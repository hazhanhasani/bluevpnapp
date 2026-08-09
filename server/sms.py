from __future__ import annotations

import asyncio
import hashlib
import json
import logging
import re
from datetime import datetime, timedelta, timezone
from typing import Any
from urllib.parse import quote

import httpx
from sqlalchemy import select
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session

from .models import Customer, SmsDelivery, SmsSetting, SmsTemplate
from .security import decrypt
from .sms_catalog import SMS_TEMPLATE_MAP, SMS_TEMPLATE_SPECS, SmsTemplateSpec
from .time_locale import format_jalali

logger = logging.getLogger("bluevpn.sms")

IRANPAYAMAK_DEFAULT_BASE_URL = "https://api.iranpayamak.com/ws/v1"


class SmsError(RuntimeError):
    def __init__(
        self,
        message: str,
        *,
        transient: bool = False,
        provider_status: int | None = None,
    ) -> None:
        super().__init__(message)
        self.transient = transient
        self.provider_status = provider_status


_PERSIAN_DIGITS = str.maketrans("۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩", "01234567890123456789")
_RETRY_SECONDS = (60, 300, 900, 1800)
_PROVIDER_RETRY_DELAYS = (0.6, 1.5)
_TRANSIENT_PROVIDER_STATUSES = {408, 425, 429, 500, 502, 503, 504}


def utcnow() -> datetime:
    return datetime.now(timezone.utc)


def normalize_iran_phone(raw: str) -> str:
    value = str(raw or "").translate(_PERSIAN_DIGITS).strip()
    value = re.sub(r"[^0-9+]", "", value)
    if value.startswith("0098"):
        value = "+98" + value[4:]
    elif value.startswith("98"):
        value = "+" + value
    elif value.startswith("0"):
        value = "+98" + value[1:]
    elif value.startswith("9"):
        value = "+98" + value
    if not re.fullmatch(r"\+989\d{9}", value):
        raise ValueError("شماره تماس باید یک شماره موبایل معتبر ایران باشد")
    return value


def local_phone(phone: str) -> str:
    value = normalize_iran_phone(phone)
    return "0" + value[3:]


def sms_setting_ready(setting: SmsSetting | None) -> bool:
    return bool(
        setting
        and setting.active
        and decrypt(setting.api_key_enc).strip()
        and setting.pattern_code.strip()
        and _line_number(setting)
    )


def sms_notification_ready(setting: SmsSetting | None) -> bool:
    return bool(
        setting
        and setting.notification_active
        and decrypt(setting.api_key_enc).strip()
        and _line_number(setting)
    )


def seed_sms_templates(db: Session) -> int:
    existing = {row.key: row for row in db.scalars(select(SmsTemplate)).all()}
    changed = 0
    for spec in SMS_TEMPLATE_SPECS:
        row = existing.get(spec.key)
        variables = json.dumps(
            [{"name": item.name, "type": item.kind, "length": item.length} for item in spec.variables],
            ensure_ascii=False,
        )
        if row is None:
            row = SmsTemplate(
                key=spec.key,
                title=spec.title,
                category=spec.category,
                body=spec.body,
                variables_json=variables,
                enabled=spec.default_enabled,
                broadcast=spec.broadcast,
            )
            db.add(row)
            changed += 1
        else:
            # Text and variable contracts are product-owned. Pattern code and
            # enabled state remain admin-owned across upgrades.
            dirty = False
            for field, value in (
                ("title", spec.title),
                ("category", spec.category),
                ("body", spec.body),
                ("variables_json", variables),
                ("broadcast", spec.broadcast),
            ):
                if getattr(row, field) != value:
                    setattr(row, field, value)
                    dirty = True
            changed += int(dirty)
    setting = db.get(SmsSetting, 1)
    auth = existing.get("auth_otp") or db.get(SmsTemplate, "auth_otp")
    if setting and auth:
        if setting.pattern_code and not auth.pattern_code:
            auth.pattern_code = setting.pattern_code
            auth.enabled = bool(setting.active)
            changed += 1
        elif auth.pattern_code and not setting.pattern_code:
            # The patterns section is the source of truth. Keep the legacy
            # setting mirror populated for older app/server paths.
            setting.pattern_code = auth.pattern_code
            setting.parameter_name = "code"
            changed += 1
    if changed:
        db.commit()
    return changed


def _clean_provider_text(value: Any, fallback: str) -> str:
    text = str(value or "").strip()
    if not text:
        return fallback
    lowered = text.lower()
    if any(marker in lowered for marker in ("<!doctype", "<html", "<body", "cdn-cgi", "error-section__")):
        return fallback
    text = re.sub(r"<[^>]+>", " ", text)
    text = re.sub(r"\s+", " ", text).strip()
    return text[:240] or fallback


def _provider_validation_message(payload: Any) -> str:
    if not isinstance(payload, dict):
        return ""
    meta = payload.get("meta")
    errors = payload.get("errors")
    if not isinstance(errors, dict) and isinstance(meta, dict):
        errors = meta.get("errors")
    if not isinstance(errors, dict):
        return ""

    messages: list[str] = []
    if errors.get("code"):
        messages.append(
            "کد پترن در حساب این API Key معتبر نیست؛ UID دقیق پترن فعال ایران‌پیامک را با همان حروف بزرگ و کوچک ثبت کنید."
        )
    if errors.get("line_number"):
        messages.append(
            "شماره خط ارسال در حساب ایران‌پیامک معتبر نیست؛ API جدید برای خط اشتراکی هم line_number واقعی و مجاز می‌خواهد."
        )
    if errors.get("attributes"):
        messages.append("نام یا مقدار متغیرهای پترن با قرارداد ثبت‌شده در ایران‌پیامک یکسان نیست.")
    if errors.get("recipient"):
        messages.append("شماره گیرنده برای ایران‌پیامک معتبر نیست.")

    known = {"code", "line_number", "attributes", "recipient"}
    for key, value in errors.items():
        if key in known:
            continue
        if isinstance(value, list):
            raw = " ".join(str(item) for item in value)
        else:
            raw = str(value)
        clean = _clean_provider_text(raw, "")
        if clean:
            messages.append(clean)
    return " ".join(dict.fromkeys(messages))[:500]


def _error_message(response: httpx.Response) -> str:
    fallback = "پاسخ نامعتبر از سرویس پیامک دریافت شد"
    content_type = str(response.headers.get("content-type") or "").lower()
    if "text/html" in content_type:
        return fallback
    try:
        payload = response.json()
    except Exception:
        return _clean_provider_text(response.text, fallback)

    validation = _provider_validation_message(payload)
    if validation:
        return validation

    meta = payload.get("meta") if isinstance(payload, dict) else None
    if isinstance(meta, dict):
        message = _clean_provider_text(meta.get("message"), "")
        if message:
            return message
    if isinstance(payload, dict):
        return _clean_provider_text(
            payload.get("message") or payload.get("messages") or payload.get("error"),
            fallback,
        )
    return _clean_provider_text(payload, fallback)


def _line_number(setting: SmsSetting) -> str:
    """Return the exact IranPayamak line_number used by the API.

    The current IranPayamak pattern endpoint validates line_number even when
    the account uses a shared sender. Therefore the actual shared or dedicated
    line assigned to the account must be stored here.
    """
    value = str(setting.from_number or "").translate(_PERSIAN_DIGITS).strip()
    value = re.sub(r"\s+", "", value)
    if not value:
        return ""
    if not re.fullmatch(r"[+0-9A-Za-z_-]{3,32}", value):
        return ""
    return value


def _sender(setting: SmsSetting) -> str:
    # Backward-compatible alias used by older tests/extensions.
    return _line_number(setting)


def migrate_iranpayamak_settings(db: Session) -> bool:
    """Migrate an existing IPPanel/FarazSMS row without losing the API key.

    The historical shared sender +983000505 was an application-side default,
    not an account-owned IranPayamak line, so it is cleared during migration.
    Dedicated line values are preserved.
    """
    setting = db.get(SmsSetting, 1)
    if setting is None:
        return False
    changed = False
    provider = str(setting.provider or "").strip().lower()
    base_url = str(setting.base_url or "").strip().rstrip("/")
    legacy_provider = provider != "iranpayamak" or "edge.ippanel.com" in base_url.lower()
    if provider != "iranpayamak":
        setting.provider = "iranpayamak"
        changed = True
    if base_url != IRANPAYAMAK_DEFAULT_BASE_URL:
        setting.base_url = IRANPAYAMAK_DEFAULT_BASE_URL
        changed = True
    legacy_line = str(setting.from_number or "").replace(" ", "")
    if legacy_provider and legacy_line in {"+983000505", "983000505", "00983000505"}:
        setting.from_number = ""
        changed = True
    if changed:
        db.add(setting)
        db.commit()
    return changed


def _sanitize_params(spec: SmsTemplateSpec, params: dict[str, Any]) -> dict[str, str]:
    result: dict[str, str] = {}
    for item in spec.variables:
        if item.name not in params:
            raise SmsError(f"پارامتر {item.name} برای پیام «{spec.title}» ارسال نشده است")
        value = str(params.get(item.name, "")).strip()
        if item.kind == "عددی":
            value = value.translate(_PERSIAN_DIGITS)
            value = re.sub(r"[^0-9]", "", value)
            if not value:
                raise SmsError(f"پارامتر {item.name} باید عددی باشد")
        if len(value) > item.length:
            value = value[: item.length]
        result[item.name] = value
    return result


async def validate_pattern_code(setting: SmsSetting, pattern_code: str) -> dict[str, Any]:
    api_key = decrypt(setting.api_key_enc).strip()
    code = str(pattern_code or "").strip()
    if not api_key:
        raise SmsError("کلید API ایران‌پیامک ثبت نشده است")
    if not code:
        raise SmsError("کد پترن پیامک ثبت نشده است")
    if not re.fullmatch(r"[A-Za-z0-9_-]{4,160}", code):
        raise SmsError("فرمت UID پترن ایران‌پیامک صحیح نیست")

    base_url = str(setting.base_url or IRANPAYAMAK_DEFAULT_BASE_URL).strip().rstrip("/")
    if not base_url or "edge.ippanel.com" in base_url.lower():
        base_url = IRANPAYAMAK_DEFAULT_BASE_URL
    endpoint = base_url + "/patterns/" + quote(code, safe="")
    headers = {
        "Api-Key": api_key,
        "Accept": "application/json",
        "User-Agent": "BluePanel-SMS/5",
    }
    async with httpx.AsyncClient(
        timeout=httpx.Timeout(12.0, connect=7.0),
        verify=bool(setting.verify_tls),
        follow_redirects=True,
    ) as client:
        try:
            response = await client.get(endpoint, headers=headers)
        except httpx.HTTPError as exc:
            raise SmsError(
                "بررسی پترن با ایران‌پیامک موقتاً ممکن نشد؛ دوباره تلاش کنید.",
                transient=True,
            ) from exc
    if response.status_code in _TRANSIENT_PROVIDER_STATUSES:
        raise SmsError(
            "بررسی پترن با ایران‌پیامک موقتاً ممکن نشد؛ دوباره تلاش کنید.",
            transient=True,
            provider_status=response.status_code,
        )
    if response.status_code in {401, 403}:
        raise SmsError("کلید API ایران‌پیامک معتبر نیست یا دسترسی مشاهده پترن‌ها را ندارد.")
    if not 200 <= response.status_code < 300:
        raise SmsError(
            "کد پترن در حساب این API Key پیدا نشد؛ UID را از پترن فعال ایران‌پیامک دوباره کپی کنید.",
            provider_status=response.status_code,
        )
    try:
        data = response.json() if response.content else {}
    except Exception as exc:
        raise SmsError("پاسخ بررسی پترن ایران‌پیامک معتبر نبود.") from exc
    return data if isinstance(data, dict) else {"data": data}


async def send_pattern(
    setting: SmsSetting,
    phone: str,
    pattern_code: str,
    params: dict[str, str],
) -> dict[str, Any]:
    api_key = decrypt(setting.api_key_enc).strip()
    if not api_key:
        raise SmsError("کلید API ایران‌پیامک ثبت نشده است")
    if not pattern_code.strip():
        raise SmsError("کد پترن پیامک ثبت نشده است")

    payload: dict[str, Any] = {
        "code": pattern_code.strip(),
        "attributes": {str(k): str(v) for k, v in params.items()},
        "recipient": local_phone(phone),
        "number_format": "english",
    }
    line_number = _line_number(setting)
    if not line_number:
        raise SmsError(
            "شماره خط ارسال ایران‌پیامک ثبت نشده است؛ برای خط اشتراکی هم line_number واقعی حساب الزامی است."
        )
    payload["line_number"] = line_number

    base_url = str(setting.base_url or IRANPAYAMAK_DEFAULT_BASE_URL).strip().rstrip("/")
    if not base_url or "edge.ippanel.com" in base_url.lower():
        base_url = IRANPAYAMAK_DEFAULT_BASE_URL
    endpoint = base_url + "/sms/pattern"
    headers = {
        "Api-Key": api_key,
        "Content-Type": "application/json",
        "Accept": "application/json",
        "User-Agent": "BluePanel-SMS/4",
    }

    attempts = len(_PROVIDER_RETRY_DELAYS) + 1
    async with httpx.AsyncClient(
        timeout=httpx.Timeout(15.0, connect=8.0),
        verify=bool(setting.verify_tls),
        follow_redirects=True,
    ) as client:
        for attempt in range(attempts):
            try:
                response = await client.post(endpoint, json=payload, headers=headers)
            except httpx.HTTPError as exc:
                if attempt < attempts - 1:
                    await asyncio.sleep(_PROVIDER_RETRY_DELAYS[attempt])
                    continue
                logger.warning("IranPayamak transport failed after retries: %s", exc.__class__.__name__)
                raise SmsError(
                    "ارتباط با ایران‌پیامک موقتاً برقرار نشد؛ چند لحظه دیگر دوباره تلاش کنید.",
                    transient=True,
                ) from exc

            if response.status_code in _TRANSIENT_PROVIDER_STATUSES:
                if attempt < attempts - 1:
                    await asyncio.sleep(_PROVIDER_RETRY_DELAYS[attempt])
                    continue
                logger.warning(
                    "IranPayamak transient HTTP failure status=%s content_type=%s",
                    response.status_code,
                    response.headers.get("content-type", ""),
                )
                raise SmsError(
                    "سامانه ایران‌پیامک موقتاً پاسخ‌گو نیست؛ چند لحظه دیگر دوباره تلاش کنید.",
                    transient=True,
                    provider_status=response.status_code,
                )

            if not 200 <= response.status_code < 300:
                if response.status_code in {401, 403}:
                    public_message = "کلید API ایران‌پیامک معتبر نیست یا مجوز ارسال پترن ندارد."
                elif response.status_code in {400, 422}:
                    public_message = _error_message(response)
                else:
                    public_message = _error_message(response)
                raise SmsError(public_message, provider_status=response.status_code)

            if not response.content:
                return {"success": True, "status_code": response.status_code}
            try:
                data = response.json()
            except Exception as exc:
                raise SmsError("پاسخ معتبر از ایران‌پیامک دریافت نشد.") from exc

            if isinstance(data, dict):
                meta = data.get("meta")
                explicit_failure = data.get("success") is False or data.get("status") is False
                if isinstance(meta, dict) and meta.get("status") is False:
                    explicit_failure = True
                if explicit_failure:
                    raise SmsError(_error_message(response), provider_status=response.status_code)
                return data
            return {"success": True, "data": data, "status_code": response.status_code}

    raise SmsError(
        "سامانه ایران‌پیامک موقتاً پاسخ‌گو نیست؛ چند لحظه دیگر دوباره تلاش کنید.",
        transient=True,
    )


async def send_pattern_otp(setting: SmsSetting, phone: str, code: str) -> dict[str, Any]:
    if not sms_setting_ready(setting):
        raise SmsError("تنظیمات ایران‌پیامک کامل یا فعال نیست")
    return await send_pattern(
        setting,
        phone,
        setting.pattern_code,
        {setting.parameter_name.strip(): str(code)},
    )


def _dedupe(event_key: str, phone: str, params: dict[str, str], seed: str) -> str:
    raw = json.dumps([event_key, phone, params, seed], ensure_ascii=False, sort_keys=True)
    return hashlib.sha256(raw.encode("utf-8")).hexdigest()


def queue_sms_event(
    db: Session,
    event_key: str,
    phone: str,
    params: dict[str, Any] | None = None,
    *,
    customer_id: int | None = None,
    order_id: str | None = None,
    dedupe_seed: str = "",
    force: bool = False,
) -> SmsDelivery | None:
    setting = db.get(SmsSetting, 1)
    template = db.get(SmsTemplate, event_key)
    spec = SMS_TEMPLATE_MAP.get(event_key)
    if not setting or not template or not spec:
        return None
    if event_key in {"auth_otp", "phone_change_otp"}:
        globally_enabled = bool(setting.active)
    else:
        globally_enabled = bool(setting.notification_active)
    if not force and (not globally_enabled or not template.enabled or not template.pattern_code.strip()):
        return None
    clean_phone = normalize_iran_phone(phone)
    clean_params = _sanitize_params(spec, params or {})
    seed = dedupe_seed or utcnow().strftime("%Y%m%d%H%M")
    delivery = SmsDelivery(
        event_key=event_key,
        customer_id=customer_id,
        order_id=order_id,
        phone=clean_phone,
        params_json=json.dumps(clean_params, ensure_ascii=False),
        dedupe_key=_dedupe(event_key, clean_phone, clean_params, seed),
        status="pending",
        max_attempts=max(1, min(5, int(setting.retry_max_attempts or 3))),
        next_attempt_at=utcnow(),
    )
    db.add(delivery)
    try:
        db.commit()
    except IntegrityError:
        db.rollback()
        return None
    return delivery


async def process_sms_delivery(db: Session, delivery: SmsDelivery) -> bool:
    setting = db.get(SmsSetting, 1)
    template = db.get(SmsTemplate, delivery.event_key)
    spec = SMS_TEMPLATE_MAP.get(delivery.event_key)
    if not setting or not template or not spec:
        delivery.status = "skipped"
        delivery.last_error = "تنظیمات یا پترن پیام پیدا نشد"
        db.commit()
        return False
    try:
        params = json.loads(delivery.params_json or "{}")
    except Exception:
        params = {}
    delivery.attempts += 1
    delivery.status = "sending"
    db.commit()
    try:
        response = await send_pattern(
            setting,
            delivery.phone,
            template.pattern_code,
            _sanitize_params(spec, params),
        )
        delivery.status = "sent"
        delivery.sent_at = utcnow()
        delivery.response_json = json.dumps(response, ensure_ascii=False)[:8000]
        delivery.last_error = ""
        data = response.get("data") if isinstance(response, dict) else None
        if isinstance(data, dict):
            delivery.provider_message_id = str(data.get("message_id") or data.get("id") or "")[:180]
        db.commit()
        return True
    except Exception as exc:
        delivery.last_error = str(exc)[:2000]
        if delivery.attempts >= delivery.max_attempts:
            delivery.status = "failed"
            delivery.next_attempt_at = None
        else:
            delivery.status = "retry"
            delay = _RETRY_SECONDS[min(delivery.attempts - 1, len(_RETRY_SECONDS) - 1)]
            delivery.next_attempt_at = utcnow() + timedelta(seconds=delay)
        db.commit()
        logger.warning("SMS delivery failed id=%s event=%s: %s", delivery.id, delivery.event_key, exc)
        return False


async def process_pending_sms(db: Session, limit: int = 25) -> dict[str, int]:
    now = utcnow()
    rows = list(
        db.scalars(
            select(SmsDelivery)
            .where(
                SmsDelivery.status.in_(("pending", "retry")),
                SmsDelivery.next_attempt_at <= now,
            )
            .order_by(SmsDelivery.created_at.asc())
            .limit(max(1, min(100, limit)))
        ).all()
    )
    sent = failed = 0
    for row in rows:
        if await process_sms_delivery(db, row):
            sent += 1
        else:
            failed += 1
    return {"processed": len(rows), "sent": sent, "failed": failed}


def delivery_params(**values: Any) -> dict[str, str]:
    return {key: str(value) for key, value in values.items() if value is not None}


def customer_name(customer: Customer) -> str:
    if customer.phone:
        return local_phone(customer.phone)
    return (customer.email.split("@", 1)[0] if customer.email else "کاربر")[:30]


def jalali_date(value: Any) -> str:
    return format_jalali(value, include_time=False, persian_digits=False, fallback="نامحدود")


def jalali_datetime_short(value: Any) -> str:
    text = format_jalali(value, include_time=True, persian_digits=False, fallback="")
    return text.replace("، ساعت ", " ")[:16]
