import asyncio
import json
from pathlib import Path
from types import SimpleNamespace

import pytest

from server import integrations

ROOT = Path(__file__).resolve().parents[1]


def test_release_metadata_is_v374():
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    assert release["version"] == "3.0.81"
    assert release["version_code"] == 30081
    assert app["version_name"] == "3.0.81"
    assert app["version_code"] == 30081


def test_create_invoice_sends_one_canonical_bluepay_request(monkeypatch):
    calls = []

    class FakeResponse:
        status_code = 201
        headers = {"X-Request-ID": "req-bluevpn-1"}
        text = ""

        @staticmethod
        def json():
            return {
                "success": True,
                "payment_id": "PAYMENT_TOKEN",
                "order_id": "BV-1-ABC",
                "status": "pending",
                "base_amount_rial": 1_500_000,
                "payment_url": "https://bluepay-production.up.railway.app/pay/PAYMENT_TOKEN",
            }

    class FakeClient:
        def __init__(self, *args, **kwargs):
            self.kwargs = kwargs

        async def __aenter__(self):
            return self

        async def __aexit__(self, exc_type, exc, tb):
            return False

        async def post(self, url, **kwargs):
            calls.append((url, kwargs))
            return FakeResponse()

    monkeypatch.setattr(integrations.httpx, "AsyncClient", FakeClient)
    monkeypatch.setattr(
        integrations,
        "payment_secret",
        lambda setting: ("https://bluepay-production.up.railway.app", "gw_store_key", ""),
    )

    setting = SimpleNamespace(active=True, fee_mode="default", ttl_minutes=30)
    order = SimpleNamespace(
        amount_toman=150_000,
        order_code="BV-1-ABC",
        plan=SimpleNamespace(title="اشتراک یک ماهه"),
        customer=SimpleNamespace(phone="09350000000", email=""),
    )

    result = asyncio.run(
        integrations.create_invoice(
            setting,
            order,
            "https://bluevpnapp-production.up.railway.app/webhooks/bluepay",
        )
    )

    assert result["payment_id"] == "PAYMENT_TOKEN"
    assert result["bluepay_request_id"] == "req-bluevpn-1"
    assert len(calls) == 1
    url, request = calls[0]
    assert url == "https://bluepay-production.up.railway.app/api/v1/invoices"
    assert request["headers"]["X-API-Key"] == "gw_store_key"
    assert request["headers"]["Idempotency-Key"] == "BV-1-ABC-create"
    assert "Authorization" not in request["headers"]
    assert "Api-Key" not in request["headers"]
    assert set(request["json"]) == {
        "amount_toman",
        "order_id",
        "description",
        "fee_mode",
        "callback_url",
        "ttl_minutes",
    }
    assert request["json"]["amount_toman"] == 150_000
    assert request["json"]["order_id"] == "BV-1-ABC"
    assert "webhook_url" not in request["json"]


def test_contract_source_has_no_multi_body_idempotency_retry():
    source = (ROOT / "server/integrations.py").read_text(encoding="utf-8")
    assert 'endpoints = ("/api/v1/invoices", "/api/invoices")' not in source
    assert 'for auth_mode in ("api_key", "api_key_alt", "bearer")' not in source
    assert '"webhook_url": callback_url' not in source
    assert '"X-Idempotency-Key"' not in source
    assert 'base.rstrip("/") + "/api/v1/invoices"' in source


def test_admin_cannot_enable_bluepay_without_store_api_key():
    source = (ROOT / "server/main.py").read_text(encoding="utf-8")
    template = (ROOT / "server/templates/admin.html").read_text(encoding="utf-8")
    assert "requested_active and not effective_key" in source
    assert "API Key اختصاصی فروشگاه" in template
    assert "لینک /developers به‌تنهایی کلید API نیست" in source
