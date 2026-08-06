from __future__ import annotations

import re
from typing import Any

import httpx

from .models import SmsSetting
from .security import decrypt


class SmsError(RuntimeError):
    pass


_PERSIAN_DIGITS = str.maketrans("۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩", "01234567890123456789")


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
        and setting.from_number.strip()
        and setting.pattern_code.strip()
        and setting.parameter_name.strip()
    )


def _error_message(response: httpx.Response) -> str:
    try:
        payload = response.json()
    except Exception:
        return response.text[:500] or f"HTTP {response.status_code}"
    meta = payload.get("meta") if isinstance(payload, dict) else None
    if isinstance(meta, dict):
        message = str(meta.get("message") or "").strip()
        errors = meta.get("errors")
        if message and errors:
            return f"{message}: {errors}"
        if message:
            return message
    if isinstance(payload, dict):
        return str(payload.get("message") or payload)[:500]
    return str(payload)[:500]


async def send_pattern_otp(
    setting: SmsSetting,
    phone: str,
    code: str,
) -> dict[str, Any]:
    if not sms_setting_ready(setting):
        raise SmsError("تنظیمات فراز اس‌ام‌اس کامل یا فعال نیست")

    recipient = normalize_iran_phone(phone)
    sender = str(setting.from_number or "").strip()
    if sender.startswith("00"):
        sender = "+" + sender[2:]
    elif sender.startswith("98"):
        sender = "+" + sender
    elif sender.startswith("0") and sender[1:].isdigit():
        sender = "+98" + sender[1:]

    payload = {
        "sending_type": "pattern",
        "from_number": sender,
        "code": setting.pattern_code.strip(),
        "recipients": [recipient],
        "params": {
            setting.parameter_name.strip(): str(code),
        },
    }
    endpoint = setting.base_url.rstrip("/") + "/api/send"
    headers = {
        "Authorization": decrypt(setting.api_key_enc).strip(),
        "Content-Type": "application/json",
        "Accept": "application/json",
        "User-Agent": "BlueVPN-SMS/1",
    }

    try:
        async with httpx.AsyncClient(
            timeout=httpx.Timeout(15.0, connect=8.0),
            verify=bool(setting.verify_tls),
        ) as client:
            response = await client.post(endpoint, json=payload, headers=headers)
    except httpx.HTTPError as exc:
        raise SmsError(f"ارتباط با فراز اس‌ام‌اس ناموفق بود: {exc}") from exc

    if not 200 <= response.status_code < 300:
        raise SmsError(
            f"فراز اس‌ام‌اس HTTP {response.status_code}: {_error_message(response)}"
        )

    try:
        data = response.json()
    except Exception as exc:
        raise SmsError("پاسخ فراز اس‌ام‌اس JSON معتبر نبود") from exc

    meta = data.get("meta") if isinstance(data, dict) else None
    if isinstance(meta, dict) and meta.get("status") is False:
        raise SmsError(_error_message(response))
    return data if isinstance(data, dict) else {"data": data}
