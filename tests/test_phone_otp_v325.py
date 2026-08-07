import asyncio
import json
from datetime import timedelta
from pathlib import Path

import httpx
import pytest
from sqlalchemy import create_engine, inspect, select
from sqlalchemy.orm import Session

from server.database import Base
from server.main import _consume_otp, _otp_digest_input, account_json
from server.models import Customer, OtpChallenge, SmsSetting
from server.security import encrypt, password_hash
from server.sms import local_phone, normalize_iran_phone, send_pattern_otp
from server.security import utcnow


@pytest.mark.parametrize(
    ("raw", "expected"),
    [
        ("09123456789", "+989123456789"),
        ("9123456789", "+989123456789"),
        ("989123456789", "+989123456789"),
        ("00989123456789", "+989123456789"),
        ("۰۹۱۲۳۴۵۶۷۸۹", "+989123456789"),
        ("+98 912 345 6789", "+989123456789"),
    ],
)
def test_iran_phone_normalization(raw, expected):
    assert normalize_iran_phone(raw) == expected
    assert local_phone(expected) == "09123456789"


@pytest.mark.parametrize("raw", ["", "02112345678", "091234", "+12025550123"])
def test_invalid_phone_is_rejected(raw):
    with pytest.raises(ValueError):
        normalize_iran_phone(raw)


def test_account_payload_uses_verified_phone_as_public_identity():
    customer = Customer(
        email="phone-989123456789@users.bluevpn.local",
        password_hash="x",
        phone="+989123456789",
        phone_verified_at=utcnow(),
        auth_method="phone_otp",
    )
    payload = account_json(customer)
    assert payload["phone"] == "+989123456789"
    assert payload["phone_display"] == "09123456789"
    assert payload["display_identity"] == "09123456789"
    assert payload["phone_verified"] is True
    assert payload["auth_method"] == "phone_otp"


def test_valid_otp_is_device_bound_and_consumed():
    engine = create_engine("sqlite+pysqlite:///:memory:")
    Base.metadata.create_all(engine)
    now = utcnow()
    with Session(engine) as db:
        challenge = OtpChallenge(
            id="otp-v325",
            phone="+989123456789",
            purpose="auth",
            device_id="device-v325",
            code_hash=password_hash(
                _otp_digest_input("otp-v325", "+989123456789", "54321")
            ),
            max_attempts=5,
            expires_at=now + timedelta(minutes=2),
        )
        db.add(challenge)
        db.commit()

        consumed, phone = _consume_otp(
            db,
            phone_raw="09123456789",
            challenge_id="otp-v325",
            code="۵۴۳۲۱",
            device_id="device-v325",
            purpose="auth",
        )
        db.commit()
        assert phone == "+989123456789"
        assert consumed.consumed_at is not None
        assert consumed.attempts == 1


def test_wrong_otp_increments_attempts():
    engine = create_engine("sqlite+pysqlite:///:memory:")
    Base.metadata.create_all(engine)
    with Session(engine) as db:
        db.add(
            OtpChallenge(
                id="otp-wrong-v325",
                phone="+989123456788",
                purpose="auth",
                device_id="device-v325",
                code_hash=password_hash(
                    _otp_digest_input(
                        "otp-wrong-v325", "+989123456788", "12345"
                    )
                ),
                max_attempts=5,
                expires_at=utcnow() + timedelta(minutes=2),
            )
        )
        db.commit()
        with pytest.raises(Exception) as error:
            _consume_otp(
                db,
                phone_raw="09123456788",
                challenge_id="otp-wrong-v325",
                code="99999",
                device_id="device-v325",
                purpose="auth",
            )
        assert getattr(error.value, "status_code", None) == 401
        saved = db.get(OtpChallenge, "otp-wrong-v325")
        assert saved.attempts == 1
        assert saved.consumed_at is None


def test_faraz_pattern_payload_matches_ippanel_edge_contract(monkeypatch):
    captured = {}

    class FakeResponse:
        status_code = 200
        text = ""

        def json(self):
            return {
                "data": {"message_outbox_ids": [123]},
                "meta": {"status": True, "message": "انجام شد"},
            }

    class FakeClient:
        def __init__(self, **kwargs):
            captured["client_kwargs"] = kwargs

        async def __aenter__(self):
            return self

        async def __aexit__(self, exc_type, exc, tb):
            return False

        async def post(self, endpoint, json=None, headers=None):
            captured["endpoint"] = endpoint
            captured["json"] = json
            captured["headers"] = headers
            return FakeResponse()

    monkeypatch.setattr(httpx, "AsyncClient", FakeClient)
    setting = SmsSetting(
        id=1,
        active=True,
        base_url="https://edge.ippanel.com/v1",
        api_key_enc=encrypt("test-api-key"),
        from_number="983000505",
        pattern_code="pattern-code-v325",
        parameter_name="verification-code",
        verify_tls=True,
    )
    result = asyncio.run(send_pattern_otp(setting, "09123456789", "54321"))
    assert result["meta"]["status"] is True
    assert captured["endpoint"] == "https://edge.ippanel.com/v1/api/send"
    assert captured["headers"]["Authorization"] == "test-api-key"
    assert captured["json"] == {
        "sending_type": "pattern",
        "from_number": "+983000505",
        "code": "pattern-code-v325",
        "recipients": ["+989123456789"],
        "params": {"verification-code": "54321"},
    }


def test_schema_contains_phone_otp_tables_and_columns():
    engine = create_engine("sqlite+pysqlite:///:memory:")
    Base.metadata.create_all(engine)
    inspector = inspect(engine)
    customer_columns = {item["name"] for item in inspector.get_columns("customers")}
    assert {"phone", "phone_verified_at", "auth_method"} <= customer_columns
    assert inspector.has_table("otp_challenges")
    assert inspector.has_table("sms_settings")


def test_android_login_screen_has_phone_otp_only():
    root = Path(__file__).resolve().parents[1]
    manager = (root / "android-source" / "BlueVpnAccountManager.kt").read_text()
    screen = (root / "android-source" / "BlueVpnSubscriptionsActivity.kt").read_text()
    assert "/api/v1/auth/otp/request" in manager
    assert "/api/v1/auth/otp/verify" in manager
    assert "/api/v1/account/phone/otp/request" in manager
    assert "شماره تماس" in screen
    assert "کد پیامکی" in screen
    assert "TYPE_TEXT_VARIATION_EMAIL_ADDRESS" not in screen
    assert "TYPE_TEXT_VARIATION_PASSWORD" not in screen


def test_admin_contains_faraz_sms_configuration():
    root = Path(__file__).resolve().parents[1]
    template = (root / "server" / "templates" / "admin.html").read_text()
    assert 'id="sms"' in template
    assert 'name="pattern_code"' in template
    assert 'name="from_number"' in template
    assert 'name="parameter_name"' in template
    assert "فراز اس‌ام‌اس" in template


def test_existing_sqlite_schema_is_upgraded_to_v12(tmp_path):
    import os
    import sqlite3
    import subprocess
    import sys

    data_dir = tmp_path / "data"
    data_dir.mkdir()
    db_path = data_dir / "bluevpn.db"
    connection = sqlite3.connect(db_path)
    connection.execute(
        "CREATE TABLE customers ("
        "id INTEGER PRIMARY KEY,"
        "email VARCHAR(255) UNIQUE NOT NULL,"
        "password_hash TEXT NOT NULL"
        ")"
    )
    connection.execute(
        "INSERT INTO customers(id,email,password_hash) VALUES (1,?,?)",
        ("legacy@example.com", "legacy-hash"),
    )
    connection.commit()
    connection.close()

    env = os.environ.copy()
    for name in list(env):
        if name.startswith("RAILWAY_") or name in {
            "DATABASE_URL",
            "DATABASE_PRIVATE_URL",
            "PGHOST",
            "PGPORT",
            "PGUSER",
            "PGPASSWORD",
            "PGDATABASE",
        }:
            env.pop(name, None)
    env.update(
        {
            "DATA_DIR": str(data_dir),
            "DB_REQUIRE_POSTGRES": "false",
            "ALLOW_SQLITE_FALLBACK": "true",
        }
    )
    code = """
from sqlalchemy import inspect, text
from server.database import ENGINE, initialize_database
initialize_database(force=True)
columns={x['name'] for x in inspect(ENGINE).get_columns('customers')}
assert {'phone','phone_verified_at','auth_method'} <= columns
assert inspect(ENGINE).has_table('otp_challenges')
assert inspect(ENGINE).has_table('sms_settings')
with ENGINE.connect() as c:
    assert c.scalar(text("SELECT value FROM bluevpn_schema_meta WHERE key='schema_version'")) == '13'
    assert c.scalar(text("SELECT email FROM customers WHERE id=1")) == 'legacy@example.com'
"""
    result = subprocess.run(
        [sys.executable, "-c", code],
        cwd=Path(__file__).resolve().parents[1],
        env=env,
        capture_output=True,
        text=True,
        timeout=60,
    )
    assert result.returncode == 0, result.stdout + result.stderr


def test_phone_otp_api_creates_and_logs_in_account(monkeypatch):
    from fastapi.testclient import TestClient
    from sqlalchemy.pool import StaticPool

    import server.main as main_module
    from server.database import get_db

    engine = create_engine(
        "sqlite+pysqlite:///:memory:",
        connect_args={"check_same_thread": False},
        poolclass=StaticPool,
    )
    Base.metadata.create_all(engine)
    with Session(engine) as db:
        db.add(
            SmsSetting(
                id=1,
                active=True,
                api_key_enc=encrypt("api-key"),
                from_number="+983000505",
                pattern_code="pattern-v325",
                parameter_name="code",
                otp_length=5,
                otp_ttl_seconds=120,
                resend_seconds=60,
            )
        )
        db.commit()

    def override_db():
        with Session(engine) as db:
            yield db

    sent = {}

    async def fake_send(setting, phone, code):
        sent.update({"phone": phone, "code": code})
        return {"meta": {"status": True}}

    monkeypatch.setattr(main_module, "_otp_code", lambda _: "54321")
    monkeypatch.setattr(main_module, "send_pattern_otp", fake_send)
    main_module.app.dependency_overrides[get_db] = override_db
    try:
        client = TestClient(main_module.app)
        requested = client.post(
            "/api/v1/auth/otp/request",
            json={"phone": "09120000001", "device_id": "phone-api-v325"},
        )
        assert requested.status_code == 200, requested.text
        challenge_id = requested.json()["challenge_id"]
        assert sent == {"phone": "+989120000001", "code": "54321"}

        verified = client.post(
            "/api/v1/auth/otp/verify",
            json={
                "phone": "09120000001",
                "challenge_id": challenge_id,
                "code": "54321",
                "device_id": "phone-api-v325",
                "device_name": "test phone",
            },
        )
        assert verified.status_code == 200, verified.text
        payload = verified.json()
        assert payload["is_new_account"] is True
        assert payload["token"]
        assert payload["refresh_token"]
        assert payload["account"]["phone_display"] == "09120000001"
        assert payload["account"]["email"] == ""

        with Session(engine) as db:
            customer = db.scalar(
                select(Customer).where(Customer.phone == "+989120000001")
            )
            assert customer is not None
            assert customer.phone_verified_at is not None
            assert customer.auth_method == "phone_otp"
    finally:
        main_module.app.dependency_overrides.pop(get_db, None)
