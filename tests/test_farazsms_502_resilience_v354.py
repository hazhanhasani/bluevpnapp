from __future__ import annotations

import base64
import json
import re
from pathlib import Path

import httpx

from server.sms import SmsError, _error_message

ROOT = Path(__file__).resolve().parents[1]


def test_release_version_355_compat():
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    assert release["version"] == "3.0.58"
    assert release["version_code"] == 30058
    assert app["version_name"] == "3.0.58"
    assert app["version_code"] == 30058


def test_html_provider_error_is_never_exposed():
    response = httpx.Response(
        502,
        headers={"content-type": "text/html; charset=UTF-8"},
        text="<!DOCTYPE html><html><body>cdn-cgi error-section__information SECRET</body></html>",
    )
    message = _error_message(response)
    assert message == "پاسخ نامعتبر از سرویس پیامک دریافت شد"
    assert "DOCTYPE" not in message
    assert "SECRET" not in message


def test_transient_provider_error_has_safe_metadata():
    error = SmsError(
        "سامانه ایران‌پیامک موقتاً پاسخ‌گو نیست؛ چند لحظه دیگر دوباره تلاش کنید.",
        transient=True,
        provider_status=502,
    )
    assert error.transient is True
    assert error.provider_status == 502
    assert "html" not in str(error).lower()


def test_sms_sender_retries_and_api_returns_retryable_error():
    sms = (ROOT / "server/sms.py").read_text(encoding="utf-8")
    main = (ROOT / "server/main.py").read_text(encoding="utf-8")
    assert "_TRANSIENT_PROVIDER_STATUSES" in sms
    assert "await asyncio.sleep(_PROVIDER_RETRY_DELAYS[attempt])" in sms
    assert "SMS_PROVIDER_TEMPORARY_UNAVAILABLE" in main
    assert "503 if transient else 502" in main
    assert "'Retry-After':'30'" in main


def test_android_redacts_html_and_limits_error_area():
    account = (ROOT / "android-source/BlueVpnAccountManager.kt").read_text(encoding="utf-8")
    subscriptions = (ROOT / "android-source/BlueVpnSubscriptionsActivity.kt").read_text(encoding="utf-8")
    assert "safeApiMessage" in account
    assert 'lowered.contains("<!doctype")' in account
    assert 'lowered.contains("cdn-cgi")' in account
    assert "cleaned.take(180)" in account
    assert "maxLines=3" in subscriptions
    assert "TextUtils.TruncateAt.END" in subscriptions


def test_generated_android_sources_match_snapshots_v354():
    script = (ROOT / "scripts/prepare_android.py").read_text(encoding="utf-8")
    for const, rel in (
        ("BLUEVPN_ACCOUNT_MANAGER_B64", "android-source/BlueVpnAccountManager.kt"),
        ("BLUEVPN_SUBSCRIPTIONS_ACTIVITY_B64", "android-source/BlueVpnSubscriptionsActivity.kt"),
    ):
        match = re.search(rf'{const} = "([^"]+)"', script)
        assert match
        decoded = base64.b64decode(match.group(1)).decode("utf-8")
        assert decoded == (ROOT / rel).read_text(encoding="utf-8")
