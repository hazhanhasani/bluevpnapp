from __future__ import annotations

import json
import math
import re
from datetime import datetime, timedelta, timezone
from typing import Any

from sqlalchemy import desc, func, select
from sqlalchemy.orm import Session

from .models import AiConnectionEvent, AiFeedback, AiRouteAggregate, Customer


def utcnow() -> datetime:
    return datetime.now(timezone.utc)


def clean(value: Any, limit: int = 120, fallback: str = "unknown") -> str:
    text = re.sub(r"[\x00-\x1f]+", " ", str(value or "")).strip()
    return (text[:limit] or fallback)



def canonical_operator(value: Any) -> str:
    raw = clean(value, 100).lower().replace("‌", "")
    if any(x in raw for x in ("irancell", "mtn", "ایرانسل")):
        return "ایرانسل"
    if any(x in raw for x in ("hamrah", "mci", "همراه اول", "همراه‌اول")):
        return "همراه اول"
    if any(x in raw for x in ("rightel", "rightel", "رایتل")):
        return "رایتل"
    if any(x in raw for x in ("shatel", "شاتل")):
        return "شاتل موبایل"
    if any(x in raw for x in ("samantel", "saman tel", "سامانتل")):
        return "سامانتل"
    if any(x in raw for x in ("aptel", "آپتل")):
        return "آپتل"
    if any(x in raw for x in ("taliya", "تالیا")):
        return "تالیا"
    if raw in {"wi-fi", "wifi"}:
        return "Wi-Fi"
    return raw or "ناشناخته"


def clamp_int(value: Any, minimum: int, maximum: int, default: int = 0) -> int:
    try:
        return max(minimum, min(maximum, int(float(value))))
    except Exception:
        return default


def hour_bucket(value: Any = None) -> int:
    if value is not None:
        return clamp_int(value, 0, 23, utcnow().hour)
    return utcnow().hour


def route_score(aggregate: AiRouteAggregate) -> int:
    samples = max(1, int(aggregate.sample_count or 0))
    successes = int(aggregate.success_count or 0)
    success_rate = successes / samples
    avg_duration = float(aggregate.total_duration_seconds or 0) / max(1, successes)
    avg_ping = float(aggregate.total_ping_ms or 0) / max(1, int(aggregate.ping_samples or 0))
    avg_jitter = float(aggregate.total_jitter_ms or 0) / max(1, int(aggregate.jitter_samples or 0))
    avg_loss = float(aggregate.total_packet_loss_x100 or 0) / samples / 100.0

    success_component = success_rate * 46.0
    duration_component = min(1.0, avg_duration / 900.0) * 20.0
    ping_component = 20.0 if avg_ping <= 0 else max(0.0, 20.0 * (1.0 - min(avg_ping, 450.0) / 450.0))
    quality_component = max(0.0, 9.0 - min(avg_jitter / 20.0, 5.0) - min(avg_loss / 8.0, 4.0))
    confidence_component = min(5.0, math.log2(samples + 1.0))
    return int(round(max(0.0, min(100.0, success_component + duration_component + ping_component + quality_component + confidence_component))))


def submit_event(db: Session, customer: Customer, payload: dict[str, Any]) -> dict[str, Any]:
    config_key = clean(payload.get("config_key"), 80, "")
    if len(config_key) < 8:
        raise ValueError("config_key نامعتبر است")

    operator = canonical_operator(payload.get("operator"))
    network_type = clean(payload.get("network_type"), 30).lower()
    mode = clean(payload.get("mode"), 30, "balanced").lower()
    event_type = clean(payload.get("event_type"), 30, "session").lower()
    success = bool(payload.get("success", False))
    bucket = hour_bucket(payload.get("hour_bucket"))

    event = AiConnectionEvent(
        customer_id=customer.id,
        device_id=clean(payload.get("device_id"), 80, ""),
        config_key=config_key,
        location_key=clean(payload.get("location_key"), 24),
        location_title=clean(payload.get("location_title"), 100, "نامشخص"),
        operator=operator,
        network_type=network_type,
        mode=mode,
        event_type=event_type,
        success=success,
        ping_ms=clamp_int(payload.get("ping_ms"), 0, 10000),
        jitter_ms=clamp_int(payload.get("jitter_ms"), 0, 10000),
        packet_loss_x100=clamp_int(payload.get("packet_loss_x100"), 0, 10000),
        duration_seconds=clamp_int(payload.get("duration_seconds"), 0, 31_536_000),
        health_score=clamp_int(payload.get("health_score"), 0, 100),
        download_bytes=clamp_int(payload.get("download_bytes"), 0, 10**18),
        upload_bytes=clamp_int(payload.get("upload_bytes"), 0, 10**18),
        failure_reason=clean(payload.get("failure_reason"), 500, ""),
        app_version=clean(payload.get("app_version"), 40, ""),
        android_version=clean(payload.get("android_version"), 40, ""),
        device_model=clean(payload.get("device_model"), 160, ""),
        hour_bucket=bucket,
    )
    db.add(event)

    if event_type == "heartbeat":
        db.commit()
        return {
            "accepted": True,
            "live": True,
            "operator": operator,
            "network_type": network_type,
        }

    aggregate = db.scalar(
        select(AiRouteAggregate).where(
            AiRouteAggregate.config_key == config_key,
            AiRouteAggregate.operator == operator,
            AiRouteAggregate.network_type == network_type,
            AiRouteAggregate.mode == mode,
            AiRouteAggregate.hour_bucket == bucket,
        )
    )
    if not aggregate:
        aggregate = AiRouteAggregate(
            config_key=config_key,
            location_key=event.location_key,
            location_title=event.location_title,
            operator=operator,
            network_type=network_type,
            mode=mode,
            hour_bucket=bucket,
        )
        db.add(aggregate)
        db.flush()

    aggregate.location_key = event.location_key
    aggregate.location_title = event.location_title
    aggregate.sample_count = int(aggregate.sample_count or 0) + 1
    aggregate.success_count = int(aggregate.success_count or 0) + (1 if success else 0)
    aggregate.failure_count = int(aggregate.failure_count or 0) + (0 if success else 1)
    aggregate.total_duration_seconds = int(aggregate.total_duration_seconds or 0) + event.duration_seconds
    if event.ping_ms > 0:
        aggregate.total_ping_ms = int(aggregate.total_ping_ms or 0) + event.ping_ms
        aggregate.ping_samples = int(aggregate.ping_samples or 0) + 1
    if event.jitter_ms > 0:
        aggregate.total_jitter_ms = int(aggregate.total_jitter_ms or 0) + event.jitter_ms
        aggregate.jitter_samples = int(aggregate.jitter_samples or 0) + 1
    aggregate.total_packet_loss_x100 = int(aggregate.total_packet_loss_x100 or 0) + event.packet_loss_x100
    aggregate.success_rate = aggregate.success_count / max(1, aggregate.sample_count)
    aggregate.average_ping_ms = aggregate.total_ping_ms / max(1, aggregate.ping_samples)
    aggregate.average_duration_seconds = aggregate.total_duration_seconds / max(1, aggregate.success_count)
    aggregate.score = route_score(aggregate)
    aggregate.updated_at = utcnow()
    db.commit()

    return {"accepted": True, "route_score": aggregate.score, "samples": aggregate.sample_count}


def recommendations(
    db: Session,
    *,
    operator: str,
    network_type: str,
    mode: str,
    bucket: int | None = None,
    limit: int = 20,
) -> list[dict[str, Any]]:
    operator = canonical_operator(operator)
    network_type = clean(network_type, 30).lower()
    mode = clean(mode, 30, "balanced").lower()
    bucket = hour_bucket(bucket)
    limit = max(1, min(limit, 50))

    rows = db.scalars(
        select(AiRouteAggregate)
        .where(AiRouteAggregate.sample_count > 0)
        .order_by(desc(AiRouteAggregate.updated_at))
        .limit(1000)
    ).all()

    combined: dict[str, dict[str, Any]] = {}
    for row in rows:
        context_weight = 0
        if row.operator == operator:
            context_weight += 14
        elif row.operator not in {"unknown", ""}:
            context_weight -= 3
        if row.network_type == network_type:
            context_weight += 8
        if row.mode == mode:
            context_weight += 7
        distance = min((row.hour_bucket - bucket) % 24, (bucket - row.hour_bucket) % 24)
        context_weight += max(0, 6 - distance * 2)
        confidence = min(8, int(math.log2(max(1, row.sample_count))) * 2)
        weighted = max(0, min(100, int(row.score or 0) + context_weight + confidence))

        item = combined.get(row.config_key)
        if item is None or weighted > item["score"]:
            combined[row.config_key] = {
                "config_key": row.config_key,
                "location_key": row.location_key,
                "location_title": row.location_title,
                "score": weighted,
                "base_score": int(row.score or 0),
                "samples": int(row.sample_count or 0),
                "success_rate": round(float(row.success_rate or 0.0) * 100.0, 1),
                "average_ping_ms": round(float(row.average_ping_ms or 0.0), 1),
                "average_duration_seconds": round(float(row.average_duration_seconds or 0.0), 1),
                "operator": row.operator,
                "network_type": row.network_type,
                "mode": row.mode,
            }

    return sorted(combined.values(), key=lambda x: (-x["score"], -x["samples"]))[:limit]


def customer_dashboard(db: Session, customer: Customer) -> dict[str, Any]:
    events = db.scalars(
        select(AiConnectionEvent)
        .where(AiConnectionEvent.customer_id == customer.id)
        .order_by(AiConnectionEvent.created_at.desc())
        .limit(500)
    ).all()
    successes = [x for x in events if x.success]
    duration = sum(int(x.duration_seconds or 0) for x in successes)
    avg_ping = sum(x.ping_ms for x in successes if x.ping_ms > 0) / max(1, sum(1 for x in successes if x.ping_ms > 0))
    route_totals: dict[str, dict[str, Any]] = {}
    for event in successes:
        item = route_totals.setdefault(event.config_key, {"duration": 0, "count": 0, "title": event.location_title, "key": event.location_key})
        item["duration"] += int(event.duration_seconds or 0)
        item["count"] += 1
    best = max(route_totals.values(), key=lambda x: (x["duration"], x["count"]), default=None)
    return {
        "learning_events": len(events),
        "successful_sessions": len(successes),
        "total_duration_seconds": duration,
        "average_ping_ms": round(avg_ping, 1),
        "success_rate": round(len(successes) * 100 / max(1, len(events)), 1),
        "best_location": best or {},
        "privacy": {
            "content_collected": False,
            "destination_ips_collected": False,
            "technical_metrics_only": True,
        },
    }


def submit_feedback(db: Session, customer: Customer, payload: dict[str, Any]) -> dict[str, Any]:
    feedback = AiFeedback(
        customer_id=customer.id,
        rating=clamp_int(payload.get("rating"), 1, 5, 5),
        category=clean(payload.get("category"), 50, "general"),
        message=clean(payload.get("message"), 2000, ""),
        diagnostics_json=json.dumps(payload.get("diagnostics") or {}, ensure_ascii=False)[:8000],
        app_version=clean(payload.get("app_version"), 40, ""),
    )
    db.add(feedback)
    db.commit()
    return {"accepted": True, "id": feedback.id}


def admin_overview(db: Session) -> dict[str, Any]:
    learning_filter = AiConnectionEvent.event_type != "heartbeat"
    total = db.scalar(
        select(func.count(AiConnectionEvent.id)).where(learning_filter)
    ) or 0
    successes = db.scalar(
        select(func.count(AiConnectionEvent.id)).where(
            learning_filter,
            AiConnectionEvent.success.is_(True),
        )
    ) or 0
    routes = db.scalar(select(func.count(AiRouteAggregate.id))) or 0
    avg_score = db.scalar(select(func.avg(AiRouteAggregate.score))) or 0
    active_24h = db.scalar(
        select(func.count(AiConnectionEvent.id)).where(
            learning_filter,
            AiConnectionEvent.created_at >= utcnow() - timedelta(hours=24),
        )
    ) or 0

    top_rows = db.scalars(
        select(AiRouteAggregate)
        .order_by(
            desc(AiRouteAggregate.score),
            desc(AiRouteAggregate.sample_count),
        )
        .limit(12)
    ).all()
    top = [
        {
            "location_title": row.location_title,
            "location_key": row.location_key,
            "operator": row.operator,
            "network_type": row.network_type,
            "mode": row.mode,
            "score": int(row.score or 0),
            "samples": int(row.sample_count or 0),
        }
        for row in top_rows
    ]

    failure_rows = db.scalars(
        select(AiConnectionEvent)
        .where(
            learning_filter,
            AiConnectionEvent.success.is_(False),
        )
        .order_by(AiConnectionEvent.created_at.desc())
        .limit(12)
    ).all()
    failures = [
        {
            "location_title": row.location_title,
            "operator": row.operator,
            "network_type": row.network_type,
            "failure_reason": row.failure_reason,
            "created_at": row.created_at.isoformat() if row.created_at else "",
        }
        for row in failure_rows
    ]

    operator_rows = db.execute(
        select(
            AiConnectionEvent.operator,
            func.count(AiConnectionEvent.id),
            func.sum(AiConnectionEvent.duration_seconds),
        )
        .where(learning_filter)
        .group_by(AiConnectionEvent.operator)
        .order_by(desc(func.count(AiConnectionEvent.id)))
        .limit(10)
    ).all()

    recent_heartbeats = db.scalars(
        select(AiConnectionEvent)
        .where(
            AiConnectionEvent.event_type == "heartbeat",
            AiConnectionEvent.created_at >= utcnow() - timedelta(seconds=95),
        )
        .order_by(AiConnectionEvent.created_at.desc())
        .limit(1000)
    ).all()
    live_by_device: dict[tuple[int, str], AiConnectionEvent] = {}
    for event in recent_heartbeats:
        key = (int(event.customer_id or 0), event.device_id or "")
        live_by_device.setdefault(key, event)
    live_events = list(live_by_device.values())
    live_operators: dict[str, int] = {}
    for event in live_events:
        live_operators[event.operator] = live_operators.get(event.operator, 0) + 1

    feedback_count = db.scalar(select(func.count(AiFeedback.id))) or 0
    feedback_avg = db.scalar(select(func.avg(AiFeedback.rating))) or 0
    newest = db.scalar(
        select(func.max(AiConnectionEvent.created_at))
    )
    return {
        "total_events": int(total),
        "success_rate": round(int(successes) * 100 / max(1, int(total)), 1),
        "learned_routes": int(routes),
        "average_score": round(float(avg_score), 1),
        "events_24h": int(active_24h),
        "feedback_count": int(feedback_count),
        "feedback_average": round(float(feedback_avg), 1),
        "live_sessions": len(live_events),
        "live_operators": [
            {"name": name, "count": count}
            for name, count in sorted(
                live_operators.items(),
                key=lambda item: (-item[1], item[0]),
            )
        ],
        "last_event_at": newest.isoformat() if newest else "",
        "updated_at": utcnow().isoformat(),
        "top_routes": top,
        "recent_failures": failures,
        "operators": [
            {
                "name": row[0],
                "events": int(row[1] or 0),
                "duration": int(row[2] or 0),
            }
            for row in operator_rows
        ],
    }
