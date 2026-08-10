from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def text(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def test_route_intelligence_is_semantic_and_network_scoped():
    src = text("android-source/BlueVpnRouteIntelligence.kt")
    assert "BlueVpnProfileManager.fingerprintGuid" in src
    assert "BlueVpnAi.network" in src
    assert 'ROUTE_PREFIX = "route:"' in src
    assert "networkKey(context)" in src


def test_route_history_tracks_stability_jitter_and_bounded_circuit_breaker():
    src = text("android-source/BlueVpnRouteIntelligence.kt")
    assert "successCount" in src
    assert "failureCount" in src
    assert "consecutiveFailures" in src
    assert "latencyEwmaMs" in src
    assert "jitterEwmaMs" in src
    assert "backoffMs" in src
    assert "0, 1 -> 0L" in src
    assert "else -> 10 * 60_000L" in src
    assert "never permanently deletes a profile" in src


def test_urltest_style_stickiness_prevents_server_flapping():
    selector = text("android-source/BlueVpnSmartSelector.kt")
    src = text("android-source/BlueVpnRouteIntelligence.kt")
    assert "stickyCandidate(context, ranked)" in selector
    assert "scoreTolerance: Int = 7" in src
    assert "latencyToleranceMs: Long = 60L" in src
    assert "STICKY_MAX_AGE_MS" in src


def test_real_tunnel_results_feed_route_history():
    home = text("android-source/BlueVpnHomeActivity.kt")
    assert "BlueVpnRouteIntelligence.recordSuccess(" in home
    assert "BlueVpnRouteIntelligence.recordFailure(this, failedGuid, reason)" in home
    assert "BlueVpnRouteIntelligence.recordExitTrace(" in home
    assert home.index("BlueVpnRouteIntelligence.recordSuccess(") > home.index("private fun completeFailover")


def test_exit_trace_keeps_public_ip_and_country_identity():
    src = text("android-source/BlueVpnRouteIntelligence.kt")
    assert 'values["ip"]' in src
    assert 'values["loc"]' in src
    assert "isPublicIp" in src
    assert "Carrier-grade NAT" in src


def test_generator_installs_route_intelligence_source():
    prepare = text("scripts/prepare_android.py")
    assert 'bluevpn_dir / "BlueVpnRouteIntelligence.kt"' in prepare
    assert 'ROOT / "android-source/BlueVpnRouteIntelligence.kt"' in prepare
