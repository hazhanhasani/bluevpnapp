import asyncio
import json
from datetime import datetime, timedelta, timezone

from sqlalchemy import create_engine, event
from sqlalchemy.orm import Session

from server.database import Base
from server.main import (
    _looks_like_masked_secret,
    expire_stale_orders,
    reconcile_stale_bluepay_orders,
)
from server.models import (
    Customer,
    Order,
    PasarGuardPanel,
    PaymentSetting,
    Plan,
    SmsDelivery,
)


def engine_with_foreign_keys():
    engine = create_engine("sqlite+pysqlite:///:memory:")

    @event.listens_for(engine, "connect")
    def enable_foreign_keys(dbapi_connection, _):
        cursor = dbapi_connection.cursor()
        cursor.execute("PRAGMA foreign_keys=ON")
        cursor.close()

    Base.metadata.create_all(engine)
    return engine


def seed(db: Session):
    payment = PaymentSetting(
        id=1,
        active=True,
        ttl_minutes=30,
        base_url="https://bluepay.example",
        api_key_enc="encrypted-placeholder",
    )
    panel = PasarGuardPanel(
        name="panel",
        base_url="https://panel.example",
        auth_mode="api_key",
        active=True,
    )
    db.add_all([payment, panel])
    db.flush()
    customer = Customer(email="payment-v378@example.com", password_hash="x")
    plan = Plan(
        title="Premium",
        description="",
        price_toman=150_000,
        duration_days=30,
        data_limit_gb=0,
        device_limit=1,
        panel_id=panel.id,
    )
    db.add_all([customer, plan])
    db.commit()
    return payment, customer, plan


def test_cleanup_preserves_order_referenced_by_sms_delivery():
    engine = engine_with_foreign_keys()
    with Session(engine) as db:
        _, customer, plan = seed(db)
        order = Order(
            order_code="PAY-378-FK",
            customer_id=customer.id,
            plan_id=plan.id,
            amount_toman=plan.price_toman,
            payment_id="payment-378",
            payment_url="https://pay.example/payment-378",
            status="pending",
            created_at=datetime.now(timezone.utc) - timedelta(hours=2),
            expires_at=datetime.now(timezone.utc) - timedelta(hours=1),
        )
        db.add(order)
        db.commit()
        db.add(
            SmsDelivery(
                event_key="invoice_created",
                customer_id=customer.id,
                order_id=order.id,
                phone="989121234567",
                params_json="{}",
                dedupe_key="pay-378-fk",
                status="sent",
            )
        )
        db.commit()

        result = expire_stale_orders(db)
        preserved = db.get(Order, order.id)
        assert result["archived"] == 1
        assert preserved is not None
        assert preserved.status == "expired_local"
        metadata = json.loads(preserved.gateway_json)
        assert metadata["_bluevpn_archived_at"]
        assert db.query(SmsDelivery).filter_by(order_id=order.id).count() == 1


def test_masked_secrets_are_never_saved_as_real_credentials():
    assert _looks_like_masked_secret("gw_4-••••fJPc") is True
    assert _looks_like_masked_secret("CaDAH••••4z0o") is True
    assert _looks_like_masked_secret("gw_real_private_key_123456") is False


def test_cleanup_reconciles_late_paid_invoice(monkeypatch):
    engine = engine_with_foreign_keys()
    with Session(engine) as db:
        _, customer, plan = seed(db)
        order = Order(
            order_code="PAY-378-LATE",
            customer_id=customer.id,
            plan_id=plan.id,
            amount_toman=plan.price_toman,
            payment_id="late-payment-378",
            payment_url="https://pay.example/late-payment-378",
            status="expired_local",
            created_at=datetime.now(timezone.utc) - timedelta(hours=1),
            expires_at=datetime.now(timezone.utc) - timedelta(minutes=30),
            gateway_json=json.dumps({"_bluevpn_archived_at": "2026-08-10T00:00:00Z"}),
        )
        db.add(order)
        db.commit()

        async def fake_get_invoice(_payment, _payment_id):
            return {
                "payment_id": "late-payment-378",
                "status": "paid",
                "amount_toman": plan.price_toman,
            }

        async def fake_activate(_db, target):
            target.status = "activated"
            _db.commit()
            return target

        monkeypatch.setattr("server.main.get_invoice", fake_get_invoice)
        monkeypatch.setattr("server.main.activate", fake_activate)

        result = asyncio.run(reconcile_stale_bluepay_orders(db))
        db.refresh(order)
        assert result["recovered_paid"] == 1
        assert order.status == "activated"
        assert "_bluevpn_archived_at" not in json.loads(order.gateway_json)


def test_admin_bluepay_has_sandbox_test_and_safe_cleanup_contract():
    from pathlib import Path

    root = Path(__file__).resolve().parents[1]
    main = (root / "server/main.py").read_text(encoding="utf-8")
    template = (root / "server/templates/admin.html").read_text(encoding="utf-8")
    integrations = (root / "server/integrations.py").read_text(encoding="utf-8")

    assert "async def admin_bluepay_cleanup" in main
    assert "reconcile_stale_bluepay_orders" in main
    assert "admin_cleanup_failed" in main
    assert "/admin/bluepay/test" in template
    assert "/api/v1/sandbox/invoices" in integrations
    assert "سوابق حذف نمی‌شوند" in template
