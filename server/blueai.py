from __future__ import annotations

import json
import math
import re
from datetime import datetime, timedelta, timezone
from typing import Any

from sqlalchemy import case, desc, func, select
from sqlalchemy.orm import Session

from .models import AiConnectionEvent, AiFeedback, AiLiveConnection, AiRouteAggregate, Customer


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


LIVE_TTL_SECONDS = 180
LIVE_PROBE_MAX_AGE_MS = 130_000


def as_bool(value: Any) -> bool:
    if isinstance(value, bool):
        return value
    if isinstance(value, (int, float)):
        return value != 0
    return str(value or "").strip().lower() in {
        "1", "true", "yes", "on", "connected", "verified",
    }


def epoch_millis_to_utc(value: Any) -> datetime | None:
    try:
        raw = int(value)
    except (TypeError, ValueError):
        return None
    if raw <= 0:
        return None
    seconds = raw / 1000.0 if raw > 10_000_000_000 else float(raw)
    try:
        return datetime.fromtimestamp(seconds, timezone.utc)
    except (OverflowError, OSError, ValueError):
        return None


def heartbeat_proof(payload: dict[str, Any]) -> tuple[bool, str]:
    session_id = clean(payload.get("session_id"), 80, "")
    probe_age_ms = clamp_int(
        payload.get("probe_age_ms"),
        0,
        86_400_000,
        86_400_000,
    )
    source = clean(payload.get("verification_source"), 80, "")
    checks = {
        "session": len(session_id) >= 8,
        "connected": as_bool(payload.get("connected")),
        "tunnel_running": as_bool(payload.get("tunnel_running")),
        "vpn_transport": as_bool(payload.get("vpn_transport")),
        "internet_verified": as_bool(payload.get("internet_verified")),
        "fresh_probe": probe_age_ms <= LIVE_PROBE_MAX_AGE_MS,
        "source": source in {
            "bluevpn-health",
            "cloudflare-204",
            "google-204",
            "cloudflare-trace",
            "xray-http-probe",
        },
    }
    failed = [name for name, ok in checks.items() if not ok]
    return not failed, ",".join(failed)


def update_live_connection(
    db: Session,
    customer: Customer,
    payload: dict[str, Any],
    *,
    operator: str,
    network_type: str,
    mode: str,
    proof_ok: bool,
    proof_error: str,
) -> tuple[AiLiveConnection | None, bool]:
    device_id = clean(payload.get("device_id"), 80, "")
    session_id = clean(payload.get("session_id"), 80, "")
    if not device_id or not session_id:
        return None, False

    now = utcnow()
    heartbeat_seq = clamp_int(payload.get("heartbeat_seq"), 0, 10**18)
    row = db.scalar(
        select(AiLiveConnection)
        .where(
            AiLiveConnection.customer_id == customer.id,
            AiLiveConnection.device_id == device_id,
        )
        .with_for_update()
    )
    if row is None:
        row = AiLiveConnection(
            customer_id=customer.id,
            device_id=device_id,
        )
        db.add(row)
        db.flush()

    same_session = row.session_id == session_id
    if (
        same_session
        and heartbeat_seq
        and heartbeat_seq < int(row.heartbeat_seq or 0)
    ):
        return row, False

    if not proof_ok:
        if same_session:
            row.connected = False
            row.verified = False
            row.tunnel_running = as_bool(payload.get("tunnel_running"))
            row.vpn_transport = as_bool(payload.get("vpn_transport"))
            row.last_seen_at = now
            row.expires_at = now
            row.disconnected_at = now
            row.disconnect_reason = f"unverified:{proof_error}"[:500]
        return row, False

    started_at = epoch_millis_to_utc(payload.get("started_at"))
    traffic_active = as_bool(payload.get("traffic_active"))
    row.session_id = session_id
    row.config_key = clean(payload.get("config_key"), 80, "")
    row.location_key = clean(payload.get("location_key"), 24)
    row.location_title = clean(
        payload.get("location_title"),
        100,
        "نامشخص",
    )
    row.operator = operator
    row.network_type = network_type
    row.mode = mode
    row.connected = True
    row.verified = True
    row.tunnel_running = True
    row.vpn_transport = True
    row.verification_source = clean(
        payload.get("verification_source"),
        80,
        "",
    )
    row.ping_ms = clamp_int(payload.get("ping_ms"), 0, 10000)
    row.health_score = clamp_int(payload.get("health_score"), 0, 100)
    incoming_download = clamp_int(
        payload.get("download_bytes"),
        0,
        10**18,
    )
    incoming_upload = clamp_int(
        payload.get("upload_bytes"),
        0,
        10**18,
    )
    if same_session:
        row.download_bytes = max(
            int(row.download_bytes or 0),
            incoming_download,
        )
        row.upload_bytes = max(
            int(row.upload_bytes or 0),
            incoming_upload,
        )
    else:
        row.download_bytes = incoming_download
        row.upload_bytes = incoming_upload
    row.traffic_active = traffic_active
    if traffic_active:
        row.last_traffic_at = now
    row.heartbeat_seq = heartbeat_seq
    row.started_at = started_at or (
        row.started_at if same_session else now
    )
    row.last_verified_at = now
    row.last_seen_at = now
    row.expires_at = now + timedelta(seconds=LIVE_TTL_SECONDS)
    row.disconnected_at = None
    row.disconnect_reason = ""
    row.app_version = clean(payload.get("app_version"), 40, "")
    row.android_version = clean(
        payload.get("android_version"),
        40,
        "",
    )
    row.device_model = clean(payload.get("device_model"), 160, "")
    return row, True


def disconnect_live_connection(
    db: Session,
    customer: Customer,
    payload: dict[str, Any],
    reason: str,
) -> bool:
    device_id = clean(payload.get("device_id"), 80, "")
    session_id = clean(payload.get("session_id"), 80, "")
    if not device_id or not session_id:
        return False
    row = db.scalar(
        select(AiLiveConnection)
        .where(
            AiLiveConnection.customer_id == customer.id,
            AiLiveConnection.device_id == device_id,
        )
        .with_for_update()
    )
    if row is None or row.session_id != session_id:
        return False
    now = utcnow()
    row.connected = False
    row.verified = False
    row.last_seen_at = now
    row.expires_at = now
    row.disconnected_at = now
    row.disconnect_reason = clean(reason, 500, "disconnected")
    return True


def hour_bucket(value: Any = None) -> int:
    if value is not None:
        return clamp_int(value, 0, 23, utcnow().hour)
    return utcnow().hour


def _as_utc(value: datetime | None) -> datetime:
    if value is None:
        return utcnow()
    if value.tzinfo is None:
        return value.replace(tzinfo=timezone.utc)
    return value.astimezone(timezone.utc)


def _recent_route_stats(
    events: list[AiConnectionEvent],
    *,
    now: datetime | None = None,
    half_life_hours: float = 2.0,
) -> dict[str, float]:
    """Build exponentially-decayed route metrics from raw events.

    A two-hour half-life means an event from one hour ago keeps ~70% of its
    weight, while an event from 24 hours ago keeps less than 0.03%. This lets
    current outages recover quickly without deleting useful long-term history.
    """
    current = _as_utc(now)
    half_life = max(0.25, float(half_life_hours))

    weighted_samples = 0.0
    weighted_successes = 0.0
    weighted_failures = 0.0
    weighted_ping = 0.0
    weighted_ping_samples = 0.0
    weighted_jitter = 0.0
    weighted_jitter_samples = 0.0
    weighted_loss = 0.0
    weighted_duration = 0.0
    weighted_duration_samples = 0.0

    for event in events:
        created_at = _as_utc(getattr(event, "created_at", None))
        age_hours = max(0.0, (current - created_at).total_seconds() / 3600.0)
        weight = math.pow(0.5, age_hours / half_life)
        if weight < 0.000001:
            continue

        weighted_samples += weight
        if bool(getattr(event, "success", False)):
            weighted_successes += weight
        else:
            weighted_failures += weight

        ping_ms = max(0.0, float(getattr(event, "ping_ms", 0) or 0))
        if ping_ms > 0:
            weighted_ping += ping_ms * weight
            weighted_ping_samples += weight

        jitter_ms = max(0.0, float(getattr(event, "jitter_ms", 0) or 0))
        if jitter_ms > 0:
            weighted_jitter += jitter_ms * weight
            weighted_jitter_samples += weight

        loss_x100 = max(0.0, float(getattr(event, "packet_loss_x100", 0) or 0))
        weighted_loss += (loss_x100 / 100.0) * weight

        duration = max(0.0, float(getattr(event, "duration_seconds", 0) or 0))
        if duration > 0 and bool(getattr(event, "success", False)):
            weighted_duration += duration * weight
            weighted_duration_samples += weight

    return {
        "weighted_samples": weighted_samples,
        "weighted_successes": weighted_successes,
        "weighted_failures": weighted_failures,
        "success_rate": weighted_successes / max(0.000001, weighted_samples),
        "failure_rate": weighted_failures / max(0.000001, weighted_samples),
        "average_ping_ms": weighted_ping / max(0.000001, weighted_ping_samples),
        "average_jitter_ms": weighted_jitter / max(0.000001, weighted_jitter_samples),
        "average_packet_loss": weighted_loss / max(0.000001, weighted_samples),
        "average_duration_seconds": weighted_duration / max(0.000001, weighted_duration_samples),
    }


def _wilson_lower_bound(successes: float, samples: float, z: float = 1.645) -> float:
    """Conservative success estimate; 1.645 is a one-sided 95% bound."""
    if samples <= 0:
        return 0.0
    proportion = max(0.0, min(1.0, successes / samples))
    denominator = 1.0 + (z * z / samples)
    centre = proportion + (z * z / (2.0 * samples))
    margin = z * math.sqrt(
        max(0.0, (proportion * (1.0 - proportion) / samples) + (z * z / (4.0 * samples * samples)))
    )
    return max(0.0, min(1.0, (centre - margin) / denominator))


def _route_score_details(
    aggregate: AiRouteAggregate,
    recent_stats: dict[str, float] | None = None,
) -> dict[str, Any]:
    raw_samples = max(0, int(aggregate.sample_count or 0))
    raw_successes = max(0, int(aggregate.success_count or 0))
    raw_failures = max(0, int(aggregate.failure_count or 0))
    raw_success_rate = raw_successes / max(1, raw_samples)

    if recent_stats is None:
        # Compatibility fallback for callers that only have the aggregate.
        age_hours = max(
            0.0,
            (utcnow() - _as_utc(getattr(aggregate, "updated_at", None))).total_seconds() / 3600.0,
        )
        freshness = math.pow(0.5, age_hours / 12.0)
        recent_stats = {
            "weighted_samples": max(0.25, raw_samples * freshness),
            "weighted_successes": raw_successes * freshness,
            "weighted_failures": raw_failures * freshness,
            "success_rate": raw_success_rate,
            "failure_rate": 1.0 - raw_success_rate,
            "average_ping_ms": float(aggregate.total_ping_ms or 0) / max(1, int(aggregate.ping_samples or 0)),
            "average_jitter_ms": float(aggregate.total_jitter_ms or 0) / max(1, int(aggregate.jitter_samples or 0)),
            "average_packet_loss": float(aggregate.total_packet_loss_x100 or 0) / max(1, raw_samples) / 100.0,
            "average_duration_seconds": float(aggregate.total_duration_seconds or 0) / max(1, raw_successes),
        }

    weighted_samples = max(0.0, float(recent_stats.get("weighted_samples", 0.0)))
    weighted_successes = max(0.0, float(recent_stats.get("weighted_successes", 0.0)))
    recent_success_rate = max(0.0, min(1.0, float(recent_stats.get("success_rate", raw_success_rate))))
    recent_failure_rate = max(0.0, min(1.0, float(recent_stats.get("failure_rate", 1.0 - recent_success_rate))))
    avg_ping = max(0.0, float(recent_stats.get("average_ping_ms", 0.0)))
    avg_jitter = max(0.0, float(recent_stats.get("average_jitter_ms", 0.0)))
    avg_loss = max(0.0, float(recent_stats.get("average_packet_loss", 0.0)))
    avg_duration = max(0.0, float(recent_stats.get("average_duration_seconds", 0.0)))

    # Confidence combines long-term volume with the amount of fresh evidence.
    raw_confidence = 1.0 - math.exp(-raw_samples / 24.0)
    recent_confidence = 1.0 - math.exp(-weighted_samples / 5.0)
    confidence = max(0.0, min(1.0, raw_confidence * 0.65 + recent_confidence * 0.35))

    # Recent results dominate, but low-volume bursts cannot completely erase a
    # well-tested long-term history. Wilson lower bound makes 100/100 more
    # trustworthy than 2/2.
    recent_lower_bound = _wilson_lower_bound(weighted_successes, max(0.01, weighted_samples))
    long_lower_bound = _wilson_lower_bound(float(raw_successes), float(max(1, raw_samples)))
    recency_mix = min(0.88, 0.48 + recent_confidence * 0.40)
    conservative_success = recent_lower_bound * recency_mix + long_lower_bound * (1.0 - recency_mix)
    observed_success = recent_success_rate * 0.78 + raw_success_rate * 0.22
    reliability = conservative_success * 0.68 + observed_success * 0.32

    success_component = reliability * 66.0

    if avg_ping <= 0:
        ping_component = 7.0
    elif avg_ping <= 70:
        ping_component = 16.0
    elif avg_ping <= 180:
        ping_component = 16.0 - ((avg_ping - 70.0) / 110.0) * 7.0
    elif avg_ping <= 450:
        ping_component = 9.0 - ((avg_ping - 180.0) / 270.0) * 9.0
    else:
        ping_component = 0.0

    # Non-linear jitter penalty: small jitter is tolerated, unstable routes are
    # punished aggressively.
    if avg_jitter <= 12.0:
        jitter_penalty = 0.0
    elif avg_jitter <= 30.0:
        jitter_penalty = ((avg_jitter - 12.0) / 18.0) * 7.0
    elif avg_jitter <= 65.0:
        jitter_penalty = 7.0 + ((avg_jitter - 30.0) / 35.0) * 18.0
    else:
        jitter_penalty = min(44.0, 25.0 + ((avg_jitter - 65.0) / 85.0) * 19.0)

    loss_penalty = min(24.0, max(0.0, avg_loss) * 2.4)
    duration_component = min(5.0, math.log1p(avg_duration) / math.log(1801.0) * 5.0) if avg_duration > 0 else 0.0
    confidence_component = confidence * 9.0

    operator = canonical_operator(getattr(aggregate, "operator", "unknown"))
    operator_is_specific = operator not in {"unknown", "ناشناخته", "Wi-Fi", ""}
    enough_recent_evidence = weighted_samples >= 5.0
    enough_total_evidence = raw_samples >= 10
    blocked_for_operator = bool(
        operator_is_specific
        and enough_recent_evidence
        and enough_total_evidence
        and recent_failure_rate >= 0.72
    )

    score = (
        success_component
        + ping_component
        + duration_component
        + confidence_component
        - jitter_penalty
        - loss_penalty
    )

    # A route blocked for one operator receives zero only in that operator's
    # aggregate. Other operator aggregates for the same config remain intact.
    if blocked_for_operator:
        score = 0.0

    return {
        "score": int(round(max(0.0, min(100.0, score)))),
        "confidence": round(confidence, 4),
        "recent_success_rate": round(recent_success_rate, 4),
        "recent_failure_rate": round(recent_failure_rate, 4),
        "recent_effective_samples": round(weighted_samples, 3),
        "average_ping_ms": round(avg_ping, 2),
        "average_jitter_ms": round(avg_jitter, 2),
        "average_packet_loss": round(avg_loss, 3),
        "jitter_penalty": round(jitter_penalty, 2),
        "loss_penalty": round(loss_penalty, 2),
        "blocked_for_operator": blocked_for_operator,
        "blocked_operator": operator if blocked_for_operator else "",
        "block_reason": "operator_recent_failure_rate" if blocked_for_operator else "",
    }


def route_score(
    aggregate: AiRouteAggregate,
    recent_stats: dict[str, float] | None = None,
) -> int:
    """Return a 0..100 operator-aware route score.

    ``recent_stats`` is normally generated by ``submit_event`` from raw events
    with exponential time decay. Keeping it optional preserves compatibility
    with existing callers and tests that only pass an aggregate.
    """
    return int(_route_score_details(aggregate, recent_stats)["score"])


def submit_event(db: Session, customer: Customer, payload: dict[str, Any]) -> dict[str, Any]:
    config_key = clean(payload.get("config_key"), 80, "")
    if len(config_key) < 8:
        raise ValueError("config_key نامعتبر است")

    operator = canonical_operator(payload.get("operator"))
    network_type = clean(payload.get("network_type"), 30).lower()
    mode = clean(payload.get("mode"), 30, "balanced").lower()
    event_type = clean(payload.get("event_type"), 30, "session").lower()
    bucket = hour_bucket(payload.get("hour_bucket"))

    proof_ok = False
    proof_error = ""
    if event_type == "heartbeat":
        proof_ok, proof_error = heartbeat_proof(payload)
        success = proof_ok
    else:
        success = as_bool(payload.get("success"))

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
        failure_reason=(
            clean(payload.get("failure_reason"), 500, "")
            if event_type != "heartbeat"
            else clean(proof_error, 500, "")
        ),
        app_version=clean(payload.get("app_version"), 40, ""),
        android_version=clean(payload.get("android_version"), 40, ""),
        device_model=clean(payload.get("device_model"), 160, ""),
        hour_bucket=bucket,
    )
    db.add(event)
    db.flush()

    if event_type == "heartbeat":
        live_row, accepted_live = update_live_connection(
            db,
            customer,
            payload,
            operator=operator,
            network_type=network_type,
            mode=mode,
            proof_ok=proof_ok,
            proof_error=proof_error,
        )
        db.commit()
        return {
            "accepted": True,
            "live": accepted_live,
            "verified": proof_ok,
            "proof_error": proof_error,
            "operator": operator,
            "network_type": network_type,
            "session_id": live_row.session_id if live_row else "",
            "expires_in_seconds": (
                LIVE_TTL_SECONDS if accepted_live else 0
            ),
        }

    if (
        event_type in {"session", "disconnect"}
        or clean(payload.get("live_state"), 30, "").lower()
        == "disconnected"
        or not as_bool(payload.get("connected", True))
    ):
        disconnect_live_connection(
            db,
            customer,
            payload,
            clean(
                payload.get("failure_reason"),
                500,
                event_type,
            ),
        )

    aggregate = db.scalar(
        select(AiRouteAggregate)
        .where(
            AiRouteAggregate.config_key == config_key,
            AiRouteAggregate.operator == operator,
            AiRouteAggregate.network_type == network_type,
            AiRouteAggregate.mode == mode,
            AiRouteAggregate.hour_bucket == bucket,
        )
        .with_for_update()
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
    aggregate.success_count = int(aggregate.success_count or 0) + (
        1 if success else 0
    )
    aggregate.failure_count = int(aggregate.failure_count or 0) + (
        0 if success else 1
    )
    aggregate.total_duration_seconds = (
        int(aggregate.total_duration_seconds or 0)
        + event.duration_seconds
    )
    if event.ping_ms > 0:
        aggregate.total_ping_ms = (
            int(aggregate.total_ping_ms or 0)
            + event.ping_ms
        )
        aggregate.ping_samples = int(aggregate.ping_samples or 0) + 1
    if event.jitter_ms > 0:
        aggregate.total_jitter_ms = (
            int(aggregate.total_jitter_ms or 0)
            + event.jitter_ms
        )
        aggregate.jitter_samples = int(aggregate.jitter_samples or 0) + 1
    aggregate.total_packet_loss_x100 = (
        int(aggregate.total_packet_loss_x100 or 0)
        + event.packet_loss_x100
    )
    aggregate.success_rate = (
        aggregate.success_count
        / max(1, aggregate.sample_count)
    )
    aggregate.average_ping_ms = (
        aggregate.total_ping_ms
        / max(1, aggregate.ping_samples)
    )
    aggregate.average_duration_seconds = (
        aggregate.total_duration_seconds
        / max(1, aggregate.success_count)
    )

    recent_events = db.scalars(
        select(AiConnectionEvent)
        .where(
            AiConnectionEvent.event_type != "heartbeat",
            AiConnectionEvent.config_key == config_key,
            AiConnectionEvent.operator == operator,
            AiConnectionEvent.network_type == network_type,
            AiConnectionEvent.mode == mode,
            AiConnectionEvent.created_at
            >= utcnow() - timedelta(hours=48),
        )
        .order_by(AiConnectionEvent.created_at.desc())
        .limit(600)
    ).all()
    recent_stats = _recent_route_stats(
        list(recent_events),
        half_life_hours=2.0,
    )
    details = _route_score_details(aggregate, recent_stats)

    aggregate.score = int(details["score"])
    aggregate.updated_at = utcnow()
    db.commit()

    return {
        "accepted": True,
        "route_score": aggregate.score,
        "samples": int(aggregate.sample_count or 0),
        "confidence": details["confidence"],
        "recent_effective_samples": details[
            "recent_effective_samples"
        ],
        "recent_success_rate": round(
            float(details["recent_success_rate"]) * 100.0,
            1,
        ),
        "average_jitter_ms": details["average_jitter_ms"],
        "jitter_penalty": details["jitter_penalty"],
        "blocked_for_operator": bool(
            details["blocked_for_operator"]
        ),
        "blocked_operator": details["blocked_operator"],
        "block_reason": details["block_reason"],
    }

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

    # The newest exact-operator row controls the circuit breaker. Older blocked
    # hour buckets must not keep a route closed after a newer successful event
    # has recovered it. Fallback rows from other operators still cannot bypass
    # an active block for the requested operator.
    block_cutoff = utcnow() - timedelta(hours=6)
    latest_operator_rows: dict[str, AiRouteAggregate] = {}
    for row in rows:
        if row.operator == operator:
            latest_operator_rows.setdefault(row.config_key, row)
    blocked_config_keys = {
        config_key
        for config_key, row in latest_operator_rows.items()
        if int(row.score or 0) <= 0 and _as_utc(row.updated_at) >= block_cutoff
    }

    combined: dict[str, dict[str, Any]] = {}
    for row in rows:
        if row.config_key in blocked_config_keys:
            continue

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
            func.sum(
                case(
                    (AiConnectionEvent.success.is_(True), 1),
                    else_=0,
                )
            ),
        )
        .where(learning_filter)
        .group_by(AiConnectionEvent.operator)
        .order_by(desc(func.count(AiConnectionEvent.id)))
        .limit(10)
    ).all()

    now = utcnow()
    live_events = db.scalars(
        select(AiLiveConnection)
        .where(
            AiLiveConnection.connected.is_(True),
            AiLiveConnection.verified.is_(True),
            AiLiveConnection.tunnel_running.is_(True),
            AiLiveConnection.vpn_transport.is_(True),
            AiLiveConnection.expires_at > now,
            AiLiveConnection.last_verified_at
            >= now - timedelta(seconds=LIVE_TTL_SECONDS),
        )
        .order_by(AiLiveConnection.last_verified_at.desc())
        .limit(2000)
    ).all()
    live_operators: dict[str, int] = {}
    for event in live_events:
        live_operators[event.operator] = (
            live_operators.get(event.operator, 0) + 1
        )
    live_connections = [
        {
            "customer_id": int(row.customer_id or 0),
            "device": (
                (row.device_model or "دستگاه ناشناس")[:80]
            ),
            "location_title": row.location_title,
            "location_key": row.location_key,
            "operator": row.operator,
            "network_type": row.network_type,
            "mode": row.mode,
            "ping_ms": int(row.ping_ms or 0),
            "health_score": int(row.health_score or 0),
            "download_bytes": int(row.download_bytes or 0),
            "upload_bytes": int(row.upload_bytes or 0),
            "traffic_active": bool(
                row.last_traffic_at
                and _as_utc(row.last_traffic_at)
                >= now - timedelta(seconds=180)
            ),
            "verification_source": row.verification_source,
            "started_at": (
                row.started_at.isoformat()
                if row.started_at
                else ""
            ),
            "last_verified_at": (
                row.last_verified_at.isoformat()
                if row.last_verified_at
                else ""
            ),
            "expires_in_seconds": max(
                0,
                int(
                    (
                        _as_utc(row.expires_at) - now
                    ).total_seconds()
                ),
            ),
        }
        for row in live_events
    ]

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
        "live_active_traffic": sum(
            1
            for row in live_connections
            if row["traffic_active"]
        ),
        "live_connections": live_connections,
        "live_detection": {
            "method": "vpn_transport+xray_proxy+remote_proof",
            "ttl_seconds": LIVE_TTL_SECONDS,
        },
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
                "successes": int(row[3] or 0),
                "success_rate": round(
                    int(row[3] or 0) * 100 / max(1, int(row[1] or 0)),
                    1,
                ),
            }
            for row in operator_rows
        ],
    }
