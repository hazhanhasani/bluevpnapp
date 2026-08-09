import base64
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_release_metadata_is_v377():
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    marker = json.loads((ROOT / "deployment-marker.json").read_text(encoding="utf-8"))
    assert release["version"] == "3.0.78"
    assert release["version_code"] == 30078
    assert app["version_name"] == "3.0.78"
    assert app["version_code"] == 30078
    assert marker["release"] == "3.0.78"


def test_healthy_premium_pool_is_not_reimported_on_account_refresh():
    account = (ROOT / "android-source/BlueVpnAccountManager.kt").read_text(encoding="utf-8")
    assert "val poolMissing = preferredServerGuids(c).isEmpty()" in account
    assert "forceRefresh = entitlementChanged || poolMissing" in account
    assert "forceRefresh = preferredServerGuids(appContext).isEmpty()" in account
    assert "private val subscriptionReconcileLock = Any()" in account
    assert ") = synchronized(subscriptionReconcileLock) {" in account
    assert "subscriptionRefreshRunning = true" in account
    assert "subscriptionRefreshRunning = false" in account


def test_location_cache_uses_stable_entitlement_identity_and_non_empty_commit():
    locations = (ROOT / "android-source/BlueVpnLocationUtil.kt").read_text(encoding="utf-8")
    assert "fun entitlementIdentityFingerprint" in (ROOT / "android-source/BlueVpnAccountManager.kt").read_text(encoding="utf-8")
    assert locations.count("entitlementIdentityFingerprint(context)") >= 2
    assert "CONTEXT_STALE_GRACE_MS" in locations
    assert "contextCandidateCacheDirty" in locations
    assert "if (resolved.isEmpty() && previous.isNotEmpty())" in locations
    assert "Never let a transient empty import replace a healthy visible pool" in locations
    assert "contextCandidateCache = resolved" in locations


def test_locations_screen_has_single_manual_refresh_owner_and_atomic_render():
    screen = (ROOT / "android-source/BlueVpnServersActivity.kt").read_text(encoding="utf-8")
    refresh = screen.split('refreshButton = smallButton("تازه‌سازی")', 1)[1].split("row.addView(refreshButton", 1)[0]
    assert "refreshEntitlementState(force = true)" in refresh
    assert "loadCandidates(force = true" not in refresh
    assert "mainViewModel.reloadServerList()" not in refresh
    assert "mainViewModel.testAllRealPing()" not in refresh
    assert "requestIdentity" in screen
    assert "requestIdentity != currentIdentity" in screen
    assert "candidateLoadInProgress && listContainer.childCount > 0" in screen
    assert screen.index("val candidates = BlueVpnLocationUtil.cachedCandidates(this)") < screen.index("listContainer.removeAllViews()", screen.index("private fun renderLocationsNow"))


def test_modified_android_sources_match_embedded_payloads():
    script = (ROOT / "scripts/prepare_android.py").read_text(encoding="utf-8")
    for const, name in {
        "BLUEVPN_ACCOUNT_MANAGER_B64": "BlueVpnAccountManager.kt",
        "BLUEVPN_LOCATION_UTIL_B64": "BlueVpnLocationUtil.kt",
        "BLUEVPN_SERVERS_ACTIVITY_B64": "BlueVpnServersActivity.kt",
    }.items():
        match = re.search(rf'^{const} = "([^"]+)"$', script, re.M)
        assert match, const
        embedded = base64.b64decode(match.group(1)).decode("utf-8")
        assert embedded == (ROOT / "android-source" / name).read_text(encoding="utf-8")
