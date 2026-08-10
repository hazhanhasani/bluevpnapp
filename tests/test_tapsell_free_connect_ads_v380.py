from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def text(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def test_release_metadata_v380():
    app = json.loads(text("branding/app.json"))
    release = json.loads(text("release.json"))
    marker = json.loads(text("deployment-marker.json"))
    assert app["version_name"] == "3.0.83"
    assert app["version_code"] == 30083
    assert release["version"] == "3.0.83"
    assert release["android_version_code"] == 30083
    assert marker["release"] == "3.0.83"


def test_official_tapsell_sdk_is_injected_and_kept_for_r8():
    prepare = text("scripts/prepare_android.py")
    assert 'ir.tapsell.plus:tapsell-plus-sdk-android:2.3.3' in prepare
    assert '-keep class ir.tapsell.**' in prepare
    assert 'BlueVpnTapsellManager.kt' in prepare


def test_mobile_config_and_admin_have_tapsell_settings():
    server = text("server/main.py")
    admin = text("server/templates/admin.html")
    assert "def tapsell_payload" in server
    assert "'tapsell':tapsell_payload(s)" in server
    assert "@app.post('/admin/tapsell/settings')" in server
    assert "tapsell_interstitial_zone_id" in server
    assert 'action="/admin/tapsell/settings"' in admin
    assert 'name="app_key"' in admin
    assert 'name="interstitial_zone_id"' in admin


def test_ads_are_free_only_and_never_gate_vpn_connection():
    manager = text("android-source/BlueVpnTapsellManager.kt")
    home = text("android-source/BlueVpnHomeActivity.kt")
    assert "BlueVpnEntitlement.resolve(app).isFree" in manager
    assert "Premium/Unavailable users never request or see an ad" in manager
    assert "The VPN connection is never blocked by the ad SDK" in manager
    assert "if (!completedLiveSwitch && BlueVpnEntitlement.resolve(this).isFree)" in home
    assert "BlueVpnTapsellManager.onVerifiedConnection" in home
    # Trigger must happen after the connection state is committed/rendered.
    assert home.index("BlueVpnPreferences.markConnected(this, resetTimer = true)") < home.index(
        "BlueVpnTapsellManager.onVerifiedConnection"
    )


def test_one_ad_per_verified_connection_session():
    manager = text("android-source/BlueVpnTapsellManager.kt")
    assert 'KEY_LAST_SESSION = "last_shown_session"' in manager
    assert "BlueVpnPreferences.connectedAt(context) != sessionId" in manager
    assert "storage.getLong(KEY_LAST_SESSION, 0L) == sessionId" in manager
    assert ".putLong(KEY_LAST_SESSION, sessionId)" in manager
    assert "minIntervalSeconds" in manager
    assert "dailyCap" in manager


def test_tapsell_sdk_failure_is_fail_open():
    manager = text("android-source/BlueVpnTapsellManager.kt")
    assert "runCatching" in manager
    assert 'Log.w(TAG, "Tapsell initialization unavailable"' in manager
    assert 'Log.w(TAG, "Interstitial request unavailable"' in manager
    assert 'Log.w(TAG, "Interstitial show unavailable"' in manager
    assert "BlueVpnEngineManager.stop" not in manager
