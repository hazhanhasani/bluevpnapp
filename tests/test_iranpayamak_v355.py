from __future__ import annotations

import asyncio
import json
from pathlib import Path

import httpx
from sqlalchemy import create_engine
from sqlalchemy.orm import Session

from server.database import Base, SCHEMA_VERSION
from server.models import SmsSetting
from server.security import encrypt
from server.sms import (
    IRANPAYAMAK_DEFAULT_BASE_URL,
    _line_number,
    migrate_iranpayamak_settings,
    send_pattern,
    sms_notification_ready,
    sms_setting_ready,
)

ROOT = Path(__file__).resolve().parents[1]


def test_release_version_355_and_schema_18():
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    assert release["version"] == "3.0.74"
    assert release["version_code"] == 30074
    assert app["version_name"] == "3.0.74"
    assert app["version_code"] == 30074
    assert SCHEMA_VERSION == "18"


def test_shared_line_requires_real_line_number():
    setting = SmsSetting(
        id=1,
        provider="iranpayamak",
        active=True,
        notification_active=True,
        api_key_enc=encrypt("api-key"),
        from_number="",
        pattern_code="auth-pattern",
    )
    assert _line_number(setting) == ""
    assert sms_setting_ready(setting) is False
    assert sms_notification_ready(setting) is False


def test_dedicated_line_is_sent_only_when_configured(monkeypatch):
    captured = {}

    class FakeResponse:
        status_code = 201
        content = b'{"success":true}'
        text = content.decode()
        headers = {"content-type": "application/json"}

        def json(self):
            return {"success": True}

    class FakeClient:
        def __init__(self, **kwargs):
            pass

        async def __aenter__(self):
            return self

        async def __aexit__(self, exc_type, exc, tb):
            return False

        async def post(self, endpoint, json=None, headers=None):
            captured.update(endpoint=endpoint, json=json, headers=headers)
            return FakeResponse()

    monkeypatch.setattr(httpx, "AsyncClient", FakeClient)
    setting = SmsSetting(
        id=1,
        provider="iranpayamak",
        active=True,
        api_key_enc=encrypt("secret"),
        base_url=IRANPAYAMAK_DEFAULT_BASE_URL,
        from_number="30001234",
        pattern_code="otp-code",
    )
    asyncio.run(send_pattern(setting, "09123456789", "otp-code", {"code": "12345"}))
    assert captured["endpoint"].endswith("/sms/pattern")
    assert captured["headers"]["Api-Key"] == "secret"
    assert captured["json"]["line_number"] == "30001234"
    assert captured["json"]["recipient"] == "09123456789"
    assert captured["json"]["attributes"] == {"code": "12345"}


def test_legacy_ippanel_setting_is_migrated_without_losing_key():
    engine = create_engine("sqlite+pysqlite:///:memory:")
    Base.metadata.create_all(engine)
    with Session(engine) as db:
        row = SmsSetting(
            id=1,
            provider="ippanel",
            base_url="https://edge.ippanel.com/v1",
            api_key_enc=encrypt("keep-me"),
            from_number="+983000505",
            pattern_code="auth",
        )
        db.add(row)
        db.commit()
        assert migrate_iranpayamak_settings(db) is True
        saved = db.get(SmsSetting, 1)
        assert saved.provider == "iranpayamak"
        assert saved.base_url == IRANPAYAMAK_DEFAULT_BASE_URL
        assert saved.from_number == ""
        assert saved.api_key_enc == row.api_key_enc


def test_admin_uses_iranpayamak_only():
    html = (ROOT / "server/templates/admin.html").read_text(encoding="utf-8")
    main = (ROOT / "server/main.py").read_text(encoding="utf-8")
    sms = (ROOT / "server/sms.py").read_text(encoding="utf-8")
    assert "api.iranpayamak.com/ws/v1" in html
    assert "Api-Key" in sms
    assert '"/sms/pattern"' in sms
    assert "FARAZSMS_SHARED_FROM_NUMBER" not in sms
    assert "edge.ippanel.com/v1/api/send" not in sms
    assert "setting.provider='iranpayamak'" in main
