from __future__ import annotations

import base64
import hashlib
import hmac
import json
import re
import secrets
import time
from datetime import datetime, timedelta, timezone
from typing import Any
from urllib.parse import quote, unquote

import httpx
from sqlalchemy.orm import Session

from .models import (
    Customer,
    MarzbanPanel,
    Order,
    PasarGuardPanel,
    PaymentSetting,
    Plan,
)
from .security import decrypt, utcnow


class IntegrationError(RuntimeError):
    pass


_MARZBAN_TOKENS: dict[int, tuple[str, float]] = {}
SUPPORTED_MARZBAN_PROTOCOLS = (
    "vless",
    "vmess",
    "trojan",
    "shadowsocks",
)


def aware(value: datetime | None) -> datetime | None:
    if value is None:
        return None
    if value.tzinfo is None:
        return value.replace(tzinfo=timezone.utc)
    return value.astimezone(timezone.utc)


def parse_remote_date(value: Any) -> datetime | None:
    if not value or value == 0:
        return None
    if isinstance(value, (int, float)):
        return datetime.fromtimestamp(float(value), tz=timezone.utc)
    try:
        parsed = datetime.fromisoformat(
            str(value).replace("Z", "+00:00")
        )
        return aware(parsed)
    except Exception:
        return None


def safe_json(value: str, fallback: Any) -> Any:
    try:
        parsed = json.loads(value or "")
        return parsed
    except Exception:
        return fallback


def panel_url(panel: Any, path: str) -> str:
    return panel.base_url.rstrip("/") + path


async def panel_headers(panel: PasarGuardPanel) -> dict[str, str]:
    common = {
        "Accept": "application/json",
        "Content-Type": "application/json",
        "User-Agent": "BlueVPN-Backend/1.0.11",
    }

    if panel.auth_mode == "api_key":
        key = decrypt(panel.api_key_enc)
        if not key:
            raise IntegrationError(
                "کلید API پاسارگارد تنظیم نشده است"
            )
        common["X-Api-Key"] = key
        return common

    username = decrypt(panel.username_enc)
    password = decrypt(panel.password_enc)
    if not username or not password:
        raise IntegrationError(
            "نام کاربری یا رمز پاسارگارد تنظیم نشده است"
        )

    async with httpx.AsyncClient(
        timeout=15,
        verify=panel.verify_tls,
    ) as client:
        response = await client.post(
            panel_url(panel, "/api/admin/token"),
            data={
                "grant_type": "password",
                "username": username,
                "password": password,
            },
        )

    if response.status_code >= 400:
        raise IntegrationError(
            "ورود پاسارگارد ناموفق: "
            f"HTTP {response.status_code} {response.text[:300]}"
        )

    token = (
        response.json().get("access_token")
        or response.json().get("token")
    )
    if not token:
        raise IntegrationError("توکن پاسارگارد دریافت نشد")

    common["Authorization"] = f"Bearer {token}"
    return common


async def test_panel(
    panel: PasarGuardPanel,
) -> tuple[bool, str]:
    try:
        async with httpx.AsyncClient(
            timeout=15,
            verify=panel.verify_tls,
        ) as client:
            response = await client.get(
                panel_url(panel, "/api/users"),
                headers=await panel_headers(panel),
                params={"limit": 1, "offset": 0},
            )

        if response.status_code == 200:
            return True, "اتصال و دسترسی کاربران موفق بود"

        return (
            False,
            f"HTTP {response.status_code}: {response.text[:350]}",
        )
    except Exception as exc:
        return False, str(exc)


def pg_username(customer: Customer) -> str:
    if customer.pg_username:
        return customer.pg_username
    digest = hashlib.sha1(customer.email.encode()).hexdigest()[:9]
    return f"bv_{customer.id}_{digest}"[:32]


def marzban_username(customer: Customer) -> str:
    if customer.marzban_username:
        return customer.marzban_username
    digest = hashlib.sha1(
        ("marzban:" + customer.email).encode()
    ).hexdigest()[:9]
    return f"bv_{customer.id}_{digest}"[:32]


def plan_groups(plan: Plan) -> list[int]:
    try:
        return [
            int(item)
            for item in json.loads(plan.group_ids_json or "[]")
        ]
    except Exception:
        return []


def proxy_settings(panel: PasarGuardPanel) -> dict:
    parsed = safe_json(panel.proxy_settings_json, {"vless": {}})
    return parsed if isinstance(parsed, dict) else {"vless": {}}


async def get_pg_user(
    panel: PasarGuardPanel,
    username: str,
) -> dict | None:
    async with httpx.AsyncClient(
        timeout=20,
        verify=panel.verify_tls,
    ) as client:
        response = await client.get(
            panel_url(
                panel,
                f"/api/user/by-username/{username}",
            ),
            headers=await panel_headers(panel),
        )

    if response.status_code == 404:
        return None
    if response.status_code >= 400:
        raise IntegrationError(
            "خواندن کاربر پاسارگارد ناموفق: "
            f"HTTP {response.status_code} {response.text[:500]}"
        )
    return response.json()


async def _marzban_token(
    panel: MarzbanPanel,
    force: bool = False,
) -> str:
    cached = _MARZBAN_TOKENS.get(panel.id)
    if not force and cached and cached[1] > time.monotonic():
        return cached[0]

    username = decrypt(panel.username_enc)
    password = decrypt(panel.password_enc)
    if not username or not password:
        raise IntegrationError(
            "نام کاربری یا رمز مدیر Marzban تنظیم نشده است"
        )

    async with httpx.AsyncClient(
        timeout=20,
        verify=panel.verify_tls,
    ) as client:
        response = await client.post(
            panel_url(panel, "/api/admin/token"),
            data={
                "grant_type": "password",
                "username": username,
                "password": password,
            },
            headers={
                "Accept": "application/json",
                "User-Agent": "BlueVPN-Backend/1.0.11",
            },
        )

    if response.status_code >= 400:
        raise IntegrationError(
            "ورود مدیر Marzban ناموفق: "
            f"HTTP {response.status_code} {response.text[:500]}"
        )

    token = response.json().get("access_token")
    if not token:
        raise IntegrationError("توکن Marzban دریافت نشد")

    _MARZBAN_TOKENS[panel.id] = (
        token,
        time.monotonic() + 20 * 60 * 60,
    )
    return token


async def marzban_request(
    panel: MarzbanPanel,
    method: str,
    path: str,
    *,
    json_body: dict | None = None,
    params: dict | None = None,
) -> httpx.Response:
    async def send(force_token: bool) -> httpx.Response:
        token = await _marzban_token(panel, force=force_token)
        async with httpx.AsyncClient(
            timeout=30,
            verify=panel.verify_tls,
        ) as client:
            return await client.request(
                method,
                panel_url(panel, path),
                headers={
                    "Authorization": f"Bearer {token}",
                    "Accept": "application/json",
                    "Content-Type": "application/json",
                    "User-Agent": "BlueVPN-Backend/1.0.11",
                },
                json=json_body,
                params=params,
            )

    response = await send(False)
    if response.status_code == 401:
        _MARZBAN_TOKENS.pop(panel.id, None)
        response = await send(True)
    return response


async def discover_marzban_access(
    panel: MarzbanPanel,
) -> tuple[dict, dict]:
    response = await marzban_request(
        panel,
        "GET",
        "/api/inbounds",
    )
    if response.status_code >= 400:
        raise IntegrationError(
            "دریافت ورودی‌های Marzban ناموفق: "
            f"HTTP {response.status_code} {response.text[:500]}"
        )

    raw = response.json()
    if not isinstance(raw, dict):
        raise IntegrationError(
            "پاسخ ورودی‌های Marzban قابل تشخیص نیست"
        )

    proxies: dict[str, dict] = {}
    inbounds: dict[str, list[str]] = {}

    for protocol in SUPPORTED_MARZBAN_PROTOCOLS:
        items = raw.get(protocol) or []
        if not isinstance(items, list) or not items:
            continue

        tags: list[str] = []
        for item in items:
            if isinstance(item, str):
                tag = item
            elif isinstance(item, dict):
                tag = str(
                    item.get("tag")
                    or item.get("remark")
                    or item.get("name")
                    or ""
                )
            else:
                tag = ""

            if tag and tag not in tags:
                tags.append(tag)

        if tags:
            proxies[protocol] = {}
            inbounds[protocol] = tags

    if not proxies:
        raise IntegrationError(
            "هیچ ورودی VLESS/VMess/Trojan/Shadowsocks "
            "برای این مدیر Marzban در دسترس نیست"
        )

    return proxies, inbounds


def configured_marzban_access(
    panel: MarzbanPanel,
) -> tuple[dict, dict] | None:
    proxies = safe_json(panel.proxies_json, {})
    inbounds = safe_json(panel.inbounds_json, {})
    if (
        isinstance(proxies, dict)
        and isinstance(inbounds, dict)
        and proxies
        and inbounds
    ):
        return proxies, inbounds
    return None


async def marzban_access(
    panel: MarzbanPanel,
) -> tuple[dict, dict]:
    configured = configured_marzban_access(panel)
    if configured:
        return configured
    return await discover_marzban_access(panel)


async def test_marzban_panel(
    panel: MarzbanPanel,
) -> tuple[bool, str, dict, dict]:
    try:
        current = await marzban_request(
            panel,
            "GET",
            "/api/admin",
        )
        if current.status_code >= 400:
            return (
                False,
                f"HTTP {current.status_code}: {current.text[:350]}",
                {},
                {},
            )

        proxies, inbounds = await discover_marzban_access(panel)
        admin_name = current.json().get("username", "admin")
        count = sum(len(items) for items in inbounds.values())

        return (
            True,
            f"اتصال مدیر {admin_name} موفق؛ "
            f"{count} ورودی از {len(proxies)} پروتکل شناسایی شد",
            proxies,
            inbounds,
        )
    except Exception as exc:
        return False, str(exc), {}, {}


async def get_marzban_user(
    panel: MarzbanPanel,
    username: str,
) -> dict | None:
    response = await marzban_request(
        panel,
        "GET",
        f"/api/user/{username}",
    )
    if response.status_code == 404:
        return None
    if response.status_code >= 400:
        raise IntegrationError(
            "خواندن کاربر Marzban ناموفق: "
            f"HTTP {response.status_code} {response.text[:500]}"
        )
    return response.json()


def order_metadata(order: Order) -> dict:
    parsed = safe_json(order.gateway_json, {})
    return parsed if isinstance(parsed, dict) else {}


def save_order_metadata(
    db: Session,
    order: Order,
    metadata: dict,
) -> None:
    order.gateway_json = json.dumps(
        metadata,
        ensure_ascii=False,
    )
    db.commit()


def activation_target(
    db: Session,
    order: Order,
    plan: Plan,
    existing_dates: list[datetime | None],
) -> datetime | None:
    metadata = order_metadata(order)
    saved = parse_remote_date(
        metadata.get("_bluevpn_target_expire")
    )

    if "_bluevpn_target_expire" in metadata:
        return saved

    now = utcnow()
    valid_dates = [
        aware(item)
        for item in existing_dates
        if item and aware(item) > now
    ]
    start = max(valid_dates) if valid_dates else now
    target = (
        None
        if plan.duration_days == 0
        else start + timedelta(days=plan.duration_days)
    )

    metadata["_bluevpn_target_expire"] = (
        0 if target is None else target.isoformat()
    )
    metadata["_bluevpn_plan_data_limit_gb"] = plan.data_limit_gb
    metadata["_bluevpn_plan_device_limit"] = plan.device_limit
    save_order_metadata(db, order, metadata)
    return target


def quota_limits(
    plan: Plan,
    secondary_enabled: bool,
) -> tuple[int, int]:
    total = (
        0
        if plan.data_limit_gb == 0
        else int(plan.data_limit_gb) * 1024 * 1024 * 1024
    )

    if not secondary_enabled:
        return total, 0

    if plan.marzban_quota_mode == "split" and total > 0:
        primary = (total + 1) // 2
        secondary = total - primary
        return primary, secondary

    return total, total


def ensure_subscription_identity(
    customer: Customer,
    public_base_url: str,
) -> None:
    if not customer.subscription_token:
        customer.subscription_token = secrets.token_urlsafe(32)

    # Migrate the old direct PasarGuard link before replacing it.
    if (
        customer.subscription_url
        and "/sub/" not in customer.subscription_url
        and not customer.pasarguard_subscription_url
    ):
        customer.pasarguard_subscription_url = (
            customer.subscription_url
        )

    customer.subscription_url = (
        public_base_url.rstrip("/")
        + "/sub/"
        + customer.subscription_token
    )


def aggregate_customer(
    customer: Customer,
    plan: Plan | None,
    *,
    public_base_url: str,
    pg_data: dict | None = None,
    mz_data: dict | None = None,
    pg_error: str = "",
    mz_error: str = "",
) -> Customer:
    statuses: list[str] = []
    expires: list[datetime] = []
    limits: list[int] = []
    usages: list[int] = []

    if pg_data:
        customer.pg_user_id = pg_data.get("id")
        customer.pasarguard_subscription_url = str(
            pg_data.get("subscription_url")
            or customer.pasarguard_subscription_url
            or ""
        )
        pg_status = str(pg_data.get("status") or "inactive")
        statuses.append(pg_status)

        pg_expire = parse_remote_date(pg_data.get("expire"))
        if pg_expire:
            expires.append(pg_expire)

        limits.append(int(pg_data.get("data_limit") or 0))
        usages.append(
            int(
                pg_data.get("used_traffic")
                or pg_data.get("used_traffic_bytes")
                or 0
            )
        )

    if mz_data:
        customer.marzban_user_id = mz_data.get("id")
        customer.marzban_subscription_url = str(
            mz_data.get("subscription_url")
            or customer.marzban_subscription_url
            or ""
        )
        customer.marzban_status = str(
            mz_data.get("status") or "inactive"
        )
        customer.marzban_expire = parse_remote_date(
            mz_data.get("expire")
        )
        customer.marzban_data_limit_bytes = int(
            mz_data.get("data_limit") or 0
        )
        customer.marzban_used_traffic_bytes = int(
            mz_data.get("used_traffic") or 0
        )

        statuses.append(customer.marzban_status)
        if customer.marzban_expire:
            expires.append(customer.marzban_expire)
        limits.append(customer.marzban_data_limit_bytes)
        usages.append(customer.marzban_used_traffic_bytes)

    customer.subscription_status = (
        "active"
        if "active" in statuses
        else statuses[0]
        if statuses
        else "inactive"
    )

    customer.subscription_expire = (
        max(expires) if expires else None
    )

    # The aggregate limit is exact for split mode. In full mode it represents
    # the real combined capacity available across both independent panels.
    customer.data_limit_bytes = sum(limits)
    customer.used_traffic_bytes = sum(usages)

    if plan:
        customer.plan_id = plan.id
        customer.device_limit = (
            1 if plan.device_limit <= 1 else 2
        )

    customer.marzban_last_error = mz_error[:1000]
    errors = [item for item in (pg_error, mz_error) if item]
    customer.last_sync_error = (
        ""
        if not errors
        else "برخی مسیرهای پشتیبان در حال همگام‌سازی هستند"
    )

    ensure_subscription_identity(customer, public_base_url)
    customer.last_sync_at = utcnow()
    return customer


async def provision_pasarguard(
    panel: PasarGuardPanel,
    username: str,
    *,
    target_expire: datetime | None,
    data_limit: int,
    device_limit: int,
    groups: list[int],
    note: str,
    remote: dict | None,
) -> dict:
    payload = {
        "status": "active",
        "expire": (
            0
            if target_expire is None
            else target_expire.isoformat()
        ),
        "data_limit": data_limit,
        "data_limit_reset_strategy": "no_reset",
        "group_ids": groups,
        "hwid_limit": 1 if device_limit <= 1 else 2,
        "note": note,
    }

    if remote is None:
        payload.update(
            {
                "username": username,
                "proxy_settings": proxy_settings(panel),
            }
        )
        method = "POST"
        url = panel_url(panel, "/api/user")
    else:
        method = "PUT"
        url = panel_url(
            panel,
            f"/api/user/by-username/{username}",
        )

    async with httpx.AsyncClient(
        timeout=30,
        verify=panel.verify_tls,
    ) as client:
        response = await client.request(
            method,
            url,
            headers=await panel_headers(panel),
            json=payload,
        )

    if response.status_code >= 400:
        raise IntegrationError(
            "فعال‌سازی پاسارگارد ناموفق: "
            f"HTTP {response.status_code} {response.text[:800]}"
        )

    return response.json()


async def provision_marzban(
    panel: MarzbanPanel,
    username: str,
    *,
    target_expire: datetime | None,
    data_limit: int,
    note: str,
    remote: dict | None,
) -> dict:
    proxies, inbounds = await marzban_access(panel)

    payload = {
        "status": "active",
        "expire": (
            0
            if target_expire is None
            else int(target_expire.timestamp())
        ),
        "data_limit": data_limit,
        "data_limit_reset_strategy": "no_reset",
        "proxies": proxies,
        "inbounds": inbounds,
        "note": note[:500],
    }

    if remote is None:
        payload["username"] = username
        method = "POST"
        path = "/api/user"
    else:
        method = "PUT"
        path = f"/api/user/{username}"

    response = await marzban_request(
        panel,
        method,
        path,
        json_body=payload,
    )

    if response.status_code >= 400:
        raise IntegrationError(
            "فعال‌سازی Marzban ناموفق: "
            f"HTTP {response.status_code} {response.text[:800]}"
        )

    return response.json()


async def provision(
    db: Session,
    customer: Customer,
    plan: Plan,
    order: Order,
    public_base_url: str = "",
) -> Customer:
    primary = db.get(PasarGuardPanel, plan.panel_id)
    if not primary or not primary.active:
        raise IntegrationError(
            "پنل اصلی PasarGuard این پلن فعال نیست"
        )

    secondary = (
        db.get(MarzbanPanel, plan.marzban_panel_id)
        if plan.marzban_panel_id
        else None
    )
    if secondary and not secondary.active:
        raise IntegrationError(
            "پنل دوم Marzban این پلن غیرفعال است"
        )

    pg_name = pg_username(customer)
    mz_name = marzban_username(customer)

    pg_remote = await get_pg_user(primary, pg_name)
    mz_remote = (
        await get_marzban_user(secondary, mz_name)
        if secondary
        else None
    )

    target_expire = activation_target(
        db,
        order,
        plan,
        [
            parse_remote_date(
                pg_remote.get("expire") if pg_remote else None
            ),
            parse_remote_date(
                mz_remote.get("expire") if mz_remote else None
            ),
            customer.subscription_expire,
        ],
    )

    pg_limit, mz_limit = quota_limits(
        plan,
        secondary_enabled=secondary is not None,
    )

    note = f"BlueVPN {customer.email}; {order.order_code}"
    pg_data = None
    mz_data = None
    pg_error = ""
    mz_error = ""

    try:
        pg_data = await provision_pasarguard(
            primary,
            pg_name,
            target_expire=target_expire,
            data_limit=pg_limit,
            device_limit=plan.device_limit,
            groups=plan_groups(plan),
            note=note,
            remote=pg_remote,
        )
        customer.panel_id = primary.id
        customer.pg_username = pg_name
    except Exception as exc:
        pg_error = str(exc)

    if secondary:
        try:
            mz_data = await provision_marzban(
                secondary,
                mz_name,
                target_expire=target_expire,
                data_limit=mz_limit,
                note=note,
                remote=mz_remote,
            )
            customer.marzban_panel_id = secondary.id
            customer.marzban_username = mz_name
        except Exception as exc:
            mz_error = str(exc)
    else:
        customer.marzban_panel_id = None
        customer.marzban_username = ""
        customer.marzban_subscription_url = ""
        customer.marzban_status = "inactive"
        customer.marzban_last_error = ""

    aggregate_customer(
        customer,
        plan,
        public_base_url=public_base_url,
        pg_data=pg_data or pg_remote,
        mz_data=mz_data or mz_remote,
        pg_error=pg_error,
        mz_error=mz_error,
    )

    customer.subscription_expire = target_expire
    db.add(customer)
    db.commit()

    required_errors = []
    if pg_error:
        required_errors.append(pg_error)
    if secondary and mz_error:
        required_errors.append(mz_error)

    if required_errors:
        order.status = "partial_needs_sync"
        order.activation_error = " | ".join(required_errors)[:2000]
        db.commit()
        raise IntegrationError(order.activation_error)

    order.status = "activated"
    order.activation_error = ""
    order.activated_at = utcnow()
    db.commit()
    return customer


async def sync_customer(
    db: Session,
    customer: Customer,
    public_base_url: str = "",
) -> Customer:
    plan = db.get(Plan, customer.plan_id) if customer.plan_id else None

    # Preserve the old direct link the first time the new version sees it.
    ensure_subscription_identity(customer, public_base_url)

    pg_data = None
    mz_data = None
    pg_error = ""
    mz_error = ""

    if customer.panel_id and customer.pg_username:
        panel = db.get(PasarGuardPanel, customer.panel_id)
        if panel:
            try:
                pg_data = await get_pg_user(
                    panel,
                    customer.pg_username,
                )
                if not pg_data:
                    pg_error = "کاربر در PasarGuard پیدا نشد"
            except Exception as exc:
                pg_error = str(exc)
        else:
            pg_error = "پنل PasarGuard حذف شده است"

    if customer.marzban_panel_id and customer.marzban_username:
        panel = db.get(MarzbanPanel, customer.marzban_panel_id)
        if panel:
            try:
                mz_data = await get_marzban_user(
                    panel,
                    customer.marzban_username,
                )
                if not mz_data:
                    mz_error = "کاربر در Marzban پیدا نشد"
            except Exception as exc:
                mz_error = str(exc)
        else:
            mz_error = "پنل Marzban حذف شده است"

    aggregate_customer(
        customer,
        plan,
        public_base_url=public_base_url,
        pg_data=pg_data,
        mz_data=mz_data,
        pg_error=pg_error,
        mz_error=mz_error,
    )

    db.add(customer)
    db.commit()
    return customer


def _decode_base64_text(value: str) -> str | None:
    compact = re.sub(r"\s+", "", value)
    if not compact:
        return None

    try:
        padding = "=" * (-len(compact) % 4)
        decoded = base64.urlsafe_b64decode(
            compact + padding
        ).decode("utf-8")
    except Exception:
        return None

    if "://" not in decoded:
        return None
    return decoded


def subscription_lines(raw: str) -> list[str]:
    text = raw.strip()
    if not text:
        return []

    if "://" not in text:
        decoded = _decode_base64_text(text)
        if decoded:
            text = decoded

    # Some custom APIs return JSON arrays or objects.
    if text.startswith("[") or text.startswith("{"):
        try:
            parsed = json.loads(text)
            if isinstance(parsed, list):
                text = "\n".join(str(item) for item in parsed)
            elif isinstance(parsed, dict):
                items = (
                    parsed.get("links")
                    or parsed.get("configs")
                    or parsed.get("servers")
                    or []
                )
                if isinstance(items, list):
                    text = "\n".join(str(item) for item in items)
        except Exception:
            pass

    allowed = (
        "vless://",
        "vmess://",
        "trojan://",
        "ss://",
        "socks://",
        "wireguard://",
        "hysteria2://",
        "hy2://",
        "tuic://",
    )

    result = []
    for line in text.replace("\r", "\n").split("\n"):
        line = line.strip()
        if line.lower().startswith(allowed):
            result.append(line)
    return result


def _clean_label(
    raw: str,
    hidden_names: list[str],
    index: int,
) -> str:
    label = unquote(raw).strip()

    for value in hidden_names:
        if value:
            label = re.sub(
                re.escape(value),
                "",
                label,
                flags=re.IGNORECASE,
            )

    label = re.sub(
        r"\b(marzban|pasarguard|panel|پنل)\b",
        "",
        label,
        flags=re.IGNORECASE,
    )
    label = re.sub(r"\s+", " ", label).strip(" -_|•")

    if not label:
        label = f"سرور {index}"

    return f"BlueVPN • {label[:48]}"


def _rename_vmess(
    line: str,
    label: str,
) -> str:
    payload = line[len("vmess://"):]
    try:
        padding = "=" * (-len(payload) % 4)
        data = json.loads(
            base64.urlsafe_b64decode(
                payload + padding
            ).decode("utf-8")
        )
        data["ps"] = label
        encoded = base64.b64encode(
            json.dumps(
                data,
                ensure_ascii=False,
                separators=(",", ":"),
            ).encode("utf-8")
        ).decode("ascii")
        return "vmess://" + encoded
    except Exception:
        return line


def neutralize_line(
    line: str,
    index: int,
    hidden_names: list[str],
) -> str:
    original_label = ""
    if "#" in line:
        original_label = line.rsplit("#", 1)[1]

    label = _clean_label(
        original_label,
        hidden_names,
        index,
    )

    if line.lower().startswith("vmess://"):
        return _rename_vmess(line, label)

    base = line.split("#", 1)[0]
    return base + "#" + quote(label, safe="")


def dedupe_key(line: str) -> str:
    if line.lower().startswith("vmess://"):
        try:
            payload = line[len("vmess://"):]
            padding = "=" * (-len(payload) % 4)
            data = json.loads(
                base64.urlsafe_b64decode(
                    payload + padding
                ).decode("utf-8")
            )
            data.pop("ps", None)
            return json.dumps(
                data,
                sort_keys=True,
                separators=(",", ":"),
            )
        except Exception:
            return line
    return line.split("#", 1)[0]


async def fetch_subscription_source(
    url: str,
    *,
    verify_tls: bool,
) -> list[str]:
    if not url.startswith(("http://", "https://")):
        return []

    async with httpx.AsyncClient(
        timeout=25,
        verify=verify_tls,
        follow_redirects=True,
    ) as client:
        response = await client.get(
            url,
            headers={
                "Accept": "text/plain,*/*",
                "User-Agent": "v2rayNG/2.2.6 BlueVPN/1.0.11",
            },
        )

    if response.status_code >= 400:
        raise IntegrationError(
            f"دریافت منبع اشتراک ناموفق: HTTP {response.status_code}"
        )
    return subscription_lines(response.text)


async def combined_subscription(
    db: Session,
    customer: Customer,
) -> tuple[str, dict[str, str], list[str]]:
    sources: list[tuple[str, bool, str]] = []

    if customer.pasarguard_subscription_url:
        panel = (
            db.get(PasarGuardPanel, customer.panel_id)
            if customer.panel_id
            else None
        )
        sources.append(
            (
                customer.pasarguard_subscription_url,
                panel.verify_tls if panel else True,
                panel.name if panel else "PasarGuard",
            )
        )

    if customer.marzban_subscription_url:
        panel = (
            db.get(MarzbanPanel, customer.marzban_panel_id)
            if customer.marzban_panel_id
            else None
        )
        sources.append(
            (
                customer.marzban_subscription_url,
                panel.verify_tls if panel else True,
                panel.name if panel else "Marzban",
            )
        )

    merged: list[str] = []
    seen: set[str] = set()
    errors: list[str] = []
    hidden_names = [name for _, _, name in sources]

    for url, verify_tls, source_name in sources:
        try:
            lines = await fetch_subscription_source(
                url,
                verify_tls=verify_tls,
            )
        except Exception as exc:
            errors.append(f"{source_name}: {exc}")
            continue

        for line in lines:
            key = dedupe_key(line)
            if key in seen:
                continue
            seen.add(key)
            merged.append(
                neutralize_line(
                    line,
                    len(merged) + 1,
                    hidden_names,
                )
            )

    if not merged:
        raise IntegrationError(
            "هیچ کانفیگ قابل استفاده‌ای از پنل‌ها دریافت نشد"
        )

    body = base64.b64encode(
        ("\n".join(merged) + "\n").encode("utf-8")
    ).decode("ascii")

    expiry = aware(customer.subscription_expire)
    headers = {
        "Cache-Control": "no-store",
        "profile-title": "base64:"
        + base64.b64encode("BlueVPN".encode()).decode(),
        "profile-update-interval": "1",
        "subscription-userinfo": (
            f"upload=0; download={customer.used_traffic_bytes}; "
            f"total={customer.data_limit_bytes}; "
            f"expire={int(expiry.timestamp()) if expiry else 0}"
        ),
    }
    return body, headers, errors


def payment_secret(
    setting: PaymentSetting,
) -> tuple[str, str, str]:
    return (
        setting.base_url.rstrip("/"),
        decrypt(setting.api_key_enc),
        decrypt(setting.callback_secret_enc),
    )


async def create_invoice(
    setting: PaymentSetting,
    order: Order,
    callback_url: str,
) -> dict:
    base, key, _ = payment_secret(setting)
    if not setting.active or not key:
        raise IntegrationError(
            "درگاه BluePay فعال یا کامل نیست"
        )

    payload = {
        "amount_toman": order.amount_toman,
        "order_id": order.order_code,
        "description": (
            f"خرید {order.plan.title} برای {order.customer.email}"
        ),
        "fee_mode": setting.fee_mode,
        "ttl_minutes": max(
            5,
            min(1440, setting.ttl_minutes),
        ),
        "callback_url": callback_url,
    }

    async with httpx.AsyncClient(timeout=25) as client:
        response = await client.post(
            base + "/api/v1/invoices",
            headers={
                "X-API-Key": key,
                "Idempotency-Key": f"{order.order_code}-create",
                "Accept": "application/json",
                "Content-Type": "application/json",
            },
            json=payload,
        )

    if response.status_code >= 400:
        raise IntegrationError(
            "ساخت فاکتور BluePay ناموفق: "
            f"HTTP {response.status_code} {response.text[:800]}"
        )
    return response.json()


async def get_invoice(
    setting: PaymentSetting,
    payment_id: str,
) -> dict:
    base, key, _ = payment_secret(setting)
    if not key:
        raise IntegrationError("کلید BluePay تنظیم نشده است")

    async with httpx.AsyncClient(timeout=20) as client:
        response = await client.get(
            base + f"/api/v1/invoices/{payment_id}",
            headers={
                "X-API-Key": key,
                "Accept": "application/json",
            },
        )

    if response.status_code >= 400:
        raise IntegrationError(
            f"استعلام BluePay ناموفق: HTTP {response.status_code}"
        )
    return response.json()


def verify_webhook(
    raw: bytes,
    signature: str,
    secret: str,
) -> tuple[bool, dict]:
    try:
        payload = json.loads(raw)
    except Exception:
        return False, {}

    raw_expected = hmac.new(
        secret.encode(),
        raw,
        hashlib.sha256,
    ).hexdigest()

    canonical = json.dumps(
        payload,
        ensure_ascii=False,
        separators=(",", ":"),
        sort_keys=True,
    ).encode()

    canonical_expected = hmac.new(
        secret.encode(),
        canonical,
        hashlib.sha256,
    ).hexdigest()

    return (
        hmac.compare_digest(raw_expected, signature)
        or hmac.compare_digest(
            canonical_expected,
            signature,
        ),
        payload,
    )
