from __future__ import annotations

from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def text(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def test_raw_endpoint_preflight_is_advisory_not_authoritative():
    locations = text("android-source/BlueVpnLocationUtil.kt")
    block = locations[locations.index("fun preflightCandidate("): locations.index("private fun profilePort")]

    assert 'CandidatePreflight(false, "آدرس سرور خالی است")' in block
    assert 'CandidatePreflight(false, "پورت کانفیگ نامعتبر است")' in block
    assert 'CandidatePreflight(false, "DNS سرور پاسخ نداد")' not in block
    assert 'CandidatePreflight(false, "سرور روی پورت کانفیگ پاسخ نداد")' not in block
    assert "Android's resolver" in block
    assert "sortedBy { if (it.address.size == 4) 0 else 1 }" in block
    assert ".take(3)" in block


def test_real_xray_tunnel_proof_is_authoritative_and_waits_for_proxy_ready():
    home = text("android-source/BlueVpnHomeActivity.kt")
    probe = home[home.index("private fun waitForLocalProxyReady"): home.index("private fun completeFailover")]

    assert 'InetSocketAddress("127.0.0.1", httpPort)' in probe
    assert "maxWaitMs: Long = 1_800L" in probe
    assert "if (!waitForLocalProxyReady(httpPort))" in probe
    assert "SystemClock.elapsedRealtime() + 3_200L" in probe
    assert "connection.connectTimeout = 1_500" in probe
    assert "connection.readTimeout = 1_500" in probe
    assert 'add("http://1.1.1.1/cdn-cgi/trace")' in probe


def test_startup_watchdog_cannot_race_a_running_core_verification():
    home = text("android-source/BlueVpnHomeActivity.kt")
    observer = home[home.index("mainViewModel.isRunning.observe"): home.index("mainViewModel.updateTestResultAction.observe")]
    assert "active && failoverActive" in observer
    branch = observer[observer.index("active && failoverActive"): observer.index("active && connectionVerified")]
    assert "handler.removeCallbacks(attemptTimeout)" in branch
    assert "scheduleConnectionVerification()" in branch


def test_failed_end_to_end_route_is_quarantined_only_for_current_cycle():
    home = text("android-source/BlueVpnHomeActivity.kt")
    locations = text("android-source/BlueVpnLocationUtil.kt")

    fail = home[home.index("private fun failCurrentAndTryNext"): home.index("private fun finishFailoverWithError")]
    assert "BlueVpnPreferences.markSessionInactive(this, failedGuid)" in fail
    assert "BlueVpnPreferences.markServerFailure(this, failedGuid)" in fail
    begin = home[home.index("private fun beginSmartConnection()"): home.index("private fun startSmartConnectionWithCandidates")]
    assert "BlueVpnPreferences.beginHealthSession(this)" in begin
    health = locations[locations.index("fun beginHealthSession"): locations.index("fun markSessionInactive")]
    assert "SESSION_INACTIVE_PREFIX" in health


def test_bluevpn_does_not_preempt_upstream_tls_profile_parsing():
    locations = text("android-source/BlueVpnLocationUtil.kt")
    home = text("android-source/BlueVpnHomeActivity.kt")
    prepare = text("scripts/prepare_android.py")

    assert "containsRemovedTlsOption" not in locations
    assert "compatibilityIssue(" not in locations
    assert "BlueVpnLocationUtil.compatibilityIssue(" not in home
    patch = prepare[prepare.index("def patch_legacy_tls_profiles"): prepare.index("def inject_bootstrap")]
    assert "Keep upstream v2rayNG TLS/profile parsing unchanged" in patch
    assert "config.insecure == true" not in patch
