import asyncio
from datetime import timedelta
from types import SimpleNamespace

import pytest
from fastapi import HTTPException
from sqlalchemy import create_engine
from sqlalchemy.orm import Session

from server.database import Base
from server.main import (
    _apply_global_guardcore_to_customers,
    account_json,
    login_with_email,
    register_with_email,
)
from server.manual_guardcore import manual_snapshot, resolve_manual_panel
from server.models import Customer, GuardCorePanel, PasarGuardPanel, Plan
from server.security import utcnow


class JsonRequest:
    def __init__(self, payload: dict, host: str = "127.0.0.35"):
        self.payload = payload
        self.headers = {}
        self.client = SimpleNamespace(host=host)

    async def json(self):
        return self.payload


def test_email_registration_and_login_work_next_to_sms_auth():
    engine = create_engine("sqlite+pysqlite:///:memory:")
    Base.metadata.create_all(engine)
    email = "email-v335@example.com"
    password = "StrongPass-335"

    with Session(engine) as db:
        registered = asyncio.run(
            register_with_email(
                JsonRequest(
                    {
                        "email": email,
                        "password": password,
                        "device_id": "device-email-v335",
                        "device_name": "Android test",
                    }
                ),
                db,
            )
        )
        assert registered["success"] is True
        assert registered["is_new_account"] is True
        assert registered["token"]
        assert registered["refresh_token"]
        assert registered["account"]["email"] == email
        assert registered["account"]["auth_method"] == "email_password"

        logged_in = asyncio.run(
            login_with_email(
                JsonRequest(
                    {
                        "email": email.upper(),
                        "password": password,
                        "device_id": "device-email-v335",
                        "device_name": "Android test",
                    },
                    host="127.0.0.36",
                ),
                db,
            )
        )
        assert logged_in["success"] is True
        assert logged_in["is_new_account"] is False
        assert logged_in["account"]["display_identity"] == email

        with pytest.raises(HTTPException) as exc:
            asyncio.run(
                login_with_email(
                    JsonRequest(
                        {
                            "email": email,
                            "password": "wrong-password",
                            "device_id": "device-email-v335",
                        },
                        host="127.0.0.37",
                    ),
                    db,
                )
            )
        assert exc.value.status_code == 401
        assert exc.value.detail["code"] == "INVALID_CREDENTIALS"


def test_global_third_panel_applies_to_old_users_without_granting_inactive_access():
    engine = create_engine("sqlite+pysqlite:///:memory:")
    Base.metadata.create_all(engine)
    now = utcnow()

    with Session(engine) as db:
        primary = PasarGuardPanel(
            name="primary",
            base_url="https://pg.example",
            active=True,
        )
        db.add(primary)
        db.flush()
        plan = Plan(
            title="30 days",
            price_toman=100_000,
            duration_days=30,
            data_limit_gb=100,
            device_limit=1,
            panel_id=primary.id,
        )
        db.add(plan)
        db.flush()

        active_customer = Customer(
            email="active-v335@example.com",
            password_hash="x",
            plan_id=plan.id,
            subscription_url="https://bluevpn.example/sub/active",
            subscription_status="active",
            subscription_expire=now + timedelta(days=5),
            pasarguard_subscription_url="https://pg.example/sub/active",
        )
        inactive_customer = Customer(
            email="inactive-v335@example.com",
            password_hash="x",
            subscription_url="https://bluevpn.example/sub/inactive",
            subscription_status="inactive",
        )
        legacy_empty = GuardCorePanel(
            name="legacy-empty",
            base_url="https://legacy.example",
            auth_mode="manual",
            active=True,
        )
        global_panel = GuardCorePanel(
            name="global-third",
            base_url="https://third.example",
            global_subscription_url="https://third.example/sub/global",
            auth_mode="manual",
            active=True,
        )
        db.add_all([active_customer, inactive_customer, legacy_empty, global_panel])
        db.flush()

        assert resolve_manual_panel(db, plan).id == global_panel.id
        updated = _apply_global_guardcore_to_customers(db, global_panel)
        db.commit()
        assert updated == 2

        db.refresh(active_customer)
        db.refresh(inactive_customer)
        assert active_customer.guardcore_subscription_url == global_panel.global_subscription_url
        assert inactive_customer.guardcore_subscription_url == global_panel.global_subscription_url
        assert active_customer.guardcore_status == "active"
        assert inactive_customer.guardcore_status == "inactive"

        active_snapshot = manual_snapshot(active_customer, global_panel)
        inactive_snapshot = manual_snapshot(inactive_customer, global_panel)
        assert active_snapshot["status"] == "active"
        assert inactive_snapshot["status"] == "inactive"
        assert account_json(active_customer, db)["subscription"]["active"] is True
        assert account_json(inactive_customer, db)["subscription"]["active"] is False


def test_android_and_admin_expose_both_auth_modes_and_global_link():
    manager = open("android-source/BlueVpnAccountManager.kt", encoding="utf-8").read()
    screen = open("android-source/BlueVpnSubscriptionsActivity.kt", encoding="utf-8").read()
    admin = open("server/templates/admin.html", encoding="utf-8").read()
    main = open("server/main.py", encoding="utf-8").read()

    assert "authenticateWithEmail" in manager
    assert "/api/v1/auth/register" in manager
    assert "/api/v1/auth/login" in manager
    assert 'authMode=="sms"' in screen
    assert 'authMode=="email"' in screen
    assert "global_subscription_url" in admin
    assert "ذخیره و اعمال برای همه" in admin
    assert "Subscription inactive or expired" in main
