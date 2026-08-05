from __future__ import annotations

import json
import math
import time
from datetime import datetime, timedelta, timezone
from typing import Any
from urllib.parse import urljoin

import httpx

from .models import GuardCorePanel
from .security import decrypt


class GuardCoreError(RuntimeError):
    pass


_GUARDCORE_TOKENS: dict[int, tuple[str, float]] = {}
_GB = 1024 * 1024 * 1024


def aware(value: datetime | None) -> datetime | None:
    if value is None:
        return None
    if value.tzinfo is None:
        return value.replace(tzinfo=timezone.utc)
    return value.astimezone(timezone.utc)


def parse_datetime(value: Any) -> datetime | None:
    if value in (None, "", 0, "0"):
        return None
    if isinstance(value, (int, float)):
        try:
            return datetime.fromtimestamp(float(value), tz=timezone.utc)
        except Exception:
            return None
    try:
        return aware(
            datetime.fromisoformat(str(value).replace("Z", "+00:00"))
        )
    except Exception:
        return None


def service_ids_from_json(value: str) -> list[int]:
    try:
        parsed = json.loads(value or "[]")
    except Exception:
        return []
    if not isinstance(parsed, list):
        return []
    result: list[int] = []
    for item in parsed:
        try:
            number = int(item)
        except Exception:
            continue
        if number > 0 and number not in result:
            result.append(number)
    return result


def normalize_usage(panel: GuardCorePanel, value: Any) -> int:
    try:
        amount = float(value or 0)
    except Exception:
        return 0
    if panel.usage_unit == "gb":
        amount *= _GB
    return max(0, int(amount))


def encode_usage(panel: GuardCorePanel, value_bytes: int) -> int:
    value_bytes = max(0, int(value_bytes or 0))
    if panel.usage_unit == "gb":
        if value_bytes == 0:
            return 0
        return max(1, int(math.ceil(value_bytes / _GB)))
    return value_bytes


def parse_expire(panel: GuardCorePanel, data: dict[str, Any]) -> datetime | None:
    for key in ("expire", "expires_at", "expiration", "expiry"):
        parsed = parse_datetime(data.get(key))
        if parsed:
            return parsed

    try:
        value = int(data.get("limit_expire") or 0)
    except Exception:
        return None
    if value <= 0:
        return None

    if panel.expire_mode == "timestamp":
        return parse_datetime(value)

    base = (
        parse_datetime(data.get("created_at"))
        or parse_datetime(data.get("updated_at"))
        or datetime.now(timezone.utc)
    )
    if panel.expire_mode == "seconds":
        return base + timedelta(seconds=value)
    return base + timedelta(days=value)


def encode_expire(
    panel: GuardCorePanel,
    target: datetime | None,
) -> int:
    if target is None:
        return 0
    target = aware(target) or target
    now = datetime.now(timezone.utc)
    remaining = max(0.0, (target - now).total_seconds())
    if panel.expire_mode == "timestamp":
        return int(target.timestamp())
    if panel.expire_mode == "seconds":
        return max(1, int(math.ceil(remaining)))
    return max(1, int(math.ceil(remaining / 86400)))


def normalized_subscription(
    panel: GuardCorePanel,
    data: dict[str, Any],
) -> dict[str, Any]:
    enabled = bool(data.get("enabled", True))
    activated = bool(data.get("activated", True))
    stopped = any(
        bool(data.get(key, False))
        for key in ("reached", "limited", "expired")
    )
    status = "active" if enabled and activated and not stopped else "inactive"
    if bool(data.get("expired", False)):
        status = "expired"
    elif bool(data.get("limited", False)):
        status = "limited"
    elif not enabled:
        status = "disabled"
    elif not activated:
        status = "pending"

    raw_link = str(data.get("link") or "")
    subscription_url = (
        urljoin(panel.base_url.rstrip("/") + "/", raw_link)
        if raw_link
        else ""
    )

    return {
        "id": data.get("id"),
        "username": str(data.get("username") or ""),
        "subscription_url": subscription_url,
        "status": status,
        "expire": parse_expire(panel, data),
        "data_limit": normalize_usage(panel, data.get("limit_usage")),
        "used_traffic": normalize_usage(
            panel,
            data.get("current_usage")
            if data.get("current_usage") is not None
            else data.get("total_usage"),
        ),
        "raw": data,
    }


async def _token(panel: GuardCorePanel, force: bool = False) -> str:
    cached = _GUARDCORE_TOKENS.get(panel.id)
    if not force and cached and cached[1] > time.monotonic():
        return cached[0]

    username = decrypt(panel.username_enc)
    password = decrypt(panel.password_enc)
    if not username or not password:
        raise GuardCoreError("نام کاربری یا رمز GuardCore تنظیم نشده است")

    async with httpx.AsyncClient(
        timeout=20,
        verify=panel.verify_tls,
        follow_redirects=True,
    ) as client:
        response = await client.post(
            panel.base_url.rstrip("/") + "/api/admins/token",
            data={
                "grant_type": "password",
                "username": username,
                "password": password,
            },
            headers={"Accept": "application/json"},
        )
    if response.status_code >= 400:
        raise GuardCoreError(
            "ورود GuardCore ناموفق: "
            f"HTTP {response.status_code} {response.text[:500]}"
        )
    token = str(response.json().get("access_token") or "")
    if not token:
        raise GuardCoreError("توکن GuardCore دریافت نشد")
    _GUARDCORE_TOKENS[panel.id] = (token, time.monotonic() + 300)
    return token


async def headers(panel: GuardCorePanel, force: bool = False) -> dict[str, str]:
    result = {
        "Accept": "application/json",
        "Content-Type": "application/json",
        "User-Agent": "BlueVPN-GuardCore/2.2.2",
    }
    if panel.auth_mode == "api_key":
        api_key = decrypt(panel.api_key_enc)
        if not api_key:
            raise GuardCoreError("کلید API GuardCore تنظیم نشده است")
        result["X-API-Key"] = api_key
    else:
        result["Authorization"] = f"Bearer {await _token(panel, force)}"
    return result


async def request(
    panel: GuardCorePanel,
    method: str,
    path: str,
    *,
    json_body: Any = None,
    params: dict[str, Any] | None = None,
) -> httpx.Response:
    url = panel.base_url.rstrip("/") + path
    async with httpx.AsyncClient(
        timeout=30,
        verify=panel.verify_tls,
        follow_redirects=True,
    ) as client:
        response = await client.request(
            method,
            url,
            headers=await headers(panel),
            json=json_body,
            params=params,
        )
    if response.status_code == 401 and panel.auth_mode != "api_key":
        async with httpx.AsyncClient(
            timeout=30,
            verify=panel.verify_tls,
            follow_redirects=True,
        ) as client:
            response = await client.request(
                method,
                url,
                headers=await headers(panel, force=True),
                json=json_body,
                params=params,
            )
    return response


async def test_guardcore_panel(
    panel: GuardCorePanel,
) -> tuple[bool, str, list[dict[str, Any]]]:
    try:
        admin_response = await request(panel, "GET", "/api/admins/current")
        if admin_response.status_code >= 400:
            return False, (
                f"HTTP {admin_response.status_code}: "
                f"{admin_response.text[:350]}"
            ), []
        services_response = await request(panel, "GET", "/api/services")
        if services_response.status_code >= 400:
            return False, (
                "ورود موفق بود اما دریافت Serviceها ناموفق شد: "
                f"HTTP {services_response.status_code} "
                f"{services_response.text[:350]}"
            ), []
        services = services_response.json()
        if not isinstance(services, list):
            services = []
        admin = admin_response.json()
        username = str(admin.get("username") or "admin")
        return True, (
            f"اتصال مدیر {username} موفق؛ "
            f"{len(services)} سرویس دریافت شد"
        ), services
    except Exception as exc:
        return False, str(exc), []


async def get_subscription(
    panel: GuardCorePanel,
    username: str,
) -> dict[str, Any] | None:
    response = await request(
        panel,
        "GET",
        f"/api/subscriptions/{username}",
    )
    if response.status_code == 404:
        return None
    if response.status_code >= 400:
        raise GuardCoreError(
            "خواندن اشتراک GuardCore ناموفق: "
            f"HTTP {response.status_code} {response.text[:600]}"
        )
    data = response.json()
    if not isinstance(data, dict):
        raise GuardCoreError("پاسخ اشتراک GuardCore نامعتبر است")
    return normalized_subscription(panel, data)


async def provision_subscription(
    panel: GuardCorePanel,
    username: str,
    *,
    target_expire: datetime | None,
    data_limit: int,
    service_ids: list[int],
    note: str,
    remote: dict[str, Any] | None,
) -> dict[str, Any]:
    if not service_ids:
        raise GuardCoreError(
            "حداقل یک Service ID برای GuardCore لازم است"
        )

    payload = {
        "limit_usage": encode_usage(panel, data_limit),
        "limit_expire": encode_expire(panel, target_expire),
        "service_ids": service_ids,
        "note": note[:500],
    }

    if remote is None:
        create_payload = [{"username": username, **payload}]
        response = await request(
            panel,
            "POST",
            "/api/subscriptions",
            json_body=create_payload,
        )
    else:
        response = await request(
            panel,
            "PUT",
            f"/api/subscriptions/{username}",
            json_body=payload,
        )

    if response.status_code >= 400:
        raise GuardCoreError(
            "فعال‌سازی GuardCore ناموفق: "
            f"HTTP {response.status_code} {response.text[:800]}"
        )

    refreshed = await get_subscription(panel, username)
    if not refreshed:
        raise GuardCoreError(
            "اشتراک GuardCore ساخته شد اما قابل خواندن نیست"
        )

    if refreshed["status"] == "disabled":
        enable = await request(
            panel,
            "POST",
            "/api/subscriptions/enable",
            json_body={"usernames": [username]},
        )
        if enable.status_code < 400:
            refreshed = await get_subscription(panel, username) or refreshed

    return refreshed
