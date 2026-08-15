from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(rel: str) -> str:
    p = ROOT / rel
    if not p.exists():
        raise AssertionError(f"missing required file: {rel}")
    return p.read_text(encoding="utf-8")


def require(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def between(text: str, start: str, end: str) -> str:
    i = text.find(start)
    require(i >= 0, f"missing block start: {start}")
    j = text.find(end, i + len(start))
    require(j >= 0, f"missing block end after: {start}")
    return text[i:j]


def main() -> None:
    app = json.loads(read("branding/app.json"))
    release = json.loads(read("release.json"))
    home = read("android-source/BlueVpnHomeActivity.kt")
    account = read("android-source/BlueVpnAccountManager.kt")
    location = read("android-source/BlueVpnLocationUtil.kt")
    subscription = read("android-source/BlueVpnSubscriptionIntelligence.kt")
    prepare = read("scripts/prepare_android.py")
    workflow = read(".github/workflows/build-apk.yml")
    profile = read("android-source/BlueVpnProfileManager.kt")
    warp = read("android-source/BlueVpnWarpEngine.kt")
    aether_build = read("scripts/build_aether_android.py")
    notice = read("NOTICE.md")
    readme = read("README.md")
    sms_otp = read("bluevpn-manager/includes/class-bluevpn-sms-otp.php")
    sms_notifications = read("bluevpn-manager/includes/class-bluevpn-sms-notifications.php")
    api = read("bluevpn-manager/includes/class-bluevpn-api.php")
    ads = read("bluevpn-manager/includes/class-bluevpn-ads.php")
    carousel = read("android-source/BlueVpnAdsCarouselView.kt")
    update_manager = read("android-source/BlueVpnUpdateManager.kt")

    version = str(app.get("version_name", "")).strip()
    match = re.fullmatch(r"(\d+)\.(\d+)\.(\d+)", version)
    require(match is not None, f"invalid app version_name: {version!r}")
    major, minor, patch = map(int, match.groups())
    expected_version_code = major * 10000 + minor * 100 + patch

    require(int(app.get("version_code", -1)) == expected_version_code,
            f"app versionCode mismatch: expected {expected_version_code} for {version}")
    require(str(release.get("version", "")).strip() == version,
            f"release version mismatch: app={version} release={release.get('version')}")
    require(int(release.get("version_code", -1)) == expected_version_code,
            f"release versionCode mismatch: expected {expected_version_code}")
    require(str(release.get("android_version", "")).strip() == version,
            "release android_version mismatch")
    require(int(release.get("android_version_code", -1)) == expected_version_code,
            "release android_version_code mismatch")

    # /mobile/config is a critical control-plane contract: its helper names must
    # exist and Free policy changes must propagate into Android independently of
    # whether the local Free subscription pool is already populated.
    require("BlueVPN_Ads::advertising_payload($s, $r)" in api, "mobile config uses wrong advertising helper")
    require("BlueVPN_Ads::free_access_payload($s)" in api, "mobile config uses wrong Free policy helper")
    require("$advertising = BlueVPN_Ads::advertising_payload($s, $r);" in api, "mobile config does not build canonical advertising payload once")
    require("$tapsell = BlueVPN_Ads::tapsell_payload($s);" in api, "mobile config does not expose Tapsell payload")
    require("'advertising'=>$advertising" in api, "mobile config canonical advertising key missing")
    require("'ads'=>$advertising" in api, "mobile config ads compatibility alias missing")
    require("'tapsell'=>$tapsell" in api, "mobile config tapsell key missing")
    require('root.optJSONObject("advertising") ?: root.optJSONObject("ads")' in carousel, "Android banner parser lacks advertising/ads compatibility fallback")
    require("public static function public_config" in ads and "public static function free_public_config" in ads,
            "BlueVPN_Ads compatibility aliases missing")
    require("fun applyRemoteMobileConfig(c: Context, config: JSONObject): Boolean" in account,
            "Android does not persist server-authored Free policy")
    require("newMinutes < oldMinutes" in account and '.putLong("session_ends_at", allowedEnd)' in account,
            "active Free session is not clamped after a server-side limit reduction")
    require("BlueVpnAccountManager.mobileConfig(" in update_manager and "applyRemoteMobileConfig(appContext, response)" in account,
            "manual update check does not apply Free policy through canonical mobileConfig")
    require(app["upstream_ref"] == "2.2.6", "production v2rayNG pin must be 2.2.6")
    require(app["android_lib_xray_ref"] == "v26.7.5", "documented AndroidLibXrayLite tag must be v26.7.5")
    require(app["xray_core_release_label"] == "v26.6.27", "v2rayNG 2.2.6 Xray-core release label must be v26.6.27")
    require("xray_ref" not in app, "ambiguous xray_ref metadata must be removed")
    require("sing_box_ref" not in app, "sing-box pin must be removed")
    require(app.get("free_engine") == "aether-warp-primary", "Free engine metadata must select Aether/WARP")
    require(app.get("aether_ref") == "a26159b82a70048b459e0128213c71767abecb8a", "Aether metadata pin mismatch")

    # Premium remains mounted directly on stock v2rayNG. Free may start the
    # separately packaged Aether process and then use a local SOCKS ProfileItem
    # through that same stock v2rayNG VpnService/TUN owner.
    require("import com.v2ray.ang.core.CoreServiceManager" in home, "Home must import upstream CoreServiceManager")
    require("CoreServiceManager.startVService(this, guid)" in home, "Home must start exact GUID through upstream v2rayNG")
    require("CoreServiceManager.stopVService" in home, "Home must stop through upstream v2rayNG")
    require("BlueVpnEngineManager" not in home + account, "legacy engine abstraction remains")
    require("beginWarpFreeConnection()" in home, "Free WARP routing entrypoint missing")
    require("BlueVpnWarpEngine.isBridgeGuid" in home, "WARP bridge is not isolated from subscription candidates")
    require("import com.v2ray.ang.bluevpn.BlueVpnWarpEngine" in home, "BlueVpnHomeActivity is missing the explicit WARP engine import")
    require("warpFreeEnabled(this)" in home, "Free connect gate is not policy-driven by WARP")
    require("warpReadyByPolicy = snapshot.warpEnabled" in account, "Free entitlement still depends on legacy subscription rows")
    require('BRIDGE_SUBSCRIPTION_ID = "bluevpn_free_warp_aether"' in warp, "dedicated WARP bridge ownership missing")
    require("127.0.0.1" in warp and "1819" in warp, "Aether loopback SOCKS boundary missing")
    require("Aether loopback port is already occupied" in warp, "WARP local port hijack must fail closed")
    require('AETHER_COMMIT = "a26159b82a70048b459e0128213c71767abecb8a"' in aether_build, "Aether source is not pinned")
    require("Build pinned Aether WARP runtime" in workflow, "CI does not build Aether from pinned source")
    require("coreStartError" not in home, "Home still depends on patched MainViewModel diagnostics")
    require("CoreServiceManager.stopVService(appContext)" in account, "Account must stop stock v2rayNG directly")

    # Match stock MainActivity ordering: receiver/assets are ready before delayed work/ads.
    listen_at = home.find("mainViewModel.startListenBroadcast()")
    assets_at = home.find("mainViewModel.initAssets(assets)")
    pipeline_at = home.find("scheduleStartupPipeline()")
    ads_at = home.find("BlueVpnTapsellManager.warmUp(this)")
    require(min(listen_at, assets_at, pipeline_at, ads_at) >= 0, "startup parity anchors missing")
    require(listen_at < pipeline_at and assets_at < pipeline_at, "v2rayNG receiver/assets are still delayed behind startup pipeline")
    require(listen_at < ads_at and assets_at < ads_at, "ads run before v2rayNG critical startup")

    # Observe real upstream START_FAILURE without changing upstream MainViewModel/service.
    require("AppConfig.MSG_STATE_START_FAILURE" in home, "stock START_FAILURE is not observed")
    require('intent.getStringExtra("content")' in home, "upstream START_FAILURE reason is not preserved")
    require("failCurrentAndTryNext(reason)" in home, "upstream START_FAILURE does not advance hidden-route failover")

    # BlueVPN must not authoritatively pre-validate imported profiles before stock runtime.
    start_block = between(home, "private fun startCurrentCandidate", "private fun startExactCandidateCore")
    exact_block = between(home, "private fun startExactCandidateCore", "private fun scheduleConnectionVerification")
    require("preflightCandidate(" not in start_block, "authoritative DNS/TCP preflight still blocks connect")
    require("validateExactConfig" not in home, "custom config hydration gate still exists")
    require("MmkvManager.setSelectServer(guid)" not in exact_block, "BlueVPN duplicates upstream selected-GUID handoff")
    require("handler.postDelayed(attemptTimeout, 12_000L)" in exact_block, "bounded upstream start safety timeout missing")

    usable_block = between(location, "fun isUsable(", "fun invalidateCache()")
    require("return true" in usable_block, "BlueVPN still rejects decoded profiles before CoreConfigManager")
    require("server.isBlank" not in usable_block and "127.0.0.1" not in usable_block, "BlueVPN endpoint heuristics still reject profiles")
    require("BlueVpnProfileManager.fingerprint" not in location, "candidate catalogue still performs semantic dedupe")

    # Subscription import must be the stock v2rayNG parser/fetch path and UA semantics.
    require("AngConfigManager.updateConfigViaSub(row)" in subscription, "stock subscription update path missing")
    for token in ("Clash.Meta", "compatibilityUserAgents", 'BlueVPN/${BuildConfig.VERSION_NAME}'):
        require(token not in subscription, f"subscription UA/parser compatibility shim remains: {token}")
    require("userAgent = null" in account, "managed subscriptions do not preserve stock v2rayNG UA behavior")

    # Retired sing-box/legacy engine files must stay absent. The only additional
    # Free transport is the separately pinned Aether process above.
    for rel in (
        "android-source/BlueVpnEngineManager.kt",
        "android-source/BlueVpnSingBoxProcess.kt",
        "android-source/BlueVpnSingBoxProfileCompiler.kt",
        "android-source/BlueVpnAiActivity.kt",
    ):
        require(not (ROOT / rel).exists(), f"legacy dual-engine file still present: {rel}")
    cleanup = read("scripts/cleanup_repository.py")
    for token in (
        "BlueVpnEngineManager.kt",
        "BlueVpnSingBoxProcess.kt",
        "BlueVpnSingBoxProfileCompiler.kt",
        "BlueVpnAiActivity.kt",
        "android-source/generated",
    ):
        require(token in cleanup, f"repository cleanup does not retire: {token}")
    require("Remove retired BlueVPN runtime files" in workflow, "CI repository cleanup step missing")
    require("python scripts/cleanup_repository.py" in workflow, "CI does not execute repository cleanup")

    require("sing-box" not in workflow.lower(), "workflow still builds sing-box")
    require("CluvexStudio/Aether" in prepare, "Aether source attribution missing from Android notice")
    require("SING_BOX" not in profile and "SING_BOX_JSON" not in profile, "profile catalogue still routes to sing-box")

    # Upstream source is checked out first and its exact core submodule pairing is never moved.
    require("repository: 2dust/v2rayNG" in workflow, "official v2rayNG checkout missing")
    require("ref: ${{ steps.config.outputs.upstream_ref }}" in workflow, "v2rayNG pin is not used by checkout")
    require("Overlay BlueVPN UI and control plane on v2rayNG" in workflow, "overlay stage missing")
    require("repository: SagerNet/sing-box" not in workflow, "sing-box checkout still exists")
    require("actions/setup-go" not in workflow, "Go/sing-box setup still exists")
    require('git checkout --force "$XRAY_REF"' not in workflow, "CI still moves AndroidLibXrayLite away from the v2rayNG submodule commit")
    require('CURRENT_COMMIT="$(git rev-parse HEAD)"' in workflow, "CI does not read v2rayNG-pinned core submodule commit")
    require('CURRENT_TAG="$(git describe --tags --abbrev=0' in workflow, "CI does not resolve the nearest official AndroidLibXrayLite tag from the pinned submodule")
    require('DOCUMENTED_TAG="${{ steps.config.outputs.android_lib_xray_ref }}"' in workflow, "CI does not expose documented AndroidLibXrayLite metadata")
    require('XRAY_RELEASE_LABEL="${{ steps.config.outputs.xray_core_release_label }}"' in workflow, "CI does not expose informational Xray-core release label")
    require('PINNED_COMMIT="$(git rev-list -n 1' not in workflow, "CI still assumes submodule HEAD must equal the release-tag commit")
    require('AndroidLibXrayLite release tag mismatch' not in workflow, "CI still treats AndroidLibXrayLite and Xray-core version labels as the same namespace")
    require('Building with the upstream-resolved tag' in workflow, "CI does not prefer the official v2rayNG-resolved AndroidLibXrayLite tag")

    # Build-time immutable boundary around v2rayNG runtime/parser files.
    require("patch_v2rayng_runtime_lifecycle" not in prepare, "core lifecycle patch remains")
    require("patch_shadowsocks_transport_queries" not in prepare, "protocol parser patch remains")
    require("UPSTREAM_RUNTIME_GUARD" in prepare, "upstream runtime guard missing")
    for protected in (
        "core/CoreServiceManager.kt",
        "core/CoreConfigManager.kt",
        "service/CoreVpnService.kt",
        "viewmodel/MainViewModel.kt",
        "handler/AngConfigManager.kt",
    ):
        require(protected in prepare, f"protected upstream runtime file missing from hash guard: {protected}")
    require("snapshot_upstream_runtime()" in prepare, "upstream runtime snapshot is not taken")
    require("assert_upstream_runtime_unchanged(runtime_snapshot)" in prepare, "upstream runtime hash is not checked after overlay")
    override_block = between(prepare, "plain_overrides = {", "    for target, source in plain_overrides.items()")
    for protected_name in ("CoreServiceManager.kt", "CoreConfigManager.kt", "CoreVpnService.kt", "MainViewModel.kt", "AngConfigManager.kt"):
        require(protected_name not in override_block, f"BlueVPN overlay rewrites protected upstream file: {protected_name}")

    # Hidden AI may score internally, but no dead AI control path may reconnect the VPN.
    require("private fun runSmartSelection()" not in home, "dead AI connection controller still exists")
    require("private fun monitorBlueAiHealth()" not in home, "dead AI health controller still exists")

    # Product requirements retained without owning protocol runtime.
    require("MANUAL_LOCATION" in home, "location-only selection flow missing")
    require("failoverReserveQueue" in home, "AUTO mode can still discard lower-ranked imported GUIDs")
    require("hidden" in readme.lower() and "location" in readme.lower(), "hidden-route architecture not documented")
    require("Free/Premium" in readme or "Free/Premium" in notice, "entitlement isolation documentation missing")
    entitlement = read("android-source/BlueVpnEntitlement.kt")
    account_manager = read("android-source/BlueVpnAccountManager.kt")
    require("val warpEligible = free.warpEnabled" in entitlement and "val legacyFreeEligible = free.subscriptions.isNotEmpty()" in entitlement, "Free entitlement does not model WARP and legacy Pool independently")
    require("fun freeAccessConfigured" in account_manager, "explicit Free config state tracking missing")
    require("bluevpn-manager" in workflow, "WordPress release integration missing")
    settings_writes = re.findall(r"MmkvManager\.encodeSettings\(", home)
    require(len(settings_writes) == 1 and 'AppConfig.PREF_MODE, "VPN"' in home, "BlueVPN still force-overrides v2rayNG runtime settings")

    # 4.2.0 performance freeze: optimize only BlueVPN presentation/control plane.
    require("private var firstHomeResume = true" in home, "first-resume dedupe guard missing")
    resume_block = between(home, "override fun onResume()", "private fun scheduleStartupPipeline")
    require("if (!initialResume)" in resume_block, "initial onResume still repeats startup refresh work")
    pipeline_block = between(home, "private fun scheduleStartupPipeline", "private fun scheduleIdleCandidateWarmup")
    require("val premiumAtLaunch = BlueVpnAccountManager.premiumEntitlementActive" in pipeline_block, "startup no longer distinguishes live Premium entitlement")
    require("val needsFreeBootstrap = !premiumAtLaunch" in pipeline_block, "logged-in non-Premium accounts no longer bootstrap the Free pool")
    require("val preparedFree = if (needsFreeBootstrap)" in pipeline_block, "Free bootstrap is not guarded behind the Premium boundary")
    dashboard_block = between(home, "private fun refreshDashboard", "private fun readTunnelTrafficBytes")
    require("selectedServerAllowedUi" in dashboard_block, "dashboard still performs deep selected-server ownership scan")
    require("selectedServerAllowed(this)" not in dashboard_block, "dashboard still calls deep selectedServerAllowed on main thread")
    experience_block = between(home, "private fun refreshExperienceDashboard", "private fun recordCurrentConnection")
    require("compatibilityParentVisible" in experience_block, "hidden compatibility dashboard still performs invisible work")
    subscription_info_block = between(home, "private fun refreshSubscriptionInfo", "private fun formatAccountRemainingTime")
    require('getSharedPreferences("bluevpn_subscription_info"' not in subscription_info_block, "subscription UI still clears disk cache on each render")

    # Plugin version stays synchronized.
    plugin = read("bluevpn-manager/bluevpn-manager.php")
    plugin_readme = read("bluevpn-manager/readme.txt")
    plugin_header = re.search(r"(?mi)^\s*\*?\s*Version:\s*(\d+\.\d+\.\d+)\s*$", plugin)
    require(plugin_header is not None and plugin_header.group(1) == version,
            f"plugin header version mismatch: expected {version}")
    # 4.2.0 SMS transport hardening: backend must fail before Android OTP budget
    # and OTP endpoints must always return JSON even on unexpected PHP failures.
    require('val otpRequest = method == "POST"' in account, "Android OTP request classification missing")
    require("otpRequest -> 30_000" in account, "Android OTP read budget must be 30 seconds")
    require("'timeout' => 10" in sms_otp, "OTP provider timeout must be shorter than Android budget")
    require("provider_transport_failure" in sms_otp, "OTP provider transport diagnostics missing")
    require("record_provider_health" in sms_otp, "OTP provider health persistence missing")
    require("private static function unexpected(Throwable $e,string $scope)" in api, "JSON fatal guard missing for OTP API")
    require("catch(Throwable $e){return self::unexpected($e,'otp_request');}" in api, "OTP request does not catch unexpected PHP failures")
    require("'timeout'=>10" in sms_notifications, "notification SMS provider timeout is not bounded")

    plugin_constant = re.search(
        r"define\(\s*['\"]BLUEVPN_MANAGER_VERSION['\"]\s*,\s*['\"](\d+\.\d+\.\d+)['\"]\s*\)\s*;",
        plugin,
    )
    require(plugin_constant is not None and plugin_constant.group(1) == version,
            f"plugin constant version mismatch: expected {version}")
    stable_tag = re.search(r"(?mi)^Stable tag:\s*(\d+\.\d+\.\d+)\s*$", plugin_readme)
    require(stable_tag is not None and stable_tag.group(1) == version,
            f"plugin stable tag mismatch: expected {version}")
    readme_version = re.search(r"(?mi)^Version:\s*(\d+\.\d+\.\d+)\s*$", plugin_readme)
    require(readme_version is not None and readme_version.group(1) == version,
            f"plugin readme version mismatch: expected {version}")

    # IranPayamak pattern discovery: fetch every provider page instead of
    # silently caching only page 1, while keeping GET requests body-free on
    # WordPress/PHP 8.4.
    control = read("bluevpn-manager/includes/class-bluevpn-control-center.php")
    require("provider_pattern_page_url" in sms_otp and "'/patterns?page='" in sms_otp, "IranPayamak pattern list/pagination endpoint missing")
    require("'method' => 'GET'" in sms_otp and "'Api-Key' => $apiKey" in sms_otp, "pattern sync auth/method mismatch")
    require("PATTERN_CACHE_OPTION" in sms_otp and "provider_pattern_candidates" in sms_otp, "pattern sync cache/normalizer missing")
    refresh = between(sms_otp, 'public static function refresh_patterns', 'public static function active_pattern_codes')
    require("provider_pattern_page_url($base, $page, $limit)" in refresh and "'method' => 'GET'" in refresh, "provider pattern GET request missing")
    require("$maxPages = 50" in refresh and "$newProviderCodes === 0" in refresh and "$all[$code] = $row" in refresh, "multi-page pattern traversal/dedup guard missing")
    require("'body' =>" not in refresh and "add_query_arg(" not in refresh, "pattern discovery must not serialize GET body/query filters")
    require("'share' => 1" not in refresh, "private patterns must not be excluded by share=1")
    require("rawurlencode($configuredCode)" in refresh, "configured pattern detail recovery missing")
    require("bluevpn_cc_refresh_sms_patterns" in control and "sms_pattern_select" in control, "admin pattern refresh/dropdown missing")
    require('placeholder="Pattern UID"' not in control, "manual Pattern UID field still exposed")
    require("reconcile_sms_pattern_selections" in control, "stale provider pattern reconciliation missing")
    require("preferred_otp_parameter" in sms_otp and "SMS_PATTERN_INACTIVE" in sms_otp, "OTP pattern variable/stale protection missing")

    # Manager deployment: once this manager is bootstrapped, the Telegram bot
    # must be able to publish/install the manager before starting Android.
    telegram_bot = read("bluevpn-manager/includes/class-bluevpn-telegram-bot.php")
    github_updater = read("bluevpn-manager/includes/class-bluevpn-github-updater.php")
    manager_workflow = read(".github/workflows/bluevpn-manager-release.yml")
    require("🧩 بروزرسانی Manager" in telegram_bot and "dispatch_manager_release" in telegram_bot, "Telegram manager update command/dispatch missing")
    require("waiting_manager" in telegram_bot and "start_android_build_for_job" in telegram_bot, "manager-before-Android orchestration missing")
    require("public static function install_latest_now" in github_updater, "manager self-install entrypoint missing")
    require("target_sha:" in manager_workflow and "inputs.target_sha || 'main'" in manager_workflow, "manager workflow exact SHA input missing")
    require("request_id:" in manager_workflow and "inputs.request_id || github.run_id" in manager_workflow, "manager workflow correlation id missing")
    require("request_id' => $requestId" in telegram_bot and "display_title" in telegram_bot, "Telegram manager workflow correlation missing")

    # Versioning contract: patch series is 0..10.
    require(0 <= patch <= 10, "patch version exceeded BlueVPN short series")

    print(f"BlueVPN {version} validation: PASS")
    print("runtime=v2rayNG-2.2.6 androidlib=v26.7.5 xray-release-label=v26.6.27 sing-box=removed")
    print("architecture=Free -> pinned Aether/WARP loopback SOCKS -> stock v2rayNG VpnService; Premium -> immutable stock v2rayNG/Xray")


if __name__ == "__main__":
    main()
