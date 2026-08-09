from pathlib import Path
import base64
import json
import re

ROOT = Path(__file__).resolve().parents[1]


def test_version_370():
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    assert release["version"] == "3.0.73"
    assert release["android_version_code"] == 30073
    assert app["version_name"] == "3.0.73"
    assert app["version_code"] == 30073


def test_runtime_is_local_first_and_deferred():
    home = (ROOT / "android-source/BlueVpnHomeActivity.kt").read_text(encoding="utf-8")
    account = (ROOT / "android-source/BlueVpnAccountManager.kt").read_text(encoding="utf-8")
    theme = (ROOT / "android-source/BlueVpnTheme.kt").read_text(encoding="utf-8")
    assert "hasInstalledFreeServers" in account
    assert "prepareFreeAccess(this@BlueVpnHomeActivity, force = false)" in home
    assert "BlueVpnPerformance.accountSyncDelayMs" in home
    assert "mainViewModel.initAssets(assets)" in home
    assert "lifecycleScope.launch(Dispatchers.IO)" in home
    assert "60_000L else 40_000L" in theme
    assert "repeatCount = ValueAnimator.INFINITE" not in theme
    assert "handler.postDelayed(navigationUnlock, 520L)" in home
    assert "beginFreeTimerOnCoreStart()" not in home


def test_removed_tls_profiles_are_filtered_twice():
    location = (ROOT / "android-source/BlueVpnLocationUtil.kt").read_text(encoding="utf-8")
    prepare = (ROOT / "scripts/prepare_android.py").read_text(encoding="utf-8")
    assert "containsRemovedTlsOption" in location
    assert "MmkvManager.decodeServerRaw(guid)" in location
    assert "patch_legacy_tls_profiles" in prepare
    assert "config.insecure == true" in prepare
    assert "containsRemovedTlsOption(rawConfig)" in prepare


def test_generated_sources_match_snapshots():
    prepare = (ROOT / "scripts/prepare_android.py").read_text(encoding="utf-8")
    mapping = {
        "BLUEVPN_HOME_ACTIVITY_B64": "android-source/BlueVpnHomeActivity.kt",
        "BLUEVPN_ACCOUNT_MANAGER_B64": "android-source/BlueVpnAccountManager.kt",
        "BLUEVPN_LOCATION_UTIL_B64": "android-source/BlueVpnLocationUtil.kt",
        "BLUEVPN_THEME_B64": "android-source/BlueVpnTheme.kt",
    }
    for key, rel in mapping.items():
        match = re.search(rf'{key} = "([^"]+)"', prepare)
        assert match, key
        decoded = base64.b64decode(match.group(1)).decode("utf-8")
        assert decoded == (ROOT / rel).read_text(encoding="utf-8")
