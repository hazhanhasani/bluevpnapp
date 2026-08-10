from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(rel: str) -> str:
    return (ROOT / rel).read_text(encoding="utf-8")


def test_subscription_intelligence_source_and_ua_ladder():
    text = read("android-source/BlueVpnSubscriptionIntelligence.kt")
    assert "object BlueVpnSubscriptionIntelligence" in text
    assert '"v2rayNG"' in text
    assert '"sing-box"' in text
    assert '"Clash.Meta"' in text
    assert "AngConfigManager.updateConfigViaSub(row)" in text
    assert "MAX_FALLBACKS_NORMAL = 1" in text
    assert "MAX_FALLBACKS_REPAIR = 3" in text
    assert "if (aggressiveRepair || beforeCount == 0)" in text
    assert "captureSelectedFingerprint" in text
    assert "restoreSelectedFingerprint" in text


def test_account_manager_scopes_subscription_refresh_and_preserves_metadata():
    text = read("android-source/BlueVpnAccountManager.kt")
    assert "BlueVpnSubscriptionIntelligence.refresh(" in text
    assert "AngConfigManager.updateConfigViaSubAll()" not in text
    assert ".copy(enabled = false)" in text
    assert "recommendedUserAgent" in text


def test_prepare_android_embeds_subscription_intelligence_and_server_ui():
    text = read("scripts/prepare_android.py")
    assert "BlueVpnSubscriptionIntelligence.kt" in text
    assert "BLUEVPN_SERVERS_ACTIVITY_B64" in text


def test_route_intelligence_has_rolling_decay_and_exit_colo():
    text = read("android-source/BlueVpnRouteIntelligence.kt")
    assert "recentCounts" in text
    assert ">= 64" in text
    assert "exitColo" in text
    assert '"colo"' in text


def test_servers_activity_surfaces_route_evidence_and_exit_summary():
    text = read("android-source/BlueVpnServersActivity.kt")
    assert "BlueVpnRouteIntelligence.evidence" in text
    assert "BlueVpnRouteIntelligence.exitSummary" in text
