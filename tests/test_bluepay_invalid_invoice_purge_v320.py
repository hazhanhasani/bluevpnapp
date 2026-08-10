import asyncio
from datetime import datetime, timedelta, timezone

from sqlalchemy import create_engine
from sqlalchemy.orm import Session

from server.database import Base
from server.main import _validate_reusable_invoice
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
    customer = Customer(email="purge@example.com", password_hash="x")
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


def make_order(db: Session, customer: Customer, plan: Plan) -> Order:
    now = datetime.now(timezone.utc)
    order = Order(
        order_code="PURGE-320",
        customer_id=customer.id,
        plan_id=plan.id,
        amount_toman=plan.price_toman,
        payment_id="pay-old-320",
        payment_url="https://pay.example/old-320",
        status="pending",
        created_at=now,
        expires_at=now + timedelta(minutes=30),
    )
    db.add(order)
    db.commit()
    return order


def test_remote_expired_invoice_is_hard_deleted(monkeypatch):
    engine = create_engine("sqlite+pysqlite:///:memory:")
    Base.metadata.create_all(engine)
    with Session(engine) as db:
        payment, customer, plan = seed(db)
        order = make_order(db, customer, plan)
        order_id = order.id

        async def fake_get_invoice(_payment, _payment_id):
            return {
                "status": "expired",
                "amount_toman": plan.price_toman,
                "payment_id": "pay-old-320",
                "payment_url": "https://pay.example/old-320",
            }

        monkeypatch.setattr("server.main.get_invoice", fake_get_invoice)
        state = asyncio.run(_validate_reusable_invoice(db, order, payment))
        assert state == "invalid"
        assert db.get(Order, order_id) is None


def test_remote_pending_invoice_with_future_expiry_can_be_reused(monkeypatch):
    engine = create_engine("sqlite+pysqlite:///:memory:")
    Base.metadata.create_all(engine)
    with Session(engine) as db:
        payment, customer, plan = seed(db)
        order = make_order(db, customer, plan)
        future = datetime.now(timezone.utc) + timedelta(minutes=20)

        async def fake_get_invoice(_payment, _payment_id):
            return {
                "status": "pending",
                "amount_toman": plan.price_toman,
                "payment_id": "pay-old-320",
                "payment_url": "https://pay.example/current-320",
                "expires_at": future.isoformat().replace("+00:00", "Z"),
            }

        monkeypatch.setattr("server.main.get_invoice", fake_get_invoice)
        state = asyncio.run(_validate_reusable_invoice(db, order, payment))
        assert state == "usable"
        db.refresh(order)
        assert order.payment_url == "https://pay.example/current-320"
        assert order.status == "pending"
