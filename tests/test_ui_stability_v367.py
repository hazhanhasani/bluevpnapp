from __future__ import annotations

import base64
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_release_version_367():
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    assert release["version"] == "3.0.77"
    assert release["android_version_code"] == 30077
    assert app["version_name"] == "3.0.77"
    assert app["version_code"] == 30077


def test_global_ui_guard_and_recovery_mode_present():
    experience = (ROOT / "android-source/BlueVpnExperience.kt").read_text(encoding="utf-8")
    bootstrap = (ROOT / "android-source/BlueVpnBootstrap.kt").read_text(encoding="utf-8")
    theme = (ROOT / "android-source/BlueVpnTheme.kt").read_text(encoding="utf-8")
    assert "object BlueVpnUiGuard" in experience
    assert "fun bind(" in experience
    assert "installCrashLogger" in experience
    assert "safe_mode_until" in experience
    assert "BlueVpnUiGuard.installCrashLogger" in bootstrap
    assert "BlueVpnUiGuard.safeMode(app)" in theme


def test_home_actions_are_debounced_and_navigation_is_guarded():
    home = (ROOT / "android-source/BlueVpnHomeActivity.kt").read_text(encoding="utf-8")
    assert "lastConnectionToggleAt" in home
    assert "now - lastConnectionToggleAt < 700L" in home
    assert "BlueVpnUiGuard.bind(connectButton" in home
    assert 'BlueVpnUiGuard.run(this, "open-account")' in home
    assert 'BlueVpnUiGuard.run(this, "open-servers")' in home
    assert "BlueVpnUiGuard.start(" in home


def test_server_list_render_is_coalesced_and_chunked():
    servers = (ROOT / "android-source/BlueVpnServersActivity.kt").read_text(encoding="utf-8")
    assert "renderGeneration" in servers
    assert "renderLocationsNow" in servers
    assert "BlueVpnPerformance.uiChunkSize" in servers
    assert "renderHandler.post(appendChunk)" in servers
    assert "expandedLocations.clear()" in servers
    assert "refreshTimeoutRunnable" in servers


def test_account_render_generation_prevents_stale_ui_updates():
    account = (ROOT / "android-source/BlueVpnSubscriptionsActivity.kt").read_text(encoding="utf-8")
    assert "renderGeneration" in account
    assert "generation!=renderGeneration" in account
    assert 'BlueVpnUiGuard.run(this,"render-account")' in account
    assert "handler.removeCallbacksAndMessages(null)" in account


def test_embedded_sources_match_snapshots_v367():
    script = (ROOT / "scripts/prepare_android.py").read_text(encoding="utf-8")
    pairs = {
        "BOOTSTRAP_B64": "BlueVpnBootstrap.kt",
        "BLUEVPN_EXPERIENCE_B64": "BlueVpnExperience.kt",
        "BLUEVPN_THEME_B64": "BlueVpnTheme.kt",
        "BLUEVPN_HOME_ACTIVITY_B64": "BlueVpnHomeActivity.kt",
        "BLUEVPN_SERVERS_ACTIVITY_B64": "BlueVpnServersActivity.kt",
        "BLUEVPN_SUBSCRIPTIONS_ACTIVITY_B64": "BlueVpnSubscriptionsActivity.kt",
        "BLUEVPN_SETTINGS_ACTIVITY_B64": "BlueVpnSettingsActivity.kt",
    }
    for constant, filename in pairs.items():
        match = re.search(rf'^{constant} = "([^"]+)"', script, flags=re.M)
        assert match, constant
        decoded = base64.b64decode(match.group(1)).decode("utf-8")
        snapshot = (ROOT / "android-source" / filename).read_text(encoding="utf-8")
        assert decoded == snapshot, filename
