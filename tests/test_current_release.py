from __future__ import annotations

import json
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def text(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def test_release_validator_passes() -> None:
    result = subprocess.run(
        ["python", "scripts/validate_release.py"],
        cwd=ROOT,
        text=True,
        capture_output=True,
        check=False,
    )
    assert result.returncode == 0, result.stdout + result.stderr


def test_short_version_and_core_pins() -> None:
    app = json.loads(text("branding/app.json"))
    assert app["version_name"] == "4.1.7"
    assert app["version_code"] == 40107
    assert int(app["version_name"].split(".")[-1]) <= 10
    assert app["upstream_ref"] == "2.2.6"
    assert app["xray_ref"] == "v26.6.27"


def test_free_premium_pool_isolation_contract() -> None:
    account = text("android-source/BlueVpnAccountManager.kt")
    auth = text("bluevpn-manager/includes/class-bluevpn-auth.php")
    assert "pool_identity" in auth
    assert "poolIdentity" in account
    assert "autoUpdate = false" in account
    assert "return guid in entitlementServerGuids" in account
    assert "scheduleInstall(" not in account


def test_ai_is_read_only_on_selection_tap() -> None:
    home = text("android-source/BlueVpnHomeActivity.kt")
    start = home.index("    private fun runSmartSelection()")
    end = home.index("    private fun showConnectingOverlay", start)
    block = home[start:end]
    assert "awaitEntitlementServers" not in block
    assert "prepareFreeAccess" not in block
    assert block.index("BlueVpnSmartSelector.decide") < block.index("BlueVpnAi.refreshRecommendations")


def test_ci_does_not_mutate_source_branch_or_store_generated_source() -> None:
    workflow = text(".github/workflows/build-apk.yml")
    assert "git push" not in workflow
    assert "git commit" not in workflow
    assert "android-source/generated" not in workflow
    assert 'git checkout --force "$XRAY_REF"' in workflow


def test_account_navigation_does_not_force_provider_sync() -> None:
    account_ui = text("android-source/BlueVpnSubscriptionsActivity.kt")
    start = account_ui.index(" override fun onResume()")
    end = account_ui.index(" override fun onPause()", start)
    block = account_ui[start:end]
    assert "sync(false)" in block
    assert "sync(true)" not in block


def test_subscription_mutation_is_atomic_and_deferred() -> None:
    account = text("android-source/BlueVpnAccountManager.kt")
    intelligence = text("android-source/BlueVpnSubscriptionIntelligence.kt")
    home = text("android-source/BlueVpnHomeActivity.kt")
    assert "private fun install(c: Context, url: String)" not in account
    assert "refreshWithinMutation" in intelligence
    assert "beginSubscriptionMutation" in account
    assert "reconcilePendingEntitlement" in account
    assert "reconcileDeferredEntitlementIfIdle(retryConnection = true)" in home
    assert "beginSmartConnection()\n                        beginSmartConnection()" not in home


def test_wordpress_account_read_is_snapshot_only_and_sync_is_background() -> None:
    api = text("bluevpn-manager/includes/class-bluevpn-api.php")
    providers = text("bluevpn-manager/includes/class-bluevpn-providers.php")
    account_start = api.index("    public static function account(WP_REST_Request $r)")
    sync_start = api.index("    public static function account_sync(WP_REST_Request $r)", account_start)
    next_start = api.index("    public static function resolve_locations", sync_start)
    account_block = api[account_start:sync_start]
    sync_block = api[sync_start:next_start]
    assert "request_background_sync" not in account_block
    assert "sync_customer" not in account_block
    assert "source'=>'wordpress_snapshot'" in account_block
    assert "request_background_sync" in sync_block
    assert "sync_customer" not in sync_block
    assert "wp_next_scheduled('bluevpn_sync_customer_async'" in providers
    assert "self::sync_customer($customerId,false)" in providers
    assert "private const SYNC_TTL_SECONDS = 300" in providers


def test_terminal_failure_cannot_reenter_connecting_or_release_gate_early() -> None:
    home = text("android-source/BlueVpnHomeActivity.kt")
    observer_start = home.index("        mainViewModel.isRunning.observe(this) { running ->")
    observer_end = home.index("        mainViewModel.coreStartError.observe(this)", observer_start)
    observer = home[observer_start:observer_end]
    assert observer.index("if (terminalFailureStopping)") < observer.index("if (userDisconnecting)")
    assert "return@observe" in observer[observer.index("if (terminalFailureStopping)"):observer.index("if (userDisconnecting)")]

    finish_start = home.index("    private fun finishFailoverWithError(")
    finish_end = home.index("    private fun cancelFailover()", finish_start)
    finish = home[finish_start:finish_end]
    assert "terminalFailureStopping = mainViewModel.isRunning.value == true" in finish
    assert "if (terminalFailureStopping)" in finish
    assert "BlueVpnRuntimeGate.endConnection(this)" in finish

    ping_start = home.index("        mainViewModel.updateTestResultAction.observe(this) { result ->")
    ping_end = home.index("    private fun parseV2rayNgDelayMs", ping_start)
    ping = home[ping_start:ping_end]
    assert "completeFailover(upstreamDelay)" not in ping
    assert "Quality telemetry only" in ping

def test_last_candidate_failure_reason_is_preserved() -> None:
    home = text("android-source/BlueVpnHomeActivity.kt")
    start = home.index("    private fun failCurrentAndTryNext(")
    end = home.index("    private fun finishFailoverWithError(", start)
    block = home[start:end]
    assert "lastCandidateFailureReason = reason.trim()" in block
    assert "finishFailoverWithError(lastCandidateFailureReason)" in block


def test_internal_routes_are_hidden_behind_locations() -> None:
    locations = text("android-source/BlueVpnServersActivity.kt")
    preferences = text("android-source/BlueVpnLocationUtil.kt")
    home = text("android-source/BlueVpnHomeActivity.kt")

    assert "private fun createServerEntry(" not in locations
    assert "private fun selectServer(" not in locations
    assert "routeLabel(" not in locations
    assert "selectGroup(" in locations
    assert "Selecting a country never exposes or pins a concrete route" in locations
    assert "parsed == BlueVpnSelectionMode.MANUAL_SERVER" in preferences
    assert ".remove(KEY_MANUAL_SERVER_GUID)" in preferences
    assert "fun setManualServerSelection(" not in preferences
    assert "$locationCount لوکیشن" in home
    assert "$usableCount مسیر" not in home
    assert "$routeCount مسیر آماده" not in home


def test_hidden_route_is_hydrated_by_v2rayng_before_tun_start() -> None:
    engine = text("android-source/BlueVpnEngineManager.kt")
    home = text("android-source/BlueVpnHomeActivity.kt")
    prepare = text("scripts/prepare_android.py")

    assert "CoreConfigManager.getV2rayConfig(app, guid)" in engine
    assert "result.status && result.content.isNotBlank()" in engine
    preflight_pos = home.index("BlueVpnEngineManager.validateExactConfig(")
    endpoint_pos = home.index("BlueVpnLocationUtil.preflightCandidate(", preflight_pos)
    core_start_pos = home.index("startExactCandidateCore(guid)", endpoint_pos)
    assert preflight_pos < endpoint_pos < core_start_pos
    runtime_patch = prepare[prepare.index("def patch_v2rayng_runtime_lifecycle() -> None:"):prepare.index("def inject_bootstrap() -> None:")]
    assert "CoreVpnService.kt" not in runtime_patch
    assert "CoreServiceManager.kt" not in runtime_patch
    assert "coreStartError" in runtime_patch
    assert "CoreServiceManager.startVService(app, targetGuid)" in engine
    assert "startVServiceExact" not in engine

def test_home_ai_summary_is_location_only_and_cannot_show_stale_route_errors() -> None:
    selector = text("android-source/BlueVpnSmartSelector.kt")
    ai = text("android-source/BlueVpnAi.kt")
    start = selector.index("    fun lastSummary(context: Context): String")
    end = selector.index("    fun clear(context: Context)", start)
    block = selector[start:end]
    assert "val selectedGuid = MmkvManager.getSelectServer().orEmpty()" in block
    assert "sequenceOf(selectedGuid, storedGuid)" in block
    assert "امتیاز $score" not in block
    assert "$reason" not in block
    assert "خطای پیاپی" not in block
    local_start = ai.index("    fun localSummary(context: Context): String")
    local_end = ai.index("    fun onEntitlementChanged", local_start)
    local = ai[local_start:local_end]
    assert "سیگنال" not in local
    assert "دیتای موبایل" in local


def test_home_merges_location_and_status_and_exposes_no_ai_section() -> None:
    home = text("android-source/BlueVpnHomeActivity.kt")
    prepare = text("scripts/prepare_android.py")
    ids = text("android-source/bluevpn_ids.xml")

    screen_start = home.index("    private fun createScreen(): View")
    screen_end = home.index("    private fun createHeader(): View", screen_start)
    screen = home[screen_start:screen_end]
    server_start = home.index("    private fun createServerCard(): View")
    server_end = home.index("    private fun createModeRow(): View", server_start)
    server = home[server_start:server_end]

    assert screen.count("createServerCard()") == 1
    assert "createAiCard()" not in home
    assert "bluevpn_ai_card" not in ids
    assert "R.id.bluevpn_ai_card" not in home
    assert "serverStatusValue" in server
    assert "مسیرهای این لوکیشن مخفی هستند" in home
    assert "BlueVpnAiActivity.kt" not in prepare
    assert not (ROOT / "android-source/BlueVpnAiActivity.kt").exists()


def test_internal_intelligence_stays_background_only() -> None:
    home = text("android-source/BlueVpnHomeActivity.kt")
    assert "BlueVpnAi.verifyTunnel" in home
    assert "BlueVpnAi.startSession" in home
    assert '"BlueAI •' not in home
    assert '"BlueAI افت کیفیت' not in home


def test_v2rayng_compatibility_failover_never_discards_hidden_routes() -> None:
    home = text("android-source/BlueVpnHomeActivity.kt")
    assert "scoredQueue.take(5)" not in home
    assert ".take(18)" not in home
    assert "maxCandidates = 10" not in home
    assert "BlueVpnLocationUtil.allCandidates(" in home
    assert "failoverReserveQueue" in home
    assert "failoverReserveQueue.take(8)" in home
    assert "BlueVpnSelectionMode.MANUAL_LOCATION ->" in home
    assert "failoverQueue = orderedGuids" in home


def test_xray_cold_start_and_local_proxy_windows_are_not_over_aggressive() -> None:
    home = text("android-source/BlueVpnHomeActivity.kt")
    assert "handler.postDelayed(attemptTimeout, 24_000L)" in home
    assert "maxWaitMs: Long = 5_000L" in home
    assert "SystemClock.elapsedRealtime() + 6_500L" in home
    assert "connectTimeout = 3_000" in home
    assert "readTimeout = 3_000" in home


def test_connection_state_follows_upstream_v2rayng_and_quality_probes_are_non_authoritative() -> None:
    home = text("android-source/BlueVpnHomeActivity.kt")
    engine = text("android-source/BlueVpnEngineManager.kt")
    settings_start = home.index("    private fun enforceReliableVpnSettings()")
    settings_end = home.index("    private fun renderVerifyingState", settings_start)
    settings = home[settings_start:settings_end]
    observer_start = home.index("        mainViewModel.isRunning.observe(this) { running ->")
    observer_end = home.index("        mainViewModel.coreStartError.observe(this)", observer_start)
    observer = home[observer_start:observer_end]
    assert "CoreServiceManager.startVService(app, targetGuid)" in engine
    assert "startVServiceExact" not in engine
    assert "completeFailover(null)" in observer
    assert "AppConfig.PREF_DYNAMIC_SOCKS_PORT" not in settings
    assert "AppConfig.PREF_ENABLE_LOCAL_PROXY" not in settings
    assert "SettingsManager.getSocksPort()" in home
    stats = home[home.index("    private val statsTicker"):home.index("    private val freeSessionTicker")]
    assert "monitorBlueAiHealth()" not in stats

def test_profile_dedupe_keeps_v2rayng_runtime_affecting_variants_distinct() -> None:
    profile = text("android-source/BlueVpnProfileManager.kt")
    for getter in (
        "getBrowserDialerMode",
        "getProxyChainProfiles",
        "getPolicyGroupType",
        "getPolicyGroupSubscriptionId",
        "getPolicyGroupFilter",
    ):
        assert getter in profile


def test_custom_xray_json_is_not_dropped_before_upstream_runtime_validation() -> None:
    location = text("android-source/BlueVpnLocationUtil.kt")
    assert "sourceFormat == BlueVpnProfileManager.SourceFormat.XRAY_JSON" in location
    assert "CoreConfigManager validate these profiles" in location
    assert "return raw.isNotBlank()" in location
