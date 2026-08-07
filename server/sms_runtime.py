from __future__ import annotations

import asyncio
import json
import logging
from datetime import datetime, timezone
from typing import Any

from sqlalchemy import select
from sqlalchemy.orm import Session

from .database import SessionLocal
from .models import Customer, SmsSetting
from .sms import (
    delivery_params,
    jalali_date,
    process_pending_sms,
    queue_sms_event,
)

logger = logging.getLogger("bluevpn.sms_runtime")
SMS_RUNTIME_TASK: asyncio.Task | None = None


def _aware(value: datetime | None) -> datetime | None:
    if value is None:
        return None
    if value.tzinfo is None:
        return value.replace(tzinfo=timezone.utc)
    return value.astimezone(timezone.utc)


def _reminder_days(setting: SmsSetting) -> list[int]:
    try:
        values = json.loads(setting.reminder_days_json or "[3,2,1]")
    except Exception:
        values = [3, 2, 1]
    result = []
    for value in values if isinstance(values, list) else []:
        try:
            day = int(value)
        except (TypeError, ValueError):
            continue
        if 1 <= day <= 30 and day not in result:
            result.append(day)
    return result or [3, 2, 1]


def scan_subscription_notifications(db: Session) -> dict[str, int]:
    setting = db.get(SmsSetting, 1)
    if not setting or not setting.notification_active:
        return {"scanned": 0, "queued": 0}
    now = datetime.now(timezone.utc)
    reminders = set(_reminder_days(setting))
    threshold = max(1, min(9999, int(setting.low_volume_threshold_gb or 5)))
    queued = 0
    customers = list(
        db.scalars(
            select(Customer).where(
                Customer.active.is_(True),
                Customer.phone.is_not(None),
                Customer.phone != "",
            )
        ).all()
    )
    for customer in customers:
        expiry = _aware(customer.subscription_expire)
        if expiry is not None:
            seconds = (expiry - now).total_seconds()
            days_left = int((seconds + 86399) // 86400) if seconds > 0 else 0
            expiry_seed = expiry.strftime("%Y%m%d%H%M")
            if days_left in reminders:
                queued += int(
                    queue_sms_event(
                        db,
                        "subscription_reminder",
                        customer.phone or "",
                        delivery_params(days_left=days_left),
                        customer_id=customer.id,
                        dedupe_seed=f"expiry:{customer.id}:{expiry_seed}:day:{days_left}",
                    )
                    is not None
                )
            if seconds <= 0:
                queued += int(
                    queue_sms_event(
                        db,
                        "subscription_expired",
                        customer.phone or "",
                        {},
                        customer_id=customer.id,
                        dedupe_seed=f"expired:{customer.id}:{expiry_seed}",
                    )
                    is not None
                )

        limit_bytes = int(customer.data_limit_bytes or 0)
        used_bytes = int(customer.used_traffic_bytes or 0)
        if limit_bytes > 0:
            remaining_bytes = max(0, limit_bytes - used_bytes)
            remaining_gb = remaining_bytes // (1024 ** 3)
            cycle_seed = (
                _aware(customer.subscription_expire).strftime("%Y%m%d%H%M")
                if _aware(customer.subscription_expire)
                else str(customer.plan_id or 0)
            )
            if remaining_bytes <= 0:
                queued += int(
                    queue_sms_event(
                        db,
                        "volume_expired",
                        customer.phone or "",
                        {},
                        customer_id=customer.id,
                        dedupe_seed=f"volume-expired:{customer.id}:{cycle_seed}",
                    )
                    is not None
                )
            elif remaining_gb <= threshold:
                queued += int(
                    queue_sms_event(
                        db,
                        "low_remaining_volume",
                        customer.phone or "",
                        delivery_params(remaining_volume=max(1, remaining_gb)),
                        customer_id=customer.id,
                        dedupe_seed=f"low-volume:{customer.id}:{cycle_seed}:{remaining_gb}",
                    )
                    is not None
                )
    return {"scanned": len(customers), "queued": queued}


def queue_broadcast(
    db: Session,
    event_key: str,
    params: dict[str, Any],
    *,
    only_active: bool = True,
    dedupe_seed: str,
) -> int:
    query = select(Customer).where(Customer.phone.is_not(None), Customer.phone != "")
    if only_active:
        query = query.where(Customer.active.is_(True))
    queued = 0
    for customer in db.scalars(query).all():
        queued += int(
            queue_sms_event(
                db,
                event_key,
                customer.phone or "",
                params,
                customer_id=customer.id,
                dedupe_seed=f"broadcast:{dedupe_seed}:{customer.id}",
            )
            is not None
        )
    return queued


async def _runtime_loop() -> None:
    ticks = 0
    while True:
        await asyncio.sleep(10)
        db = SessionLocal()
        try:
            await process_pending_sms(db, 25)
            ticks += 1
            if ticks % 360 == 1:  # approximately hourly
                result = scan_subscription_notifications(db)
                if result["queued"]:
                    logger.info("Queued %s subscription SMS notifications", result["queued"])
        except asyncio.CancelledError:
            raise
        except Exception:
            db.rollback()
            logger.exception("SMS notification runtime failed")
        finally:
            db.close()


def start_sms_runtime() -> asyncio.Task:
    global SMS_RUNTIME_TASK
    if SMS_RUNTIME_TASK is None or SMS_RUNTIME_TASK.done():
        SMS_RUNTIME_TASK = asyncio.create_task(_runtime_loop(), name="bluepanel-sms-runtime")
    return SMS_RUNTIME_TASK


async def stop_sms_runtime() -> None:
    global SMS_RUNTIME_TASK
    task = SMS_RUNTIME_TASK
    SMS_RUNTIME_TASK = None
    if task is None:
        return
    task.cancel()
    try:
        await task
    except asyncio.CancelledError:
        pass
