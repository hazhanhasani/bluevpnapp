import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_release_metadata_is_v375():
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    assert release["version"] == "3.0.79"
    assert release["version_code"] == 30079
    assert release["android_version"] == "3.0.79"
    assert release["android_version_code"] == 30079
    assert app["version_name"] == "3.0.79"
    assert app["version_code"] == 30079


def test_entitlement_pool_is_strict_and_has_no_global_fallback():
    account = (ROOT / "android-source/BlueVpnAccountManager.kt").read_text(encoding="utf-8")
    locations = (ROOT / "android-source/BlueVpnLocationUtil.kt").read_text(encoding="utf-8")

    assert "fun entitlementSubscriptionGuids" in account
    assert "fun entitlementPoolFingerprint" in account
    assert "return guid in entitlementServerGuids" in account
    assert "id.isNotBlank() && id in entitlementSubscriptionGuids(c)" in account
    assert "id !in allFreeGuids" not in account

    fast = locations.split("fun fastCandidates(", 1)[1].split("fun instantCandidates(", 1)[0]
    assert "if (entitlementGuids.isEmpty()) return emptyList()" in fast
    assert "selected in entitlementGuidSet" in fast
    assert "allGuids.forEach" not in fast
    assert "MmkvManager.decodeAllServerList()" not in fast


def test_candidate_cache_is_bound_to_entitlement_fingerprint():
    locations = (ROOT / "android-source/BlueVpnLocationUtil.kt").read_text(encoding="utf-8")
    assert 'private var contextCandidateCacheKey: String = ""' in locations
    assert "BlueVpnAccountManager.entitlementPoolFingerprint(context)" in locations
    assert "contextCandidateCacheKey == cacheKey" in locations
    assert "contextCandidateCacheKey = cacheKey" in locations
    assert "Stale-while-revalidate" in locations


def test_stale_premium_rows_are_disabled_before_refresh():
    account = (ROOT / "android-source/BlueVpnAccountManager.kt").read_text(encoding="utf-8")
    assert "val managedRows = existing.filter { it.subscription.remarks == SUB }" in account
    assert "it.subscription.url.trim() == normalizedPremiumUrl" in account
    assert "Disable stale Premium rows" in account
    assert "it.guid != managed?.guid && it.subscription.enabled" in account


def test_location_loading_always_recovers_and_broadcasts_are_coalesced():
    screen = (ROOT / "android-source/BlueVpnServersActivity.kt").read_text(encoding="utf-8")
    assert "val result = runCatching" in screen
    assert 'candidateLoadError = ""' in screen
    assert "candidateLoadInProgress = false" in screen
    assert "scheduleCandidateReload(force = true)" in screen
    assert "postDelayed(candidateReloadRunnable, 350L)" in screen
    assert "retry only while the pool is still empty" in screen
    assert "دریافت سرورها ناموفق بود؛ تازه‌سازی را بزنید" in screen
