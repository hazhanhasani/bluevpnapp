import asyncio
from datetime import datetime, timedelta, timezone

from sqlalchemy import create_engine, func, select
from sqlalchemy.orm import Session

from server.database import Base
from server.main import (
    expire_stale_orders,
    refresh_order_from_bluepay,
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
    customer = Customer(email="pending@example.com", password_hash="x")
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


def test_cleanup_archives_old_pending_order():
    engine = create_engine("sqlite+pysqlite:///:memory:")
    Base.metadata.create_all(engine)
    with Session(engine) as db:
        _, customer, plan = seed(db)
        old = Order(
            order_code="OLD-PENDING",
            customer_id=customer.id,
            plan_id=plan.id,
            amount_toman=plan.price_toman,
            payment_id="pay-old",
            payment_url="https://pay.example/old",
            status="pending",
            created_at=datetime.now(timezone.utc) - timedelta(hours=2),
        )
        db.add(old)
        db.commit()

        old_id = old.id
        result = expire_stale_orders(db)
        assert result["archived"] == 1
        preserved = db.get(Order, old_id)
        assert preserved is not None
        assert preserved.status == "expired_local"
        assert db.scalar(select(func.count(Order.id))) == 1


def test_reuses_newest_valid_invoice_and_archives_duplicate():
    engine = create_engine("sqlite+pysqlite:///:memory:")
    Base.metadata.create_all(engine)
    now = datetime.now(timezone.utc)
    with Session(engine) as db:
        payment, customer, plan = seed(db)
        older = Order(
            order_code="PENDING-1",
            customer_id=customer.id,
            plan_id=plan.id,
            amount_toman=plan.price_toman,
            payment_id="pay-1",
            payment_url="https://pay.example/1",
            status="pending",
            created_at=now - timedelta(minutes=10),
            expires_at=now + timedelta(minutes=20),
        )
        newest = Order(
            order_code="PENDING-2",
            customer_id=customer.id,
            plan_id=plan.id,
            amount_toman=plan.price_toman,
            payment_id="pay-2",
            payment_url="https://pay.example/2",
            status="pending",
            created_at=now - timedelta(minutes=2),
            expires_at=now + timedelta(minutes=28),
        )
        db.add_all([older, newest])
        db.commit()

        older_id = older.id
        selected, in_progress = reusable_pending_order(db, customer, plan, payment)
        db.commit()
        assert selected is not None
        assert selected.id == newest.id
        assert in_progress is False
        preserved = db.get(Order, older_id)
        assert preserved is not None
        assert preserved.status == "superseded"
        assert newest.status == "pending"


def test_cleanup_archives_terminal_invalid_order():
    engine = create_engine("sqlite+pysqlite:///:memory:")
    Base.metadata.create_all(engine)
    with Session(engine) as db:
        _, customer, plan = seed(db)
        order = Order(
            order_code="TERMINAL-INVALID",
            customer_id=customer.id,
            plan_id=plan.id,
            amount_toman=plan.price_toman,
            payment_id="invalid-payment",
            payment_url="https://pay.example/invalid",
            status="expired_local",
            created_at=datetime.now(timezone.utc) - timedelta(hours=2),
            expires_at=datetime.now(timezone.utc) - timedelta(hours=1),
        )
        db.add(order)
        db.commit()
        order_id = order.id
        result = expire_stale_orders(db)
        assert result["archived"] == 1
        preserved = db.get(Order, order_id)
        assert preserved is not None
        assert preserved.status == "expired_local"
