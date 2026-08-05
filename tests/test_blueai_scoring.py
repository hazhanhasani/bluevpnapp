from datetime import datetime, timedelta, timezone
from types import SimpleNamespace

from server.blueai import _recent_route_stats, _route_score_details, route_score


def aggregate(samples=20, successes=18, jitter=10, operator="ایرانسل"):
    return SimpleNamespace(
        sample_count=samples,
        success_count=successes,
        failure_count=samples - successes,
        total_duration_seconds=successes * 600,
        total_ping_ms=samples * 90,
        ping_samples=samples,
        total_jitter_ms=samples * jitter,
        jitter_samples=samples,
        total_packet_loss_x100=0,
        operator=operator,
        updated_at=datetime.now(timezone.utc),
    )


def event(*, age_hours, success, jitter=10, ping=90):
    return SimpleNamespace(
        created_at=datetime.now(timezone.utc) - timedelta(hours=age_hours),
        success=success,
        jitter_ms=jitter,
        ping_ms=ping,
        packet_loss_x100=0,
        duration_seconds=600 if success else 0,
    )


def test_recent_failure_outweighs_day_old_success():
    stats = _recent_route_stats([
        event(age_hours=0.05, success=False),
        *[event(age_hours=24, success=True) for _ in range(20)],
    ])
    assert stats["failure_rate"] > 0.95


def test_high_jitter_is_heavily_penalized():
    base = aggregate(samples=100, successes=97)
    good = _route_score_details(base, {
        "weighted_samples": 30,
        "weighted_successes": 29,
        "weighted_failures": 1,
        "success_rate": 29 / 30,
        "failure_rate": 1 / 30,
        "average_ping_ms": 85,
        "average_jitter_ms": 8,
        "average_packet_loss": 0,
        "average_duration_seconds": 600,
    })
    bad = _route_score_details(base, {
        **{k: v for k, v in good.items() if False},
        "weighted_samples": 30,
        "weighted_successes": 29,
        "weighted_failures": 1,
        "success_rate": 29 / 30,
        "failure_rate": 1 / 30,
        "average_ping_ms": 85,
        "average_jitter_ms": 140,
        "average_packet_loss": 0,
        "average_duration_seconds": 600,
    })
    assert good["score"] - bad["score"] >= 25


def test_operator_block_is_scoped_and_requires_evidence():
    route = aggregate(samples=30, successes=5, operator="همراه اول")
    details = _route_score_details(route, {
        "weighted_samples": 12,
        "weighted_successes": 2,
        "weighted_failures": 10,
        "success_rate": 2 / 12,
        "failure_rate": 10 / 12,
        "average_ping_ms": 250,
        "average_jitter_ms": 60,
        "average_packet_loss": 8,
        "average_duration_seconds": 40,
    })
    assert details["blocked_for_operator"] is True
    assert details["blocked_operator"] == "همراه اول"
    assert details["score"] == 0


def test_confidence_rewards_large_sample_history():
    recent = {
        "weighted_samples": 2,
        "weighted_successes": 2,
        "weighted_failures": 0,
        "success_rate": 1.0,
        "failure_rate": 0.0,
        "average_ping_ms": 80,
        "average_jitter_ms": 8,
        "average_packet_loss": 0,
        "average_duration_seconds": 600,
    }
    small = route_score(aggregate(samples=2, successes=2), recent)
    large = route_score(aggregate(samples=100, successes=100), recent)
    assert large > small
