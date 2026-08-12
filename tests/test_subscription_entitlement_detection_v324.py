from datetime import datetime, timedelta, timezone
from pathlib import Path

from sqlalchemy import create_engine
from sqlalchemy.orm import Session

from server.database import Base
from server.main import account_json
from server.models import Customer, Order, PasarGuardPanel, Plan


def _fixture(db: Session, *, status: str = "inactive"):
    panel = PasarGuardPanel(
        name="primary",
        base_url="https://panel.example",
        auth_mode="api_key",
        active=True,
    )
    db.add(panel)
    db.flush()
    plan = Plan(
        title="30 days unlimited volume",
        description="",
        price_toman=150_000,
        duration_days=30,
        data_limit_gb=0,
        device_limit=1,
        panel_id=panel.id,
        active=True,
        deleted=False,
    )
    db.add(plan)
    db.flush()
    now = datetime.now(timezone.utc).replace(microsecond=0)
    customer = Customer(
        email="active-but-cached@example.com",
        password_hash="x",
        active=True,
        plan_id=plan.id,
        subscription_status=status,
        subscription_url="https://bluevpn.example/sub/token",
        pasarguard_subscription_url="https://panel.example/sub/user",
        subscription_expire=now + timedelta(minutes=5),
        data_limit_bytes=0,
        used_traffic_bytes=0,
    )
    db.add(customer)
    db.flush()
    order = Order(
        order_code="ENTITLEMENT-324",
        customer_id=customer.id,
        plan_id=plan.id,
        amount_toman=150_000,
        status="activated",
        gateway_json="{}",
        paid_at=now,
        activated_at=now,
    )
    db.add(order)
    db.commit()
    return customer, plan, order, now


def test_latest_activated_order_recovers_active_account_payload():
    engine = create_engine("sqlite+pysqlite:///:memory:")
    Base.metadata.create_all(engine)
    with Session(engine) as db:
        customer, _, order, now = _fixture(db)
        payload = account_json(customer, db)["subscription"]
        assert payload["active"] is True
        assert payload["status"] == "active"
        assert payload["active_reason"] == "activated_order"
        assert payload["entitlement_active"] is True
        assert payload["entitlement_order_id"] == order.id
        assert payload["remaining_seconds"] >= int(timedelta(days=29, hours=23).total_seconds())
        assert payload["expire"].endswith("Z")
        assert payload["expires_at_fa"]


def test_explicit_disabled_state_is_not_revived_by_activated_order():
    engine = create_engine("sqlite+pysqlite:///:memory:")
    Base.metadata.create_all(engine)
    with Session(engine) as db:
        customer, _, order, _ = _fixture(db, status="disabled")
        payload = account_json(customer, db)["subscription"]
        assert payload["active"] is False
        assert payload["status"] == "disabled"
        assert payload["entitlement_active"] is True
        assert payload["entitlement_order_id"] == order.id


def test_android_inactive_cache_is_forced_to_refresh_after_update_and_resume():
    root = Path(__file__).resolve().parents[1]
    manager = (root / "android-source" / "BlueVpnAccountManager.kt").read_text()
    home = (root / "android-source" / "BlueVpnHomeActivity.kt").read_text()
    subscriptions = (
        root / "android-source" / "BlueVpnSubscriptionsActivity.kt"
    ).read_text()

    assert "!local.subscriptionActive" in manager
    assert "accountCacheVersion(c) != currentAppVersion()" in manager
    assert 'subscription.optBoolean("entitlement_active", false)' in manager
    assert "if (terminalStatus) return false" in manager
    assert "syncManagedAccount(force = true)" in home
    assert "if(returnedOrder.isBlank()&&BlueVpnAccountManager.hasSession(this))" in subscriptions
    assert "sync(true)" in subscriptions
