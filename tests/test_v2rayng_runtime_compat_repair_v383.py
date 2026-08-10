from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def _read(rel: str) -> str:
    return (ROOT / rel).read_text(encoding="utf-8")


def test_xray_start_uses_exact_v2rayng_guid_and_entitlement():
    engine = _read("android-source/BlueVpnEngineManager.kt")
    assert "fun start(context: Context, serverGuid: String? = null)" in engine
    assert "CoreServiceManager.startVService(app, targetGuid)" in engine
    assert "BlueVpnAccountManager.candidateAllowed(" in engine
    assert "MmkvManager.decodeServerConfig(it)" in engine


def test_route_switch_waits_for_confirmed_core_stop_instead_of_90ms_race():
    home = _read("android-source/BlueVpnHomeActivity.kt")
    assert "private var waitingForCoreStop = false" in home
    assert "!active && failoverActive && waitingForCoreStop" in home
    assert "startExactCandidateCore(guid)" in home
    assert "handler.postDelayed(startCore, 90L)" not in home
    # HomeActivity must coordinate through MainViewModel service state rather
    # than importing the service-process singleton directly.
    assert "CoreServiceManager" not in home


def test_next_route_is_not_preselected_before_old_core_stops():
    home = _read("android-source/BlueVpnHomeActivity.kt")
    block = home[home.index("private fun startSmartConnectionWithCandidates"):
                 home.index("private fun startCurrentCandidate")]
    assert "MmkvManager.setSelectServer(chosen.candidate.guid)" not in block


def test_v2rayng_native_delay_is_a_parallel_compatibility_proof():
    home = _read("android-source/BlueVpnHomeActivity.kt")
    assert "private fun parseV2rayNgDelayMs" in home
    assert "'۰' -> '0'" in home
    assert "'۹' -> '9'" in home
    assert "'٠' -> '0'" in home
    assert "'٩' -> '9'" in home
    assert "mainViewModel.testCurrentServerRealPing()" in home
    assert "completeFailover(upstreamDelay)" in home


def test_health_verification_is_multi_round_and_non_destructive_for_existing_core():
    home = _read("android-source/BlueVpnHomeActivity.kt")
    verify = home[home.index("private fun verifyTunnelThroughCore"):
                  home.index("private fun waitForLocalProxyReady")]
    assert "verificationRound += 1" in verify
    assert "round < 3" in verify
    assert "mainViewModel.testCurrentServerRealPing()" in verify

    existing = home[home.index("private fun verifyExistingRunningSession"):
                    home.index("private fun verifyTunnelThroughCore")]
    assert "BlueVpnEngineManager.stop(" not in existing
    assert "هسته Xray فعال است؛ تأیید اینترنت در پس‌زمینه ادامه دارد" in existing


def test_core_stop_timeout_does_not_poison_unattempted_candidate():
    home = _read("android-source/BlueVpnHomeActivity.kt")
    block = home[home.index("private val coreStopTimeout"):
                 home.index("private val requestPing")]
    assert "finishFailoverWithError" in block
    assert "failCurrentAndTryNext" not in block
