from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def text(rel: str) -> str:
    return (ROOT / rel).read_text(encoding="utf-8")


def test_locations_does_not_force_sync_on_open():
    source = text("android-source/BlueVpnServersActivity.kt")
    on_create = source.split("override fun onCreate", 1)[1].split("override fun onResume", 1)[0]
    assert "refreshEntitlementState(force = true)" not in on_create
    assert "force = force," in source


def test_premium_lkg_is_account_bound_and_excludes_free():
    source = text("android-source/BlueVpnAccountManager.kt")
    assert "premiumOwnerKey" in source
    assert "premiumLastKnownGoodServerGuids" in source
    assert "allFreeServerGuids" in source
    assert "it.isNotBlank() && it !in free" in source
    assert "MmkvManager.decodeAllServerList(" not in source.split("fun preferredServerGuids", 1)[1].split("fun entitlementPoolFingerprint", 1)[0]


def test_connect_and_subscription_import_are_mutually_exclusive():
    gate = text("android-source/BlueVpnRuntimeGate.kt")
    intel = text("android-source/BlueVpnSubscriptionIntelligence.kt")
    account = text("android-source/BlueVpnAccountManager.kt")
    assert "beginConnection" in gate
    assert "beginSubscriptionMutation" in gate
    assert "beginSubscriptionMutation(context)" in intel
    assert "effectiveForce = force && !BlueVpnRuntimeGate.connectionActive(c)" in account
    assert "if (BlueVpnRuntimeGate.connectionActive(c)) return 0" in account
    assert "return@runCatching preferredServerGuids(appContext).size" in account


def test_home_accepts_only_service_reported_exact_guid():
    source = text("android-source/BlueVpnHomeActivity.kt")
    assert "mainViewModel.runningServerGuid.value" in source
    assert "runningGuid == attemptedGuid" in source
    assert "scoredQueue.take(5)" in source
    assert "round < 2" in source


def test_engine_uses_patched_exact_start():
    source = text("android-source/BlueVpnEngineManager.kt")
    assert "startVServiceExact(app, targetGuid)" in source
    assert "CoreServiceManager.isRunning()" not in source
    assert "fun markIdle()" in source


def test_home_fast_fails_daemon_start_error():
    source = text("android-source/BlueVpnHomeActivity.kt")
    assert "mainViewModel.coreStartError.observe(this)" in source
    assert "failCurrentAndTryNext(error)" in source


def test_prepare_android_patches_real_v2rayng_runtime():
    source = text("scripts/prepare_android.py")
    for expected in (
        'intent.putExtra("bluevpn_target_guid", guid)',
        'fun getRunningServerGuid() = currentGuid.orEmpty()',
        'CoreVpnService lives in :RunSoLibV2RayDaemon',
        'Reject overlapping start',
        'MSG_STATE_START_SUCCESS, guid',
        'MSG_STATE_RUNNING, currentGuid.orEmpty()',
        '@Synchronized\n    fun stopCoreLoop(): Boolean',
        'runningServerGuid by lazy',
        'coreStartError by lazy',
        'if (setupVpnService())',
        'val coreStopped = CoreServiceManager.stopCoreLoop()',
    ):
        assert expected in source


def test_ai_has_no_direct_forced_account_sync():
    source = text("android-source/BlueVpnHomeActivity.kt")
    section = source.split("private fun runSmartSelection()", 1)[1].split("private fun", 1)[0]
    assert not ("BlueVpnAccountManager.sync(" in section and "force = true" in section)
