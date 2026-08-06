import json
from datetime import datetime, timedelta, timezone

from sqlalchemy import create_engine
from sqlalchemy.orm import Session

from server.database import Base
from server.integrations import (
    _expiry_matches_target,
    _pasarguard_expire_candidates,
    aggregate_customer,
    repair_subscription_states,
)
from server.models import Customer, Order, PasarGuardPanel, Plan


def _make_plan(db: Session, days: int = 30) -> tuple[PasarGuardPanel, Plan]:
    panel = PasarGuardPanel(
        name="main",
        base_url="https://panel.example",
        auth_mode="api_key",
        active=True,
    )
    db.add(panel)
    db.flush()
    plan = Plan(
        title="one month",
        description="",
        price_toman=150_000,
        duration_days=days,
        data_limit_gb=0,
        device_limit=1,
        panel_id=panel.id,
        active=True,
        deleted=False,
    )
    db.add(plan)
    db.flush()
    return panel, plan


def test_pasarguard_expiry_candidates_cover_seconds_iso_and_millis():
    target = datetime(2026, 9, 5, 9, 0, tzinfo=timezone.utc)
    values = _pasarguard_expire_candidates(target)
    seconds = int(target.timestamp())
    assert values[0] == seconds
    assert any(isinstance(value, str) and value.endswith("Z") for value in values)
    assert seconds * 1000 in values


def test_expiry_verification_rejects_same_day_regression():
    target = datetime.now(timezone.utc) + timedelta(days=30)
    stale = datetime.now(timezone.utc) + timedelta(hours=1)
    assert _expiry_matches_target({"expire": target.timestamp()}, target) is True
    assert _expiry_matches_target({"expire": stale.timestamp()}, target) is False


def test_aggregate_does_not_shorten_last_known_paid_expiry():
    old_expiry = datetime.now(timezone.utc) + timedelta(days=30)
    customer = Customer(
        email="regression@example.com",
        password_hash="x",
        active=True,
        subscription_status="active",
        subscription_url="https://bluevpn.example/sub/token",
        pasarguard_subscription_url="https://panel.example/sub/user",
        subscription_expire=old_expiry,
    )
    aggregate_customer(
        customer,
        None,
        public_base_url="https://bluevpn.example",
        pg_data={
            "status": "active",
            "subscription_url": "https://panel.example/sub/user",
            "expire": (datetime.now(timezone.utc) + timedelta(hours=1)).timestamp(),
            "data_limit": 0,
            "used_traffic": 0,
        },
    )
    assert customer.subscription_expire == old_expiry
    assert "تاریخ کوتاه‌تری" in customer.last_sync_error


def test_startup_repairs_active_account_expiry_from_latest_activation():
    engine = create_engine("sqlite+pysqlite:///:memory:")
    Base.metadata.create_all(engine)
    with Session(engine) as db:
        _, plan = _make_plan(db, 30)
        now = datetime.now(timezone.utc).replace(microsecond=0)
        customer = Customer(
            email="today@example.com",
            password_hash="x",
            active=True,
            plan_id=plan.id,
            subscription_status="active",
            subscription_url="https://bluevpn.example/sub/token",
            pasarguard_subscription_url="https://panel.example/sub/user",
            subscription_expire=now + timedelta(hours=1),
        )
        db.add(customer)
        db.flush()
        # Simulate the broken release: provider/local expiry became today and
        # even stored metadata was too short. The plan and activation time are
        # still enough to reconstruct the full one-month entitlement.
        order = Order(
            order_code="MANUAL-EXPIRY-REGRESSION",
            customer_id=customer.id,
            plan_id=plan.id,
            amount_toman=0,
            status="activated",
            gateway_json=json.dumps(
                {"_bluevpn_target_expire": (now + timedelta(hours=1)).isoformat()}
            ),
            paid_at=now,
            activated_at=now,
        )
        db.add(order)
        db.commit()

        result = repair_subscription_states(db)
        db.refresh(customer)

        assert result["expiry_repaired"] == 1
        assert order.id in result["provider_repair_order_ids"]
        repaired = customer.subscription_expire.replace(tzinfo=timezone.utc)
        assert repaired >= now + timedelta(days=29, hours=23)
        assert customer.subscription_status == "active"
        db.refresh(order)
        metadata = json.loads(order.gateway_json)
        assert metadata["_bluevpn_target_reconstruction_source"] == (
            "activation_time_plus_plan_duration"
        )
        assert metadata["_bluevpn_target_expire"].endswith("Z")


def test_explicit_disabled_account_is_not_reactivated_by_expiry_repair():
    engine = create_engine("sqlite+pysqlite:///:memory:")
    Base.metadata.create_all(engine)
    with Session(engine) as db:
        _, plan = _make_plan(db, 30)
        now = datetime.now(timezone.utc).replace(microsecond=0)
        customer = Customer(
            email="disabled-regression@example.com",
            password_hash="x",
            active=True,
            plan_id=plan.id,
            subscription_status="disabled",
            subscription_url="https://bluevpn.example/sub/token",
            pasarguard_subscription_url="https://panel.example/sub/user",
            subscription_expire=now + timedelta(hours=1),
        )
        db.add(customer)
        db.flush()
        order = Order(
            order_code="DISABLED-NOT-REVIVED",
            customer_id=customer.id,
            plan_id=plan.id,
            amount_toman=0,
            status="activated",
            gateway_json="{}",
            paid_at=now,
            activated_at=now,
        )
        db.add(order)
        db.commit()

        result = repair_subscription_states(db)
        db.refresh(customer)

        assert customer.subscription_status == "disabled"
        assert order.id not in result["provider_repair_order_ids"]
