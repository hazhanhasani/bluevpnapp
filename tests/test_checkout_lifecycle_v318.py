from datetime import datetime, timedelta, timezone

from sqlalchemy import create_engine
from sqlalchemy.orm import Session

from server.database import Base
from server.main import (
    CHECKOUT_ABANDON_GRACE_SECONDS,
    computed_order_expiry,
    expire_stale_orders,
    mark_checkout_closed,
    mark_checkout_open,
    reusable_pending_order,
)
from server.models import Customer, Order, PasarGuardPanel, PaymentSetting, Plan


def seed(db: Session):
    payment = PaymentSetting(
        id=1,
        active=True,
        ttl_minutes=30,
        base_url="https://bluepay.example",
    )
    panel = PasarGuardPanel(
        name="panel",
        base_url="https://panel.example",
        auth_mode="api_key",
        active=True,
    )
    db.add_all([payment, panel])
    db.flush()
    customer = Customer(email="checkout@example.com", password_hash="x")
    plan = Plan(
        title="30 days",
        description="",
        price_toman=100_000,
        duration_days=30,
        data_limit_gb=10,
        device_limit=1,
        panel_id=panel.id,
    )
    db.add_all([customer, plan])
    db.commit()
    return payment, customer, plan


def make_order(db: Session, customer: Customer, plan: Plan, now: datetime) -> Order:
    order = Order(
        order_code="CHECKOUT-318",
        customer_id=customer.id,
        plan_id=plan.id,
        amount_toman=plan.price_toman,
        payment_id="pay-318",
        payment_url="https://pay.example/318",
        status="pending",
        created_at=now,
        expires_at=now + timedelta(minutes=30),
        checkout_opened_at=now,
        checkout_last_seen_at=now,
    )
    db.add(order)
    db.commit()
    return order


def test_open_checkout_keeps_full_thirty_minute_ttl():
    engine = create_engine("sqlite+pysqlite:///:memory:")
    Base.metadata.create_all(engine)
    now = datetime.now(timezone.utc)
    with Session(engine) as db:
        payment, customer, plan = seed(db)
        order = make_order(db, customer, plan, now)
        expiry = computed_order_expiry(order, payment, now=now + timedelta(minutes=10))
        assert expiry == now + timedelta(minutes=30)


def test_closing_checkout_reduces_effective_ttl_to_five_minutes():
    engine = create_engine("sqlite+pysqlite:///:memory:")
    Base.metadata.create_all(engine)
    now = datetime.now(timezone.utc)
    with Session(engine) as db:
        payment, customer, plan = seed(db)
        order = make_order(db, customer, plan, now)
        closed_at = now + timedelta(minutes=2)
        mark_checkout_closed(order, now=closed_at)
        db.commit()
        assert computed_order_expiry(order, payment, now=closed_at) == (
            closed_at + timedelta(seconds=CHECKOUT_ABANDON_GRACE_SECONDS)
        )


def test_cleanup_marks_closed_checkout_abandoned_after_grace():
    engine = create_engine("sqlite+pysqlite:///:memory:")
    Base.metadata.create_all(engine)
    now = datetime.now(timezone.utc)
    with Session(engine) as db:
        _, customer, plan = seed(db)
        order = make_order(db, customer, plan, now)
        mark_checkout_closed(order, now=now)
        db.commit()
        result = expire_stale_orders(
            db,
            now=now + timedelta(seconds=CHECKOUT_ABANDON_GRACE_SECONDS + 1),
        )
        order_id = order.id
        assert result["deleted"] == 1
        assert db.get(Order, order_id) is None


def test_reopen_before_grace_restores_original_hard_expiry():
    engine = create_engine("sqlite+pysqlite:///:memory:")
    Base.metadata.create_all(engine)
    now = datetime.now(timezone.utc)
    with Session(engine) as db:
        payment, customer, plan = seed(db)
        order = make_order(db, customer, plan, now)
        mark_checkout_closed(order, now=now + timedelta(minutes=1))
        mark_checkout_open(order, now=now + timedelta(minutes=3))
        db.commit()
        assert order.checkout_closed_at is None
        assert computed_order_expiry(order, payment, now=now + timedelta(minutes=3)) == (
            now + timedelta(minutes=30)
        )


def test_new_attempt_after_abandonment_does_not_reuse_old_invoice():
    engine = create_engine("sqlite+pysqlite:///:memory:")
    Base.metadata.create_all(engine)
    now = datetime.now(timezone.utc)
    with Session(engine) as db:
        payment, customer, plan = seed(db)
        order = make_order(db, customer, plan, now - timedelta(minutes=10))
        order.expires_at = now + timedelta(minutes=20)
        mark_checkout_closed(order, now=now - timedelta(minutes=6))
        db.commit()
        selected, in_progress = reusable_pending_order(db, customer, plan, payment)
        db.commit()
        order_id = order.id
        assert selected is None
        assert in_progress is False
        assert db.get(Order, order_id) is None
