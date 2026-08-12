from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(rel: str) -> str:
    return (ROOT / rel).read_text(encoding="utf-8")


def test_subscription_refresh_uses_native_v2rayng_user_agent_first():
    text = read("android-source/BlueVpnSubscriptionIntelligence.kt")
    assert "mutableListOf<String?>(null)" in text
    assert "native v2rayNG/<version> first" in text
    assert "UPSTREAM_DEFAULT_UA" in text
    assert "MAX_FALLBACKS_NORMAL = 1" in text
    assert "MAX_FALLBACKS_REPAIR = 3" in text


def test_entitlement_server_guids_ignore_ghost_profiles():
    text = read("android-source/BlueVpnAccountManager.kt")
    helper = text[text.index("private fun usableServerGuids"):text.index("fun pruneInactiveManagedPools")]
    block = text[text.index("fun preferredServerGuids"):text.index("fun entitlementPoolFingerprint")]
    assert "MmkvManager.decodeServerConfig(serverGuid) != null" in helper
    assert "val exact = usableServerGuids(entitlementSubscriptionGuids(c))" in block


def test_invalid_selected_guid_is_cleared_when_pool_is_empty():
    text = read("android-source/BlueVpnAccountManager.kt")
    block = text[text.index("fun ensureEntitlementSelection"):text.index("fun preferredServerGuids")]
    assert 'MmkvManager.setSelectServer("")' in block
    assert "pruneInactiveManagedPools(c)" not in block


def test_home_does_not_render_orphan_selected_profile():
    text = read("android-source/BlueVpnHomeActivity.kt")
    assert "val selectedAllowed = BlueVpnAccountManager.selectedServerAllowed(this)" in text
    assert "it.isNotBlank() && selectedAllowed" in text


def test_smart_selector_summary_cannot_show_previous_entitlement_route():
    text = read("android-source/BlueVpnSmartSelector.kt")
    block = text[text.index("fun lastSummary"):text.index("fun clear")]
    assert "BlueVpnAccountManager.candidateAllowed(" in block
    assert "در انتظار دریافت سرورهای مجاز" in block


def test_premium_pool_swap_is_transactional():
    text = read("android-source/BlueVpnAccountManager.kt")
    assert "Do not delete the previous Premium physical pool before the new" in text
    assert "val currentPoolReady = !premiumActive || exactEntitlementServerGuids(c).isNotEmpty()" in text
    assert "val stalePremiumRows = subscriptions" in text
    assert "if (activePremiumReady)" in text
