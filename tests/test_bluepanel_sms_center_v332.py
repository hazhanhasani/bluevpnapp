from __future__ import annotations

import json
from pathlib import Path

from sqlalchemy import create_engine, select
from sqlalchemy.orm import Session
from sqlalchemy.pool import StaticPool

from server.database import Base, SCHEMA_VERSION
from server.models import Customer, SmsDelivery, SmsSetting, SmsTemplate
from server.security import encrypt, password_hash
from server.sms import queue_sms_event, seed_sms_templates
from server.sms_catalog import SMS_TEMPLATE_MAP, SMS_TEMPLATE_SPECS

ROOT = Path(__file__).resolve().parents[1]


def memory_engine():
    engine = create_engine(
        "sqlite+pysqlite:///:memory:",
        connect_args={"check_same_thread": False},
        poolclass=StaticPool,
    )
    Base.metadata.create_all(engine)
    return engine


def test_release_and_schema_v332():
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    assert release["version"] == "3.0.36"
    assert release["version_code"] == 30036
    assert app["version_name"] == "3.0.36"
    assert app["version_code"] == 30036
    assert SCHEMA_VERSION == "14"


def test_catalog_contains_exactly_38_bluepanel_patterns():
    assert len(SMS_TEMPLATE_SPECS) == 38
    assert len(SMS_TEMPLATE_MAP) == 38
    assert all("BlueVPN" not in item.body and "بلو وی پی ان" not in item.body for item in SMS_TEMPLATE_SPECS)
    assert SMS_TEMPLATE_MAP["auth_otp"].variables[0].name == "code"
    assert SMS_TEMPLATE_MAP["payment_success"].variables[0].kind == "عددی"
    assert SMS_TEMPLATE_MAP["subscription_activated"].variables[1].length == 10


def test_seed_preserves_admin_pattern_codes():
    engine = memory_engine()
    with Session(engine) as db:
        db.add(
            SmsSetting(
                id=1,
                active=True,
                notification_active=True,
                api_key_enc=encrypt("key"),
                from_number="+983000505",
                pattern_code="legacy-auth-code",
                parameter_name="code",
            )
        )
        db.commit()
        assert seed_sms_templates(db) >= 38
        assert len(db.scalars(select(SmsTemplate)).all()) == 38
        assert db.scalar(select(SmsTemplate).where(SmsTemplate.key == "auth_otp")).pattern_code == "legacy-auth-code"
        row = db.get(SmsTemplate, "payment_success")
        row.pattern_code = "pay-pattern"
        row.enabled = True
        db.commit()
        seed_sms_templates(db)
        row = db.get(SmsTemplate, "payment_success")
        assert row.pattern_code == "pay-pattern"
        assert row.enabled is True


def test_queue_validates_params_and_deduplicates():
    engine = memory_engine()
    with Session(engine) as db:
        db.add(
            SmsSetting(
                id=1,
                active=True,
                notification_active=True,
                api_key_enc=encrypt("key"),
                from_number="+983000505",
                pattern_code="auth-code",
                parameter_name="code",
            )
        )
        customer = Customer(
            email="phone-989120000001@users.bluevpn.local",
            password_hash=password_hash("unused"),
            phone="+989120000001",
        )
        db.add(customer)
        db.commit()
        seed_sms_templates(db)
        template = db.get(SmsTemplate, "payment_success")
        template.pattern_code = "pay-pattern"
        template.enabled = True
        db.commit()

        first = queue_sms_event(
            db,
            "payment_success",
            customer.phone,
            {"amount": "250,000", "invoice_id": "BV-123"},
            customer_id=customer.id,
            dedupe_seed="same-event",
        )
        second = queue_sms_event(
            db,
            "payment_success",
            customer.phone,
            {"amount": "250,000", "invoice_id": "BV-123"},
            customer_id=customer.id,
            dedupe_seed="same-event",
        )
        assert first is not None
        assert second is None
        delivery = db.get(SmsDelivery, first.id)
        assert json.loads(delivery.params_json) == {"amount": "250000", "invoice_id": "BV-123"}


def test_admin_contains_pattern_broadcast_and_delivery_centers():
    template = (ROOT / "server/templates/admin.html").read_text(encoding="utf-8")
    main = (ROOT / "server/main.py").read_text(encoding="utf-8")
    assert 'id="sms-templates"' in template
    assert 'id="sms-broadcast"' in template
    assert 'id="sms-deliveries"' in template
    assert "ذخیره همه ۳۸ پترن" in template
    assert "@app.post('/admin/sms-templates')" in main
    assert "@app.post('/admin/sms/broadcast')" in main
    assert "@app.post('/api/v1/internal/sms/events')" in main


def test_event_hooks_cover_project_lifecycle():
    main = (ROOT / "server/main.py").read_text(encoding="utf-8")
    runtime = (ROOT / "server/sms_runtime.py").read_text(encoding="utf-8")
    for event in (
        "welcome",
        "invoice_created",
        "invoice_expired",
        "payment_success",
        "payment_failed",
        "subscription_activated",
        "subscription_renewed",
        "subscription_upgraded",
        "subscription_plan_changed",
        "new_device_login",
        "suspicious_login",
        "phone_changed",
        "device_removed",
        "account_temporarily_blocked",
        "account_unblocked",
    ):
        assert f"'{event}'" in main
    for event in (
        "subscription_reminder",
        "subscription_expired",
        "low_remaining_volume",
        "volume_expired",
    ):
        assert f'"{event}"' in runtime
