from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def require(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def function_block(source: str, signature: str, next_signature: str | None = None) -> str:
    start = source.find(signature)
    require(start >= 0, f"missing block: {signature}")
    if next_signature:
        end = source.find(next_signature, start + len(signature))
        require(end > start, f"missing end marker after {signature}: {next_signature}")
        return source[start:end]
    return source[start:]


def version_tuple(value: str) -> tuple[int, int, int]:
    m = re.fullmatch(r"(\d+)\.(\d+)\.(\d+)", value.strip())
    require(m is not None, f"invalid semantic version: {value}")
    return tuple(map(int, m.groups()))  # type: ignore[return-value]


def main() -> None:
    app = json.loads(read("branding/app.json"))
    release = json.loads(read("release.json"))
    manager = read("bluevpn-manager/bluevpn-manager.php")
    manager_readme = read("bluevpn-manager/readme.txt")
    db = read("bluevpn-manager/includes/class-bluevpn-db.php")
    auth = read("bluevpn-manager/includes/class-bluevpn-auth.php")
    account = read("android-source/BlueVpnAccountManager.kt")
    entitlement = read("android-source/BlueVpnEntitlement.kt")
    home = read("android-source/BlueVpnHomeActivity.kt")
    subscriptions_ui = read("android-source/BlueVpnSubscriptionsActivity.kt")
    subscription_intelligence = read("android-source/BlueVpnSubscriptionIntelligence.kt")
    runtime_gate = read("android-source/BlueVpnRuntimeGate.kt")
    engine = read("android-source/BlueVpnEngineManager.kt")
    prepare = read("scripts/prepare_android.py")
    workflow = read(".github/workflows/build-apk.yml")
    manager_workflow = read(".github/workflows/bluevpn-manager-release.yml")
    api = read("bluevpn-manager/includes/class-bluevpn-api.php")
    providers = read("bluevpn-manager/includes/class-bluevpn-providers.php")

    version = str(app["version_name"])
    code = int(app["version_code"])
    major, minor, patch = version_tuple(version)
    require(version == release.get("version") == release.get("android_version"), "release version metadata diverged")
    require(code == int(release.get("version_code", -1)) == int(release.get("android_version_code", -2)), "release version codes diverged")
    require(code == major * 10000 + minor * 100 + patch, "version_code does not match short semantic version")
    require(0 <= patch <= 10, "BlueVPN patch series must stay within x.y.0..x.y.10")
    require(version == "4.1.7" and code == 40107, "this upstream-runtime restore source must be 4.1.7 / 40107")
    require(app.get("upstream_ref") == "2.2.6", "production upstream must remain pinned to reviewed stable v2rayNG 2.2.6")
    require(app.get("xray_ref") == "v26.6.27", "Xray must match the v2rayNG 2.2.6 production pairing (v26.6.27)")
    require("v2rayng-2.3.3-compatibility-reviewed" in release.get("features", []), "2.3.3 compatibility review marker missing")
    require("xray-core-v26.6.27-upstream-pairing" in release.get("features", []), "Xray/v2rayNG exact pairing marker missing")
    require("location-only-public-selection" in release.get("features", []), "location-only selection marker missing")

    locations_ui = read("android-source/BlueVpnServersActivity.kt")
    location_util = read("android-source/BlueVpnLocationUtil.kt")
    require("private fun createServerEntry(" not in locations_ui, "per-route entries are still rendered in Locations")
    require("private fun selectServer(" not in locations_ui, "user-facing exact route selection still exists")
    require("routeLabel(" not in locations_ui, "route names are still exposed in Locations")
    require("selectGroup(" in locations_ui and "Selecting a country never exposes or pins a concrete route" in locations_ui, "location card does not own hidden-route selection")
    require("parsed == BlueVpnSelectionMode.MANUAL_SERVER" in location_util and ".remove(KEY_MANUAL_SERVER_GUID)" in location_util, "legacy manual-route preference migration missing")
    require("fun setManualServerSelection(" not in location_util, "public preference API can still pin an exact hidden route")
    require("$locationCount لوکیشن" in home and "$usableCount مسیر" not in home, "Home still exposes route counts")
    selector = read("android-source/BlueVpnSmartSelector.kt")
    ai = read("android-source/BlueVpnAi.kt")
    public_summary = function_block(selector, "    fun lastSummary(context: Context): String", "    fun clear(context: Context)")
    require("val selectedGuid = MmkvManager.getSelectServer().orEmpty()" in public_summary, "Home AI summary does not follow current selected GUID")
    require("sequenceOf(selectedGuid, storedGuid)" in public_summary, "stale AI decision can still override current Home selection")
    require("امتیاز $score" not in public_summary and "$reason" not in public_summary and "خطای پیاپی" not in public_summary, "route-level health evidence still leaks into public Home summary")
    ai_summary = function_block(ai, "    fun localSummary(context: Context): String", "    fun onEntitlementChanged")
    require("سیگنال" not in ai_summary and "دیتای موبایل" in ai_summary, "AI Home summary still exposes raw learned-signal internals")

    require("Version: 4.1.7" in manager, "WordPress plugin header version mismatch")
    require("BLUEVPN_MANAGER_VERSION', '4.1.7'" in manager, "WordPress plugin constant mismatch")
    require("BLUEVPN_MANAGER_SCHEMA_VERSION', '1.6.0'" in manager, "WordPress schema must be 1.6.0")
    require("Version: 4.1.7" in manager_readme and "Stable tag: 4.1.7" in manager_readme, "WordPress readme version/stable tag mismatch")

    # Paid/free ownership is server-authored and propagated all the way to the Android selector.
    require("'pool_identity' => $poolIdentity" in auth, "WordPress account payload does not expose pool_identity")
    require("$poolIdentity = hash('sha256'" in auth, "WordPress pool identity is not stable-hashed")
    require("val poolIdentity: String" in account, "Android account snapshot does not carry pool identity")
    require('optString("pool_identity")' in account and '.putString("pool_identity", poolIdentity)' in account, "Android pool identity persistence missing")
    require("account.poolIdentity.ifBlank" in entitlement, "entitlement identity ignores server-authored pool identity")
    require("before.poolIdentity != after.poolIdentity" in home, "Home does not reload after paid pool ownership changes")
    require("account.poolIdentity.ifBlank" in account, "account entitlement fingerprint ignores pool identity")
    require("forceRefresh = true" in function_block(account, "    private fun applyAccount(", "    private fun request("), "pool identity changes are not forcing one authoritative subscription refresh")
    require("scheduleInstall(" not in account, "obsolete async subscription scheduler still exists")
    require("private fun install(c: Context, url: String)" not in account, "dead duplicate Premium installer still exists")
    require("refreshWithinMutation" in subscription_intelligence, "atomic subscription refresh entrypoint missing")
    reconcile_block = function_block(account, "    private fun reconcileSubscriptionMode(", "    fun reconcilePendingEntitlement(")
    require("beginSubscriptionMutation" in reconcile_block and "refreshWithinMutation" in reconcile_block, "Premium/Free metadata swap is not atomic with subscription import")
    free_install_block = function_block(account, "    private fun installFreeSubscriptions(", "    private fun configuredFreeSubscriptionGuids")
    require("beginSubscriptionMutation" in free_install_block and "refreshWithinMutation" in free_install_block, "Free pool install is not one atomic mutation transaction")
    require("entitlementReconcilePending" in account and "reconcilePendingEntitlement" in account, "deferred entitlement reconciliation is missing")

    # Subscription sync is serialized and forced provider polls are coalesced.
    require("private val accountSyncLock = Any()" in account, "account sync serialization lock missing")
    sync_block = function_block(account, "    fun sync(\n", "    fun plans(")
    require("synchronized(accountSyncLock)" in sync_block, "account sync is not serialized")
    require("lastForcedAccountSyncAt < 4_000L" in sync_block, "forced account sync coalescing missing")
    require('if (effectiveForce) "/api/v1/account/sync" else "/api/v1/account"' in sync_block, "routine vs forced account endpoints are not separated")
    require("forceSubscriptions = effectiveForce" in sync_block, "subscription mutation is not tied to effective forced sync")

    # WordPress GET /account must be a pure snapshot read. Explicit sync is queued,
    # deduplicated and still observes the provider TTL instead of blocking the phone.
    api_account_block = function_block(api, "    public static function account(WP_REST_Request $r)", "    public static function account_sync(WP_REST_Request $r)")
    api_sync_block = function_block(api, "    public static function account_sync(WP_REST_Request $r)", "    public static function resolve_locations(WP_REST_Request $r)")
    require("sync_customer" not in api_account_block and "request_background_sync" not in api_account_block, "GET /account still triggers provider I/O")
    require("source'=>'wordpress_snapshot'" in api_account_block, "GET /account is not explicitly snapshot-only")
    require("request_background_sync" in api_sync_block and "sync_customer" not in api_sync_block, "POST /account/sync must queue provider sync instead of blocking the request")
    require("wp_next_scheduled('bluevpn_sync_customer_async'" in providers, "provider background sync is not deduplicated")
    require("self::sync_customer($customerId,false)" in providers, "background provider sync bypasses TTL")
    require("private const SYNC_TTL_SECONDS = 300" in providers, "provider sync TTL contract changed unexpectedly")

    # v2rayNG rows are explicitly non-auto-updating and exact entitlement filters own selection.
    require(account.count("autoUpdate = false") >= 4, "managed v2rayNG subscriptions must have autoUpdate disabled")
    require("fun entitlementSubscriptionGuids" in account, "entitlement subscription ownership helper missing")
    require("fun candidateAllowed(" in account and "return guid in entitlementServerGuids" in account, "candidate selection is not strict to the entitlement pool")
    require("premiumLastKnownGoodServerGuids" in account and "allFreeServerGuids" in account, "Premium fallback is not isolated from Free pool")

    # Ordinary account navigation is cache-first; provider force-sync is reserved for real activation/manual actions.
    require("sync(false)" in function_block(subscriptions_ui, " override fun onResume()", " override fun onPause()"), "account screen resume still force-syncs providers")
    require("sync(true)" not in function_block(subscriptions_ui, " override fun onResume()", " override fun onPause()"), "account screen onResume performs a forced sync")
    require("result.resultCode == RESULT_OK" in home, "Home forces account sync after an ordinary Account Back navigation")

    # Runtime lifecycle: no infinite CONNECTING wait and no mutation while Xray owns MMKV.
    require("subscriptionMutationActive" in runtime_gate and "connectionActive" in runtime_gate, "runtime mutation/connection gate missing")
    require("waitedMs >= 6_000L" in home, "connection gate does not have a bounded UI wait")
    require('updateConnectLabel("تلاش دوباره")' in home, "bounded gate does not restore user control")
    require("handler.postDelayed(attemptTimeout, 24_000L)" in home, "Xray cold-start window is not 24 seconds")
    require("completeFailover(null)" in home, "upstream RUNNING is not accepted as connection success")
    require("maxWaitMs: Long = 5_000L" in home, "local proxy readiness window is not 5 seconds")
    require("scoredQueue.take(5)" not in home and ".take(18)" not in home, "hidden route pool is still truncated before failover")
    require("failoverReserveQueue" in home and "failoverReserveQueue.take(8)" in home, "AUTO progressive reserve failover missing")
    require("BlueVpnLocationUtil.allCandidates(" in home and "maxCandidates = 10" not in home, "cold-start candidate enumeration still drops lower-ranked routes")
    require("SettingsManager.getSocksPort()" in home, "background tunnel telemetry lost canonical SOCKS port")
    require("AppConfig.PREF_DYNAMIC_SOCKS_PORT" not in function_block(home, "    private fun enforceReliableVpnSettings()", "    private fun renderVerifyingState()"), "BlueVPN still overrides upstream dynamic SOCKS behavior")
    require("Proxy.Type.SOCKS" in home and "Proxy-Authorization" in home, "local proxy compatibility/auth fallback missing")
    require("code in 200..499 && code != 407" in home, "tunnel proof is still tied to exact public endpoint body/status")
    profile_manager = read("android-source/BlueVpnProfileManager.kt")
    require("getBrowserDialerMode" in profile_manager and "getProxyChainProfiles" in profile_manager, "runtime-affecting browser/proxy-chain fields are missing from dedupe fingerprint")
    require("CoreServiceManager.startVService(app, targetGuid)" in engine and "startVServiceExact" not in engine, "BlueVPN does not use stock v2rayNG start path")
    require("CoreConfigManager.getV2rayConfig(app, guid)" in engine, "hidden route is not hydrated through v2rayNG config builder before connect")
    require("result.status && result.content.isNotBlank()" in engine, "runtime config hydration is not upstream-compatible")
    require("CoreVpnService.kt" not in function_block(prepare, "def patch_v2rayng_runtime_lifecycle()", "def inject_bootstrap()"), "prepare_android still patches CoreVpnService lifecycle")
    require("CoreServiceManager.kt" not in function_block(prepare, "def patch_v2rayng_runtime_lifecycle()", "def inject_bootstrap()"), "prepare_android still patches CoreServiceManager lifecycle")
    require("coreStartError" in function_block(prepare, "def patch_v2rayng_runtime_lifecycle()", "def inject_bootstrap()"), "read-only core start diagnostics missing")
    require("blueVpnTargetGuid" not in prepare, "legacy forked runtime target field remains")
    require("CoreServiceManager" not in function_block(home, "class BlueVpnHomeActivity"), "UI must not directly depend on CoreServiceManager")
    require("beginSmartConnection()\n                        beginSmartConnection()" not in home, "connection retry starts duplicate concurrent connect cycles")
    require("reconcileDeferredEntitlementIfIdle(retryConnection = true)" in home, "pending plan changes are not reconciled before the next connection")
    require("terminalFailureStopping" in home, "terminal failover stop state missing")
    observer = function_block(home, "        mainViewModel.isRunning.observe(this) { running ->", "        mainViewModel.coreStartError.observe(this)")
    require(observer.find("if (terminalFailureStopping)") < observer.find("if (userDisconnecting)"), "terminal failure must be handled before generic running-session verification")
    require("return@observe" in observer[observer.find("if (terminalFailureStopping)"):observer.find("if (userDisconnecting)")], "terminal failure observer does not short-circuit stale RUNNING state")
    failure_block = function_block(home, "    private fun finishFailoverWithError(", "    private fun cancelFailover()")
    require("terminalFailureStopping = mainViewModel.isRunning.value == true" in failure_block, "terminal failure does not latch daemon-stop state")
    require("BlueVpnRuntimeGate.endConnection(this)" in failure_block and "if (terminalFailureStopping)" in failure_block, "terminal failure stop barrier/gate ownership missing")
    fail_next_block = function_block(home, "    private fun failCurrentAndTryNext(", "    private fun finishFailoverWithError(")
    require("lastCandidateFailureReason = reason.trim()" in fail_next_block and "finishFailoverWithError(lastCandidateFailureReason)" in fail_next_block, "last candidate error is not preserved")
    verify_existing = function_block(home, "    private fun verifyExistingRunningSession(", "    private fun verifyTunnelThroughCore(")
    require("terminalFailureStopping" in verify_existing and "if (terminalFailureStopping || userDisconnecting || failoverActive)" in verify_existing, "late existing-session probe can resurrect terminal failure")
    require("completeFailover(upstreamDelay)" not in function_block(home, "        mainViewModel.updateTestResultAction.observe(this) { result ->", "    private fun parseV2rayNgDelayMs"), "quality ping still controls connection state")

    # AI must be locally authoritative and must not repair/import subscriptions on a tap.
    ai_start = home.find("    private fun runSmartSelection()")
    require(ai_start >= 0, "runSmartSelection block not found")
    next_function = re.search(r"(?m)^    private fun [A-Za-z0-9_]+\(", home[ai_start + 1 :])
    require(next_function is not None, "runSmartSelection end marker not found")
    ai_end = ai_start + 1 + next_function.start()
    ai_block = home[ai_start:ai_end]
    require("awaitEntitlementServers" not in ai_block and "prepareFreeAccess" not in ai_block, "AI tap still mutates subscription state")
    decision_pos = ai_block.find("BlueVpnSmartSelector.decide")
    cloud_pos = ai_block.find("BlueVpnAi.refreshRecommendations")
    require(decision_pos >= 0 and cloud_pos > decision_pos, "AI cloud refresh still blocks local route decision")
    require("lifecycleScope.launch(Dispatchers.IO)" in ai_block, "AI cloud enrichment is not backgrounded")
    require("monitorBlueAiHealth()" not in function_block(home, "    private val statsTicker", "    private val freeSessionTicker"), "hidden AI still auto-heals/reconnects the VPN")

    # Startup must never turn an inactive paid entitlement into an implicit provider force-sync.
    startup = function_block(home, "private fun startStartupOptimization()", "private fun startStartupServerTest()")
    require("syncManagedAccount(force = false)" in startup, "startup must use routine account sync only")
    require("force = !BlueVpnAccountManager.snapshot" not in startup, "legacy forced startup provider sync remains")

    # MySQL hot paths used by auth, orders, AI and provider refresh must be indexed.
    for marker in (
        "ix_customer_entitlement",
        "ix_customer_sync_due",
        "ix_session_customer_active",
        "ix_device_customer_active_seen",
        "ix_order_customer_status_created",
        "ix_order_status_expiry",
        "ix_webhook_payment_event_created",
        "ix_ai_event_customer_created",
        "ix_ai_event_device_created",
        "ix_ai_live_device_state",
    ):
        require(marker in db, f"missing MySQL index: {marker}")
    require("ensure_customer_nullable_unique_columns" in db, "nullable unique migration guard missing")

    # Generator is now single-source for Kotlin instead of carrying duplicate base64 snapshots.
    require("Kotlin is single-source" in prepare, "prepare_android does not document canonical Kotlin source")
    for duplicate in (
        "BLUEVPN_HOME_ACTIVITY_B64",
        "BLUEVPN_ACCOUNT_MANAGER_B64",
        "BLUEVPN_AI_MANAGER_B64",
        "BLUEVPN_SUBSCRIPTIONS_ACTIVITY_B64",
    ):
        require(duplicate not in prepare, f"duplicate embedded Kotlin source remains: {duplicate}")
    require("ROOT / \"android-source/BlueVpnHomeActivity.kt\"" in prepare, "canonical Home source is not copied into upstream")
    require("ROOT / \"android-source/BlueVpnAccountManager.kt\"" in prepare, "canonical Account source is not copied into upstream")

    # CI release hygiene and short version series.
    require("x.y.0 ... x.y.10" in workflow, "short patch policy missing from workflow")
    require("if patch >= 10:" in workflow and "patch = 0" in workflow, "x.y.10 rollover logic missing")
    require("android-source/generated" not in workflow, "generated Android source is still persisted to GitHub")
    require('xray_ref=$(python -c' in workflow and 'git checkout --force "$XRAY_REF"' in workflow, "pinned Xray release is not enforced by CI")
    require("git push" not in workflow and "git commit" not in workflow, "build workflow still mutates the source branch")
    require('LAST_VERSION" = "4.0.24' not in workflow, "historical 4.0.24 release magic remains in CI")
    require("Could not synchronize readme Stable tag" in workflow, "main release workflow does not synchronize WordPress Stable tag")
    require("Readme stable tag is not synchronized" in manager_workflow, "manual manager publisher does not validate Stable tag")
    require("x.y.0..x.y.10" in manager_workflow, "manual manager publisher does not enforce short patch policy")
    build_pos = workflow.find("- name: Build unsigned release APKs")
    sign_pos = workflow.find("- name: Align and sign APKs permanently")
    wp_pos = workflow.find("- name: Publish synchronized BlueVPN Manager release barrier")
    apk_release_pos = workflow.find("- name: Publish signed APKs to GitHub Release")
    require(0 <= build_pos < sign_pos < wp_pos < apk_release_pos, "release order must be build/sign -> WordPress barrier -> APK release")

    # Manual locations refresh contract remains explicit-only.
    locations = read("android-source/BlueVpnServersActivity.kt")
    require("manual" in locations.lower() or "refresh" in locations.lower(), "locations source unexpectedly missing refresh controls")

    print("BlueVPN current release validation passed")
    print(f"version={version} code={code} schema=1.6.0 upstream={app['upstream_ref']}")
    print("checks=subscription-isolation,runtime-gate,ai,wordpress-mysql,ci-hygiene,versioning")


if __name__ == "__main__":
    try:
        main()
    except AssertionError as exc:
        print(f"VALIDATION FAILED: {exc}", file=sys.stderr)
        raise SystemExit(1)
