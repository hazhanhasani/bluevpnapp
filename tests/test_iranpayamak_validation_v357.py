from __future__ import annotations

import asyncio
import json
from pathlib import Path

import httpx
import pytest

from server.models import SmsSetting
from server.security import encrypt
from server.sms import SmsError, _error_message, send_pattern, sms_setting_ready, validate_pattern_code

ROOT = Path(__file__).resolve().parents[1]


def test_release_version_357():
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    assert release["version"] == "3.0.75"
    assert release["version_code"] == 30075
    assert app["version_name"] == "3.0.75"
    assert app["version_code"] == 30075


def test_line_number_is_required_even_for_shared_sender():
    setting = SmsSetting(id=1, active=True, api_key_enc=encrypt("key"), pattern_code="AbCd1234", from_number="")
    assert sms_setting_ready(setting) is False
    with pytest.raises(SmsError, match="line_number"):
        asyncio.run(send_pattern(setting, "09123456789", "AbCd1234", {"code": "12345"}))


def test_validation_errors_are_mapped_to_clear_persian_messages():
    request = httpx.Request("POST", "https://api.iranpayamak.com/ws/v1/sms/pattern")
    response = httpx.Response(422, request=request, json={
        "message": "The given data was invalid.",
        "errors": {
            "code": ["گزینه انتخاب شده code صحیح نمی باشد!"],
            "line_number": ["گزینه انتخاب شده line_number صحیح نمی باشد!"],
        },
    })
    message = _error_message(response)
    assert "UID دقیق پترن" in message
    assert "line_number واقعی" in message
    assert "{'code'" not in message


def test_pattern_code_can_be_verified_before_sending(monkeypatch):
    captured = {}

    class FakeResponse:
        status_code = 200
        content = b'{"status":"success","data":{"code":"AbCd1234"}}'
        headers = {"content-type": "application/json"}
        text = content.decode()
        def json(self):
            return {"status":"success","data":{"code":"AbCd1234"}}

    class FakeClient:
        def __init__(self, **kwargs): pass
        async def __aenter__(self): return self
        async def __aexit__(self, exc_type, exc, tb): return False
        async def get(self, endpoint, headers=None):
            captured.update(endpoint=endpoint, headers=headers)
            return FakeResponse()

    monkeypatch.setattr(httpx, "AsyncClient", FakeClient)
    setting = SmsSetting(id=1, active=True, api_key_enc=encrypt("secret"), from_number="50001234")
    data = asyncio.run(validate_pattern_code(setting, "AbCd1234"))
    assert captured["endpoint"].endswith("/patterns/AbCd1234")
    assert captured["headers"]["Api-Key"] == "secret"
    assert data["status"] == "success"


def test_admin_explains_exact_uid_and_line_requirement():
    html = (ROOT / "server/templates/admin.html").read_text(encoding="utf-8")
    assert "پترن‌های فعال مستقیماً از حساب فراز اس‌ام‌اس دریافت می‌شوند" in html
    assert "فهرست مستقیماً از خطوط قابل‌دسترسی" in html
    assert 'name="from_number"' in html
    assert 'name="sender_mode"' not in html
