import asyncio
import json
from pathlib import Path
from types import SimpleNamespace

import httpx

from server import integrations, security

ROOT = Path(__file__).resolve().parents[1]


def test_release_metadata_is_v376():
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    assert release["version"] == "3.0.79"
    assert release["version_code"] == 30079
    assert app["version_name"] == "3.0.79"
    assert app["version_code"] == 30079


def test_android_runtime_prunes_disabled_managed_profiles_and_guards_core_start():
    account = (ROOT / "android-source/BlueVpnAccountManager.kt").read_text(encoding="utf-8")
    home = (ROOT / "android-source/BlueVpnHomeActivity.kt").read_text(encoding="utf-8")
    engine = (ROOT / "android-source/BlueVpnEngineManager.kt").read_text(encoding="utf-8")

    assert "fun pruneInactiveManagedPools" in account
    assert "MmkvManager.removeServerViaSubid(row.guid)" in account
    assert "fun ensureEntitlementSelection" in account
    assert "BlueVpnAccountManager.ensureEntitlementSelection(this)" in home
    assert "val isolatedCandidates = candidates.filter" in home
    assert "BlueVpnAccountManager.selectedServerAllowed(app)" in engine


def test_combined_subscription_interleaves_providers_for_cold_shortlist():
    source = (ROOT / "server/integrations.py").read_text(encoding="utf-8")
    assert "max_source_size = max" in source
    assert "for index in range(max_source_size)" in source
    assert "for lines, source_name, source_key in source_items" in source


def test_bluepay_retries_same_idempotent_request_after_timeout(monkeypatch):
    calls = []

    class FakeResponse:
        status_code = 201
        headers = {"X-Request-ID": "req-retry-ok"}
        text = ""

        @staticmethod
        def json():
            return {
                "success": True,
                "payment_id": "PAYMENT_RETRY",
                "status": "pending",
                "base_amount_rial": 1_500_000,
                "payment_url": "https://bluepay-production.up.railway.app/pay/PAYMENT_RETRY",
            }

    class FakeClient:
        def __init__(self, *args, **kwargs):
            pass

        async def __aenter__(self):
            return self

        async def __aexit__(self, exc_type, exc, tb):
            return False

        async def post(self, url, **kwargs):
            calls.append((url, kwargs))
            if len(calls) == 1:
                raise httpx.ReadTimeout("temporary timeout")
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
        order_code="BV-RETRY-1001",
        plan=SimpleNamespace(title="اشتراک یک ماهه"),
        customer=SimpleNamespace(phone="09350000000", email=""),
    )

    result = asyncio.run(
        integrations.create_invoice(
            setting,
            order,
            "http://localhost:8000/webhooks/bluepay",
        )
    )

    assert result["payment_id"] == "PAYMENT_RETRY"
    assert len(calls) == 2
    assert calls[0][1]["headers"]["Idempotency-Key"] == calls[1][1]["headers"]["Idempotency-Key"]
    assert calls[0][1]["json"] == calls[1][1]["json"]
    assert "callback_url" not in calls[0][1]["json"]


def test_encrypted_bluepay_key_survives_session_to_data_key_migration(monkeypatch):
    monkeypatch.delenv("DATA_ENCRYPTION_KEY", raising=False)
    monkeypatch.delenv("DATA_ENCRYPTION_KEY_PREVIOUS", raising=False)
    monkeypatch.setenv("SESSION_SECRET", "old-stable-session-secret")
    encrypted = security.encrypt("gw_private_store_key")

    monkeypatch.setenv("DATA_ENCRYPTION_KEY", "new-dedicated-data-key")
    assert security.decrypt(encrypted) == "gw_private_store_key"
