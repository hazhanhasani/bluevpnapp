from datetime import datetime, timedelta, timezone

from sqlalchemy import create_engine
from sqlalchemy.orm import Session

from server.database import Base
from server.integrations import aggregate_customer, repair_subscription_states
from server.main import account_json
from server.models import Customer


def make_customer(**overrides):
    values = {
        "email": "recover@example.com",
        "password_hash": "x",
        "active": True,
        "subscription_status": "active",
        "subscription_url": "https://bluevpn.example/sub/token",
        "pasarguard_subscription_url": "https://panel.example/sub/user",
        "subscription_expire": datetime.now(timezone.utc) + timedelta(days=15),
        "data_limit_bytes": 10_000,
        "used_traffic_bytes": 1_000,
    }
    values.update(overrides)
    return Customer(**values)


def test_transient_provider_error_preserves_last_known_active_subscription():
    customer = make_customer()
    old_expire = customer.subscription_expire
    aggregate_customer(
        customer,
        None,
        public_base_url="https://bluevpn.example",
        pg_error="HTTP 520",
    )
    assert customer.subscription_status == "active"
    assert customer.subscription_expire == old_expire
    assert customer.data_limit_bytes == 10_000
    assert customer.used_traffic_bytes == 1_000
    assert "حفظ شد" in customer.last_sync_error


def test_successful_provider_payload_without_status_defaults_to_active():
    customer = make_customer(subscription_status="inactive")
    future = datetime.now(timezone.utc) + timedelta(days=30)
    aggregate_customer(
        customer,
        None,
        public_base_url="https://bluevpn.example",
        pg_data={
            "id": 44,
            "subscription_url": "https://panel.example/sub/new",
            "expire": future.isoformat(),
            "data_limit": 0,
            "used_traffic": 0,
        },
    )
    assert customer.subscription_status == "active"
    assert customer.subscription_expire is not None
    assert customer.subscription_expire > datetime.now(timezone.utc)


def test_healthy_explicit_inactive_response_can_downgrade():
    customer = make_customer()
    aggregate_customer(
        customer,
        None,
        public_base_url="https://bluevpn.example",
        pg_data={
            "id": 44,
            "status": "disabled",
            "subscription_url": "https://panel.example/sub/user",
            "expire": customer.subscription_expire.isoformat(),
            "data_limit": 10_000,
            "used_traffic": 1_000,
        },
    )
    assert customer.subscription_status == "disabled"


def test_startup_repair_recovers_future_subscription_marked_inactive():
    engine = create_engine("sqlite+pysqlite:///:memory:")
    Base.metadata.create_all(engine)
    with Session(engine) as db:
        customer = make_customer(
            subscription_status="inactive",
            last_sync_error="برخی مسیرهای پشتیبان در حال همگام‌سازی هستند",
        )
        db.add(customer)
        db.commit()
        result = repair_subscription_states(db)
        db.refresh(customer)
        assert result["repaired"] == 1
        assert customer.subscription_status == "active"


def test_account_payload_keeps_recoverable_finite_subscription_active():
    customer = make_customer(
        subscription_status="inactive",
        last_sync_error="temporary provider error",
    )
    payload = account_json(customer)["subscription"]
    assert payload["active"] is True
    assert payload["status"] == "active"


def test_startup_repair_recovers_pasarguard_unlimited_plan():
    from server.models import PasarGuardPanel, Plan

    engine = create_engine("sqlite+pysqlite:///:memory:")
    Base.metadata.create_all(engine)
    with Session(engine) as db:
        panel = PasarGuardPanel(
            name="main",
            base_url="https://panel.example",
            auth_mode="api_key",
            active=True,
        )
        db.add(panel)
        db.flush()
        plan = Plan(
            title="Unlimited",
            description="",
            price_toman=100_000,
            duration_days=0,
            data_limit_gb=0,
            device_limit=1,
            panel_id=panel.id,
            active=True,
            deleted=False,
        )
        db.add(plan)
        db.flush()
        customer = make_customer(
            email="unlimited@example.com",
            plan_id=plan.id,
            subscription_status="inactive",
            subscription_expire=None,
        )
        db.add(customer)
        db.commit()
        result = repair_subscription_states(db)
        db.refresh(customer)
        assert result["repaired"] == 1
        assert customer.subscription_status == "active"


def test_startup_repair_restores_erased_expiry_from_latest_order_metadata():
    import json
    from server.models import Order, PasarGuardPanel, Plan

    engine = create_engine("sqlite+pysqlite:///:memory:")
    Base.metadata.create_all(engine)
    with Session(engine) as db:
        panel = PasarGuardPanel(
            name="main",
            base_url="https://panel.example",
            auth_mode="api_key",
            active=True,
        )
        db.add(panel)
        db.flush()
        plan = Plan(
            title="30 days",
            description="",
            price_toman=100_000,
            duration_days=30,
            data_limit_gb=10,
            device_limit=1,
            panel_id=panel.id,
            active=True,
            deleted=False,
        )
        db.add(plan)
        db.flush()
        target = datetime.now(timezone.utc) + timedelta(days=20)
        customer = make_customer(
            email="erased-expiry@example.com",
            plan_id=plan.id,
            subscription_status="inactive",
            subscription_expire=None,
            last_sync_error="provider timeout",
        )
        db.add(customer)
        db.flush()
        order = Order(
            order_code="RECOVER-ORDER",
            customer_id=customer.id,
            plan_id=plan.id,
            amount_toman=100_000,
            status="activated",
            gateway_json=json.dumps({"_bluevpn_target_expire": target.isoformat()}),
            activated_at=datetime.now(timezone.utc),
        )
        db.add(order)
        db.commit()

        result = repair_subscription_states(db)
        db.refresh(customer)
        assert result["repaired"] == 1
        assert customer.subscription_status == "active"
        assert customer.subscription_expire is not None
        assert customer.subscription_expire.replace(tzinfo=timezone.utc) > datetime.now(timezone.utc)


def test_startup_repair_does_not_revive_explicit_disabled_account():
    engine = create_engine("sqlite+pysqlite:///:memory:")
    Base.metadata.create_all(engine)
    with Session(engine) as db:
        customer = make_customer(
            email="disabled@example.com",
            subscription_status="disabled",
            last_sync_error="old timeout",
        )
        db.add(customer)
        db.commit()
        result = repair_subscription_states(db)
        db.refresh(customer)
        assert result["repaired"] == 0
        assert customer.subscription_status == "disabled"
