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


def test_cleanup_expires_but_does_not_delete_old_pending_order():
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

        result = expire_stale_orders(db)
        db.refresh(old)
        assert result["expired"] == 1
        assert old.status == "expired_local"
        assert old.expires_at is not None
        assert db.scalar(select(func.count(Order.id))) == 1


def test_reuses_newest_valid_invoice_and_supersedes_duplicate():
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

        selected, in_progress = reusable_pending_order(db, customer, plan, payment)
        db.commit()
        db.refresh(older)
        assert selected is not None
        assert selected.id == newest.id
        assert in_progress is False
        assert older.status == "superseded"
        assert newest.status == "pending"


def test_late_paid_status_revives_locally_expired_order(monkeypatch):
    engine = create_engine("sqlite+pysqlite:///:memory:")
    Base.metadata.create_all(engine)
    with Session(engine) as db:
        _, customer, plan = seed(db)
        order = Order(
            order_code="LATE-PAID",
            customer_id=customer.id,
            plan_id=plan.id,
            amount_toman=plan.price_toman,
            payment_id="late-payment",
            payment_url="https://pay.example/late",
            status="expired_local",
            created_at=datetime.now(timezone.utc) - timedelta(hours=2),
            expires_at=datetime.now(timezone.utc) - timedelta(hours=1),
        )
        db.add(order)
        db.commit()

        async def fake_get_invoice(_payment, _payment_id):
            return {"status": "paid", "amount_toman": plan.price_toman}

        async def fake_activate(_db, current):
            current.status = "activated"
            current.activated_at = datetime.now(timezone.utc)
            _db.commit()

        monkeypatch.setattr("server.main.get_invoice", fake_get_invoice)
        monkeypatch.setattr("server.main.activate", fake_activate)
        asyncio.run(refresh_order_from_bluepay(db, order))
        db.refresh(order)
        assert order.status == "activated"
        assert order.paid_at is not None
