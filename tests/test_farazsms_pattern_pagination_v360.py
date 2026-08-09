from __future__ import annotations

import asyncio
import json
from pathlib import Path

import httpx

from server.models import SmsSetting
from server.security import encrypt
from server.sms import fetch_active_patterns

ROOT = Path(__file__).resolve().parents[1]


def _pattern(index: int) -> dict:
    return {
        "code": f"PATTERN{index:03d}",
        "status": "active",
        "text": f"پیام شماره {index}: %code%",
        "vars": [{"var": "code"}],
    }


def _setting() -> SmsSetting:
    return SmsSetting(
        id=1,
        provider="iranpayamak",
        base_url="https://api.iranpayamak.com/ws/v1",
        api_key_enc=encrypt("secret"),
        verify_tls=True,
    )


def test_release_version_360():
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    assert release["version"] == "3.0.79"
    assert release["version_code"] == 30079
    assert app["version_name"] == "3.0.79"
    assert app["version_code"] == 30079


def test_fetches_all_farazsms_pattern_pages_with_laravel_meta(monkeypatch):
    calls: list[dict] = []
    rows = [_pattern(i) for i in range(1, 39)]

    class FakeResponse:
        def __init__(self, payload):
            self._payload = payload
            self.status_code = 200
            self.content = json.dumps(payload, ensure_ascii=False).encode()
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
            params = dict(params or {})
            calls.append(params)
            page = int(params.get("page", 1))
            start = (page - 1) * 15
            page_rows = rows[start : start + 15]
            return FakeResponse(
                {
                    "data": page_rows,
                    "meta": {
                        "current_page": page,
                        "last_page": 3,
                        "per_page": 15,
                        "total": len(rows),
                    },
                }
            )

    monkeypatch.setattr(httpx, "AsyncClient", FakeClient)
    patterns = asyncio.run(fetch_active_patterns(_setting()))

    assert len(patterns) == 38
    assert {item["code"] for item in patterns} == {row["code"] for row in rows}
    assert [call["page"] for call in calls] == [1, 2, 3]
    assert all(call["per_page"] == 100 for call in calls)


def test_fetches_all_pages_without_metadata_and_stops_on_empty_page(monkeypatch):
    calls: list[int] = []
    rows = [_pattern(i) for i in range(1, 31)]

    class FakeResponse:
        def __init__(self, payload):
            self._payload = payload
            self.status_code = 200
            self.content = json.dumps(payload, ensure_ascii=False).encode()
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
            page = int((params or {}).get("page", 1))
            calls.append(page)
            start = (page - 1) * 15
            return FakeResponse({"data": rows[start : start + 15]})

    monkeypatch.setattr(httpx, "AsyncClient", FakeClient)
    patterns = asyncio.run(fetch_active_patterns(_setting()))

    assert len(patterns) == 30
    assert calls == [1, 2, 3]


def test_duplicate_first_page_does_not_loop_forever(monkeypatch):
    calls: list[int] = []
    rows = [_pattern(i) for i in range(1, 16)]

    class FakeResponse:
        status_code = 200
        headers = {"content-type": "application/json"}

        def __init__(self, payload):
            self._payload = payload
            self.content = json.dumps(payload, ensure_ascii=False).encode()
            self.text = self.content.decode()

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
            page = int((params or {}).get("page", 1))
            calls.append(page)
            return FakeResponse({"data": rows})

    monkeypatch.setattr(httpx, "AsyncClient", FakeClient)
    patterns = asyncio.run(fetch_active_patterns(_setting()))

    assert len(patterns) == 15
    assert calls == [1, 2]
