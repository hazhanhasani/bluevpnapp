import json
from datetime import datetime, timedelta, timezone
from pathlib import Path

from sqlalchemy import create_engine
from sqlalchemy.orm import Session

from server.database import Base
from server.integrations import (
    activation_target,
    iso_z,
    normalize_gateway_amount_toman,
)
from server.main import UNLIMITED_ANDROID_EXPIRY, account_json
from server.models import Customer, Order, PasarGuardPanel, Plan


def build_order(db: Session, *, duration_days: int, created_at=None, paid_at=None):
    panel = PasarGuardPanel(
        name="test",
        base_url="https://panel.example",
        auth_mode="api_key",
        active=True,
    )
    db.add(panel)
    db.flush()
    plan = Plan(
        title="Plan",
        description="",
        price_toman=100_000,
        duration_days=duration_days,
        data_limit_gb=10,
        device_limit=1,
        panel_id=panel.id,
    )
    customer = Customer(
        email=f"user-{duration_days}-{panel.id}@example.com",
        password_hash="x",
    )
    db.add_all([plan, customer])
    db.flush()
    order = Order(
        order_code=f"ORDER-{duration_days}-{panel.id}",
        customer_id=customer.id,
        plan_id=plan.id,
        amount_toman=plan.price_toman,
        status="paid",
        gateway_json="{}",
        created_at=created_at or datetime.now(timezone.utc),
        paid_at=paid_at,
    )
    db.add(order)
    db.commit()
    db.refresh(order)
    return order, plan, customer


def test_iso_z_is_android_safe_utc():
    value = datetime(2026, 8, 6, 12, 0, 0, 123456, tzinfo=timezone.utc)
    assert iso_z(value) == "2026-08-06T12:00:00Z"


def test_renewal_extends_from_existing_valid_expiry():
    engine = create_engine("sqlite+pysqlite:///:memory:")
    Base.metadata.create_all(engine)
    with Session(engine) as db:
        order, plan, _ = build_order(db, duration_days=30)
        previous = datetime.now(timezone.utc) + timedelta(days=10)
        target = activation_target(db, order, plan, [previous])
        assert target is not None
        assert abs((target - (previous + timedelta(days=30))).total_seconds()) < 2
        metadata = json.loads(order.gateway_json)
        assert metadata["_bluevpn_target_expire"].endswith("Z")


def test_unlimited_plan_uses_explicit_metadata_and_android_value():
    engine = create_engine("sqlite+pysqlite:///:memory:")
    Base.metadata.create_all(engine)
    with Session(engine) as db:
        order, plan, customer = build_order(db, duration_days=0)
        target = activation_target(db, order, plan, [])
        assert target is None
        assert json.loads(order.gateway_json)["_bluevpn_target_expire"] == "unlimited"

        customer.subscription_status = "active"
        customer.subscription_url = "https://example.com/sub"
        customer.subscription_expire = None
        payload = account_json(customer)["subscription"]
        assert payload["unlimited"] is True
        assert payload["expire_mode"] == "unlimited"
        assert payload["expire"] == UNLIMITED_ANDROID_EXPIRY
        assert payload["active"] is True


def test_late_confirmation_adds_one_compensatory_day_once():
    engine = create_engine("sqlite+pysqlite:///:memory:")
    Base.metadata.create_all(engine)
    now = datetime.now(timezone.utc)
    with Session(engine) as db:
        order, plan, _ = build_order(
            db,
            duration_days=30,
            created_at=now - timedelta(hours=2),
            paid_at=now,
        )
        target = activation_target(db, order, plan, [])
        assert target is not None
        assert target > now + timedelta(days=30, hours=23)
        metadata = json.loads(order.gateway_json)
        assert metadata["_bluevpn_late_confirmation_bonus_days"] == 1
        same_target = activation_target(db, order, plan, [])
        assert same_target == target


def test_gateway_amount_normalizes_rial_and_toman():
    assert normalize_gateway_amount_toman({"amount_toman": 125000}) == (125000, "toman")
    assert normalize_gateway_amount_toman({"amount_rial": 1250000}) == (125000, "rial")
    assert normalize_gateway_amount_toman({"amount": "1,250,000", "currency": "IRR"}) == (125000, "rial")
    assert normalize_gateway_amount_toman({"amount": 1250000}, 125000) == (125000, "rial_inferred")


def test_workflow_no_longer_fails_on_branch_advanced_guard():
    workflow = Path(".github/workflows/build-apk.yml").read_text(encoding="utf-8")
    assert "cancel-in-progress: false" in workflow
    assert "queue: max" in workflow
    assert "Synchronize checkout with latest branch" in workflow
    assert "rebasing attempt" in workflow
    assert "The GitHub branch advanced while version metadata was being prepared" not in workflow
    assert "actions/checkout@v6" in workflow
    assert "actions/upload-artifact@v6" in workflow
