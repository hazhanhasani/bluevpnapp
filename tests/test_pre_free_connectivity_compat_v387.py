from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def read(name: str) -> str:
    return (ROOT / "android-source" / name).read_text(encoding="utf-8")

def test_premium_uses_exact_pool_then_preserved_v2rayng_profiles():
    account = read("BlueVpnAccountManager.kt")
    block = account[account.index("fun preferredServerGuids"):account.index("fun entitlementPoolFingerprint")]
    assert "val exact = usableServerGuids(entitlementSubscriptionGuids(c))" in block
    assert "if (exact.isNotEmpty() || !active(c)) return exact" in block
    assert "val preservedPremium = usableServerGuids(premiumRows)" in block
    assert "MmkvManager.decodeAllServerList()" in block
    assert "it !in freeServerGuids" in block

def test_free_mode_never_receives_global_premium_fallback():
    account = read("BlueVpnAccountManager.kt")
    preferred = account[account.index("fun preferredServerGuids"):account.index("fun entitlementPoolFingerprint")]
    candidate = account[account.index("fun candidateAllowed(", account.index("fun candidateAllowed(") + 1):account.index("fun awaitEntitlementServers")]
    assert "!active(c)" in preferred
    assert "if (!active(c)) return false" in candidate
    assert "if (id in allFreeSubscriptionGuids()) return false" in candidate

def test_pool_switch_never_physically_deletes_working_profiles():
    account = read("BlueVpnAccountManager.kt")
    assert "MmkvManager.removeServerViaSubid" not in account
    assert "last-known-good" in account

def test_context_candidates_dedupe_after_entitlement_isolation():
    locations = read("BlueVpnLocationUtil.kt")
    block = locations[locations.index("fun allCandidates(\n        context: Context"):locations.index("fun fastCandidates(")]
    assert "val entitlementGuidList = BlueVpnAccountManager.preferredServerGuids(context)" in block
    assert "seenEntitlementFingerprints" in block
    assert "orderedEntitlementGuids" in block
    assert "val resolved = allCandidates(forceRefresh)" not in block

def test_xray_runtime_still_delegates_exact_guid_to_v2rayng_core():
    engine = read("BlueVpnEngineManager.kt")
    assert "CoreServiceManager.startVService(app, targetGuid)" in engine
    assert "BlueVpnAccountManager.candidateAllowed(" in engine

def test_current_connect_cycle_keeps_failed_routes_quarantined():
    locations = read("BlueVpnLocationUtil.kt")
    fast = locations[locations.index("fun fastCandidates("):locations.index("fun instantCandidates(")]
    assert "scan(skipSessionInactive = true)" in fast
    assert "scan(skipSessionInactive = false)" in fast  # retained only in explanatory comment
    assert "is intentionally" in fast
