from __future__ import annotations

import asyncio
import json
from pathlib import Path

import httpx

from server.models import SmsSetting
from server.security import encrypt
from server.sms import (
    choose_provider_line,
    fetch_provider_catalog,
    parse_accessible_lines,
    parse_provider_patterns,
)

ROOT = Path(__file__).resolve().parents[1]


def test_release_version_358():
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    assert release["version"] == "3.0.77"
    assert release["android_version_code"] == 30077
    assert app["version_name"] == "3.0.77"
    assert app["version_code"] == 30077


def test_accessible_lines_parser_prefers_shared_active_line():
    payload = {
        "status": "success",
        "data": {
            "items": [
                {"id": 8, "number": "50002178584000", "is_dedicated": True, "active": True},
                {"id": 4, "line_number": "3000505", "is_dedicated": False, "active": True},
            ]
        },
    }
    lines = parse_accessible_lines(payload)
    assert {item["number"] for item in lines} == {"50002178584000", "3000505"}
    assert choose_provider_line(lines) == "3000505"


def test_active_patterns_parser_extracts_uid_text_and_variables():
    payload = {
        "status": "success",
        "data": {
            "items": [
                {
                    "code": "UopnYiNAsm",
                    "status": "active",
                    "text": "کد ورود شما به بلوپنل: %code%",
                    "vars": [{"var": "code", "length": 6, "type": "int"}],
                },
                {"code": "OLD1234", "status": "rejected", "text": "رد شده"},
            ]
        },
    }
    patterns = parse_provider_patterns(payload)
    assert len(patterns) == 1
    assert patterns[0]["code"] == "UopnYiNAsm"
    assert patterns[0]["variables"] == ["code"]


def test_catalog_calls_official_farazsms_endpoints_with_api_key(monkeypatch):
    calls = []

    class FakeResponse:
        def __init__(self, payload, status_code=200):
            self._payload = payload
            self.status_code = status_code
            self.content = json.dumps(payload).encode()
            self.text = self.content.decode()
            self.headers = {"content-type": "application/json"}

        def json(self):
            return self._payload

    class FakeClient:
        def __init__(self, **kwargs):
            pass

        async def __aenter__(self):
            return self

        async def __aexit__(self, exc_type, exc, tb):
            return False

        async def request(self, method, endpoint, params=None, json=None, headers=None):
            calls.append((method, endpoint, params, json, headers))
            if endpoint.endswith("/lines/accessible"):
                return FakeResponse({"data": [{"number": "3000505", "is_dedicated": False}]})
            return FakeResponse({
                "data": [{
                    "code": "UopnYiNAsm",
                    "status": "active",
                    "text": "کد ورود: %code%",
                    "vars": [{"var": "code"}],
                }]
            }, status_code=201)

    monkeypatch.setattr(httpx, "AsyncClient", FakeClient)
    setting = SmsSetting(
        id=1,
        provider="iranpayamak",
        base_url="https://api.iranpayamak.com/ws/v1",
        api_key_enc=encrypt("secret"),
        verify_tls=True,
    )
    catalog = asyncio.run(fetch_provider_catalog(setting))
    assert catalog["lines"][0]["number"] == "3000505"
    assert catalog["patterns"][0]["code"] == "UopnYiNAsm"
    assert calls[0][1].endswith("/lines/accessible")
    assert calls[1][1].endswith("/patterns")
    assert calls[0][4]["Api-Key"] == "secret"
    assert calls[1][2]["status"] == "active"


def test_admin_uses_provider_dropdowns_instead_of_manual_uid_and_line():
    html = (ROOT / "server/templates/admin.html").read_text(encoding="utf-8")
    main = (ROOT / "server/main.py").read_text(encoding="utf-8")
    assert '<select name="from_number">' in html
    assert '<input name="from_number"' not in html
    assert 'name="pattern_{{ row.key }}"' in html
    assert 'type="text" name="pattern_{{ row.key }}"' not in html
    assert "/admin/sms/provider-sync" in html
    assert "fetch_provider_catalog" in main
    assert "_auto_match_sms_templates" in main
