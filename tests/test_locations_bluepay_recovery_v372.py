import json
from pathlib import Path

from server.integrations import _bluepay_headers, normalize_bluepay_base_url

ROOT = Path(__file__).resolve().parents[1]


def test_release_metadata_is_v372():
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    assert release["version"] == "3.0.81"
    assert release["version_code"] == 30081
    assert app["version_name"] == "3.0.81"
    assert app["version_code"] == 30081


def test_bluepay_developer_url_is_converted_to_api_root():
    base, key = normalize_bluepay_base_url(
        "https://bluepay-production.up.railway.app/developers/2/6705ba5f83c6f95d4313926b98d95038"
    )
    assert base == "https://bluepay-production.up.railway.app"
    assert key == "6705ba5f83c6f95d4313926b98d95038"

    base, key = normalize_bluepay_base_url(
        "https://bluepay-production.up.railway.app/api/v1/"
    )
    assert base == "https://bluepay-production.up.railway.app"
    assert key == ""


def test_bluepay_uses_only_the_documented_api_key_header():
    headers = _bluepay_headers("secret", idempotency_key="order-1001-create")
    assert headers["X-API-Key"] == "secret"
    assert headers["Idempotency-Key"] == "order-1001-create"
    assert "Api-Key" not in headers
    assert "Authorization" not in headers
    assert "X-Idempotency-Key" not in headers


def test_android_locations_use_server_guid_and_do_not_reload_forever():
    account = (ROOT / "android-source/BlueVpnAccountManager.kt").read_text(encoding="utf-8")
    locations = (ROOT / "android-source/BlueVpnLocationUtil.kt").read_text(encoding="utf-8")
    screen = (ROOT / "android-source/BlueVpnServersActivity.kt").read_text(encoding="utf-8")

    assert "serverGuid: String" in account
    assert "awaitEntitlementServers" in account
    assert "candidate.guid" in locations
    assert "entitlementServerGuidSet" in locations
    assert "سرورهای اشتراک هنوز دریافت نشده‌اند" in screen
    assert 'if (!candidateLoadInProgress) loadCandidates(force = false)' not in screen


def test_android_invoice_timeout_matches_backend_provider_budget():
    account = (ROOT / "android-source/BlueVpnAccountManager.kt").read_text(encoding="utf-8")
    integrations = (ROOT / "server/integrations.py").read_text(encoding="utf-8")
    main = (ROOT / "server/main.py").read_text(encoding="utf-8")

    assert "connection.readTimeout = if (invoiceRequest) 50_000 else 12_000" in account
    assert '"BLUEPAY_TIMEOUT"' in account
    assert 'base.rstrip("/") + "/api/v1/invoices"' in integrations
    assert 'timeout=httpx.Timeout(20.0, connect=8.0)' in integrations
    assert "normalize_bluepay_base_url" in main
    assert "BLUEPAY_INTERNAL_ERROR" in main
