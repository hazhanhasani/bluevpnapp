from pathlib import Path
import base64
import json
import re

ROOT = Path(__file__).resolve().parents[1]


def test_version_370():
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    assert release["version"] == "4.0.0"
    assert release["android_version_code"] == 40000
    assert app["version_name"] == "4.0.0"
    assert app["version_code"] == 40000


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


def test_tls_profile_acceptance_matches_upstream_and_is_not_silently_filtered():
    location = (ROOT / "android-source/BlueVpnLocationUtil.kt").read_text(encoding="utf-8")
    prepare = (ROOT / "scripts/prepare_android.py").read_text(encoding="utf-8")
    home = (ROOT / "android-source/BlueVpnHomeActivity.kt").read_text(encoding="utf-8")
    assert "containsRemovedTlsOption" not in location
    assert "compatibilityIssue(" not in location
    assert "BlueVpnLocationUtil.compatibilityIssue(" not in home
    assert "patch_legacy_tls_profiles" in prepare
    patch = prepare[prepare.index("def patch_legacy_tls_profiles"): prepare.index("def inject_bootstrap")]
    assert "return" in patch
    assert "config.insecure == true" not in patch
    assert "containsRemovedTlsOption" not in patch


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
