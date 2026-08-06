from __future__ import annotations

import hashlib
import html
import json
import os
from datetime import datetime, timedelta, timezone
from typing import Any
from urllib.parse import urlparse

import httpx
from sqlalchemy import select
from sqlalchemy.orm import Session, selectinload

from .models import Customer, GuardCorePanel, Order, Plan
from .security import utcnow
from .time_locale import format_jalali
from .version import VERSION


MANUAL_PENDING_STATES = {
    "awaiting_decision",
    "awaiting_link",
}


def aware(value: datetime | None) -> datetime | None:
    if value is None:
        return None
    if value.tzinfo is None:
        return value.replace(tzinfo=timezone.utc)
    return value.astimezone(timezone.utc)


def is_manual_guardcore(panel: GuardCorePanel | None) -> bool:
    return bool(panel and panel.auth_mode == "manual")


def _metadata(order: Order) -> dict[str, Any]:
    try:
        value = json.loads(order.gateway_json or "{}")
    except Exception:
        value = {}
    return value if isinstance(value, dict) else {}


def _save_metadata(db: Session, order: Order, data: dict[str, Any]) -> None:
    order.gateway_json = json.dumps(data, ensure_ascii=False)
    db.add(order)
    db.commit()


def manual_request(order: Order) -> dict[str, Any]:
    data = _metadata(order).get("guardcore_manual")
    return data if isinstance(data, dict) else {}


def parse_target_expire(order: Order) -> datetime | None:
    raw = _metadata(order).get("_bluevpn_target_expire")
    if raw in (None, "", 0, "0"):
        return None
    try:
        value = datetime.fromisoformat(str(raw).replace("Z", "+00:00"))
    except Exception:
        return None
    return aware(value)


def manual_snapshot(customer: Customer) -> dict[str, Any] | None:
    if not customer.guardcore_subscription_url:
        return None
    return {
        "id": customer.guardcore_subscription_id,
        "username": customer.guardcore_username,
        "subscription_url": customer.guardcore_subscription_url,
        "status": customer.guardcore_status or "active",
        "expire": aware(customer.guardcore_expire),
        "data_limit": int(customer.guardcore_data_limit_bytes or 0),
        "used_traffic": int(customer.guardcore_used_traffic_bytes or 0),
    }


def _generated_username(customer: Customer) -> str:
    if customer.guardcore_username:
        return customer.guardcore_username
    digest = hashlib.sha1(
        ("guardcore:" + customer.email).encode("utf-8")
    ).hexdigest()[:9]
    return f"bv_{customer.id}_{digest}"[:32]


def resolve_manual_panel(
    db: Session,
    plan: Plan,
) -> GuardCorePanel | None:
    # An explicitly selected automatic GuardCore must stay automatic.
    if plan.guardcore_panel_id:
        panel = db.get(GuardCorePanel, plan.guardcore_panel_id)
        if panel and panel.active and is_manual_guardcore(panel):
            return panel
        return None

    # When no GuardCore is selected on the plan, the first active manual
    # panel acts as the optional fallback and the admin receives Yes/No.
    return db.scalar(
        select(GuardCorePanel)
        .where(
            GuardCorePanel.active.is_(True),
            GuardCorePanel.auth_mode == "manual",
        )
        .order_by(GuardCorePanel.id.asc())
    )


def ensure_manual_request_for_order(
    db: Session,
    order: Order,
) -> dict[str, Any]:
    existing = manual_request(order)
    if existing:
        return existing

    customer = order.customer or db.get(Customer, order.customer_id)
    plan = order.plan or db.get(Plan, order.plan_id)
    if not customer or not plan:
        return {}

    panel = resolve_manual_panel(db, plan)
    if not panel:
        return {}

    target_expire = parse_target_expire(order)
    if target_expire is None:
        target_expire = aware(customer.subscription_expire)

    data_limit_bytes = (
        0
        if int(plan.data_limit_gb or 0) == 0
        else int(plan.data_limit_gb) * 1024 * 1024 * 1024
    )
    username = _generated_username(customer)

    customer.guardcore_panel_id = panel.id
    customer.guardcore_username = username
    customer.guardcore_expire = target_expire
    customer.guardcore_data_limit_bytes = data_limit_bytes
    customer.guardcore_used_traffic_bytes = int(
        customer.guardcore_used_traffic_bytes or 0
    )
    customer.guardcore_last_error = ""
    if customer.guardcore_subscription_url:
        customer.guardcore_status = "active"
    else:
        customer.guardcore_status = "manual_pending"
    db.add(customer)
    db.commit()

    return prepare_manual_request(
        db,
        order,
        customer,
        plan,
        panel,
        username=username,
        target_expire=target_expire,
        data_limit_bytes=data_limit_bytes,
    )


def backfill_recent_manual_requests(
    db: Session,
    *,
    hours: int = 72,
    limit: int = 100,
) -> list[Order]:
    cutoff = utcnow() - timedelta(hours=max(1, min(hours, 720)))
    orders = db.scalars(
        select(Order)
        .options(selectinload(Order.customer), selectinload(Order.plan))
        .where(
            Order.status == "activated",
            Order.created_at >= cutoff,
        )
        .order_by(Order.created_at.desc())
        .limit(max(1, min(limit, 500)))
    ).all()

    created: list[Order] = []
    for order in orders:
        if manual_request(order):
            continue
        request = ensure_manual_request_for_order(db, order)
        if request:
            created.append(order)
    return created


def prepare_manual_request(
    db: Session,
    order: Order,
    customer: Customer,
    plan: Plan,
    panel: GuardCorePanel,
    *,
    username: str,
    target_expire: datetime | None,
    data_limit_bytes: int,
) -> dict[str, Any]:
    metadata = _metadata(order)
    previous = metadata.get("guardcore_manual")
    previous = previous if isinstance(previous, dict) else {}

    # A new paid/manual order must create a new admin decision, even when the
    # customer already has a GuardCore link from an older purchase.
    request = {
        "state": previous.get("state")
        if previous.get("state") in {"awaiting_decision", "awaiting_link"}
        else "awaiting_decision",
        "panel_id": panel.id,
        "panel_name": panel.name,
        "panel_url": panel.base_url,
        "customer_id": customer.id,
        "customer_email": customer.email,
        "username": username,
        "plan_id": plan.id,
        "plan_title": plan.title,
        "duration_days": int(plan.duration_days or 0),
        "data_limit_gb": int(plan.data_limit_gb or 0),
        "device_limit": int(plan.device_limit or 1),
        "target_expire": (
            aware(target_expire).isoformat() if target_expire else None
        ),
        "target_expire_fa": (
            format_jalali(target_expire, fallback="") if target_expire else "نامحدود"
        ),
        "data_limit_bytes": int(data_limit_bytes or 0),
        "created_at": previous.get("created_at") or utcnow().isoformat(),
        "created_at_fa": previous.get("created_at_fa") or format_jalali(utcnow(), include_seconds=True),
        "notified_at": previous.get("notified_at"),
        "decision_at": previous.get("decision_at"),
        "attached_at": previous.get("attached_at"),
        "skipped_at": previous.get("skipped_at"),
        "admin_id": previous.get("admin_id"),
    }
    metadata["guardcore_manual"] = request
    _save_metadata(db, order, metadata)
    return request


def _request_message(order: Order, request: dict[str, Any]) -> str:
    duration = (
        "نامحدود"
        if int(request.get("duration_days") or 0) == 0
        else f"{int(request.get('duration_days') or 0)} روز"
    )
    volume = (
        "نامحدود"
        if int(request.get("data_limit_gb") or 0) == 0
        else f"{int(request.get('data_limit_gb') or 0)} گیگابایت"
    )
    return (
        "🟡 <b>اختصاص دستی GuardCore</b>\n\n"
        f"👤 کاربر: <code>{html.escape(str(request.get('customer_email') or ''))}</code>\n"
        f"🪪 نام پیشنهادی: <code>{html.escape(str(request.get('username') or ''))}</code>\n"
        f"📦 پلن: {html.escape(str(request.get('plan_title') or ''))}\n"
        f"⏳ زمان: <b>{duration}</b>\n"
        f"📊 حجم: <b>{volume}</b>\n"
        f"🖥 پنل: {html.escape(str(request.get('panel_name') or 'GuardCore'))}\n"
        f"🧾 سفارش: <code>{html.escape(order.order_code)}</code>\n\n"
        "می‌خواهی این کاربر روی پنل دستی GuardCore هم ساخته شود؟"
    )


def _keyboard(order_id: str, panel_url: str) -> dict[str, Any]:
    rows = [[
        {
            "text": "✅ بله، روی GuardCore بساز",
            "callback_data": f"gc:y:{order_id}",
        },
        {
            "text": "⏭ خیر، لازم نیست",
            "callback_data": f"gc:n:{order_id}",
        },
    ]]
    parsed = urlparse(panel_url or "")
    if parsed.scheme in {"http", "https"} and parsed.netloc:
        rows.append([{"text": "🌐 باز کردن پنل", "url": panel_url}])
    return {"inline_keyboard": rows}


async def notify_manual_request(db: Session, order: Order) -> bool:
    request = manual_request(order)
    if request.get("state") != "awaiting_decision":
        return False
    if request.get("notified_at"):
        return False

    token = os.getenv("BOT_TOKEN", "").strip()
    raw_admin_ids = os.getenv("ADMIN_IDS", "").strip()
    if not raw_admin_ids:
        raw_admin_ids = os.getenv("TELEGRAM_CHAT_ID", "").strip()
    chat_ids = [
        item.strip()
        for item in raw_admin_ids.split(",")
        if item.strip()
    ]
    if not token or not chat_ids:
        metadata = _metadata(order)
        current = metadata.get("guardcore_manual") or {}
        current["notify_error"] = (
            "BOT_TOKEN تنظیم نشده است"
            if not token
            else "ADMIN_IDS/TELEGRAM_CHAT_ID تنظیم نشده است"
        )
        metadata["guardcore_manual"] = current
        _save_metadata(db, order, metadata)
        return False

    sent = False
    failures: list[str] = []
    async with httpx.AsyncClient(timeout=20.0) as client:
        for chat_id in chat_ids:
            response = await client.post(
                f"https://api.telegram.org/bot{token}/sendMessage",
                json={
                    "chat_id": chat_id,
                    "text": _request_message(order, request),
                    "parse_mode": "HTML",
                    "disable_web_page_preview": True,
                    "reply_markup": _keyboard(
                        order.id,
                        str(request.get("panel_url") or ""),
                    ),
                },
            )
            if response.status_code < 400:
                sent = True
            else:
                failures.append(
                    f"{chat_id}: HTTP {response.status_code} "
                    f"{response.text[:240]}"
                )

    metadata = _metadata(order)
    current = metadata.get("guardcore_manual") or {}
    if sent:
        current["notified_at"] = utcnow().isoformat()
        current.pop("notify_error", None)
    elif failures:
        current["notify_error"] = " | ".join(failures)[:700]
    metadata["guardcore_manual"] = current
    _save_metadata(db, order, metadata)
    return sent


def _load_order(db: Session, order_id: str) -> Order:
    order = db.scalar(
        select(Order)
        .options(selectinload(Order.customer), selectinload(Order.plan))
        .where(Order.id == order_id)
    )
    if not order:
        raise ValueError("سفارش پیدا نشد")
    return order


def set_manual_decision(
    db: Session,
    order_id: str,
    *,
    use_guardcore: bool,
    admin_id: int | None = None,
) -> tuple[Order, dict[str, Any]]:
    order = _load_order(db, order_id)
    metadata = _metadata(order)
    request = metadata.get("guardcore_manual")
    if not isinstance(request, dict):
        raise ValueError("این سفارش درخواست دستی GuardCore ندارد")
    if request.get("state") == "attached":
        raise ValueError("لینک GuardCore این سفارش قبلاً ثبت شده است")

    request["state"] = "awaiting_link" if use_guardcore else "skipped"
    request["decision_at"] = utcnow().isoformat()
    request["admin_id"] = admin_id
    if not use_guardcore:
        request["skipped_at"] = utcnow().isoformat()
    metadata["guardcore_manual"] = request
    _save_metadata(db, order, metadata)
    return order, request


async def validate_subscription_url(url: str) -> dict[str, Any]:
    candidate = url.strip()
    parsed = urlparse(candidate)
    if parsed.scheme not in {"http", "https"} or not parsed.netloc:
        raise ValueError("لینک ساب باید با http یا https شروع شود")

    headers = {
        "User-Agent": f"v2rayNG/1.10 BlueVPN/{VERSION}",
        "Accept": "text/plain, application/octet-stream, */*",
    }
    async with httpx.AsyncClient(
        timeout=20.0,
        follow_redirects=True,
        headers=headers,
    ) as client:
        response = await client.get(candidate)

    if response.status_code >= 400:
        raise ValueError(
            f"لینک ساب پاسخ HTTP {response.status_code} داد"
        )

    raw = response.content or b""
    if len(raw) < 4:
        raise ValueError("پاسخ لینک ساب خالی است")

    preview = raw[:500].decode("utf-8", errors="ignore").lower()
    if "<html" in preview or "<!doctype" in preview:
        raise ValueError("این لینک صفحه وب است، نه لینک Subscription")

    text = raw.decode("utf-8", errors="ignore")
    config_count = sum(
        text.count(prefix)
        for prefix in (
            "vless://",
            "vmess://",
            "trojan://",
            "ss://",
            "hysteria2://",
            "tuic://",
        )
    )
    return {
        "url": candidate,
        "bytes": len(raw),
        "config_count": config_count,
        "content_type": response.headers.get("content-type", ""),
    }


async def attach_manual_subscription(
    db: Session,
    order_id: str,
    subscription_url: str,
    *,
    admin_id: int | None = None,
) -> dict[str, Any]:
    checked = await validate_subscription_url(subscription_url)
    order = _load_order(db, order_id)
    request = manual_request(order)
    if not request:
        raise ValueError("این سفارش درخواست دستی GuardCore ندارد")

    customer = order.customer
    plan = order.plan
    panel_id = int(request.get("panel_id") or 0)
    panel = db.get(GuardCorePanel, panel_id)
    if not is_manual_guardcore(panel):
        raise ValueError("پنل GuardCore این سفارش در حالت دستی نیست")

    target_expire = parse_target_expire(order)
    if not target_expire and request.get("target_expire"):
        try:
            target_expire = aware(
                datetime.fromisoformat(
                    str(request["target_expire"]).replace("Z", "+00:00")
                )
            )
        except Exception:
            target_expire = None

    data_limit_bytes = int(request.get("data_limit_bytes") or 0)
    if not data_limit_bytes and int(plan.data_limit_gb or 0) > 0:
        data_limit_bytes = int(plan.data_limit_gb) * 1024 * 1024 * 1024

    customer.guardcore_panel_id = panel.id
    customer.guardcore_username = str(request.get("username") or "")[:64]
    customer.guardcore_subscription_url = checked["url"]
    customer.guardcore_status = "active"
    customer.guardcore_expire = target_expire
    customer.guardcore_data_limit_bytes = data_limit_bytes
    customer.guardcore_used_traffic_bytes = 0
    customer.guardcore_last_error = ""
    customer.subscription_status = "active"
    if target_expire:
        current = aware(customer.subscription_expire)
        if not current or target_expire > current:
            customer.subscription_expire = target_expire
    if int(plan.data_limit_gb or 0) == 0:
        customer.data_limit_bytes = 0
    customer.last_sync_error = ""

    metadata = _metadata(order)
    request = metadata.get("guardcore_manual") or {}
    request.update(
        {
            "state": "attached",
            "attached_at": utcnow().isoformat(),
            "admin_id": admin_id,
            "validation_bytes": checked["bytes"],
            "validation_config_count": checked["config_count"],
        }
    )
    metadata["guardcore_manual"] = request
    order.gateway_json = json.dumps(metadata, ensure_ascii=False)
    db.add(customer)
    db.add(order)
    db.commit()

    return {
        "order_id": order.id,
        "order_code": order.order_code,
        "customer_email": customer.email,
        "username": customer.guardcore_username,
        "panel_name": panel.name,
        "subscription_url": checked["url"],
        "config_count": checked["config_count"],
        "response_bytes": checked["bytes"],
    }


def pending_manual_requests(db: Session, limit: int = 100) -> list[dict[str, Any]]:
    orders = db.scalars(
        select(Order)
        .options(selectinload(Order.customer), selectinload(Order.plan))
        .order_by(Order.created_at.desc())
        .limit(max(1, min(limit, 500)))
    ).all()
    result: list[dict[str, Any]] = []
    for order in orders:
        request = manual_request(order)
        if request.get("state") not in MANUAL_PENDING_STATES:
            continue
        result.append(
            {
                "order": order,
                "request": request,
            }
        )
    return result
