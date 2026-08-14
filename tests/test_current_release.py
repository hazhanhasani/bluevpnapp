from pathlib import Path
import json
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]


def text(rel):
    return (ROOT / rel).read_text(encoding="utf-8")


def block(source: str, start: str, end: str) -> str:
    i = source.index(start)
    j = source.index(end, i + len(start))
    return source[i:j]


class CurrentReleaseTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.app = json.loads(text("branding/app.json"))
        cls.release = json.loads(text("release.json"))
        cls.home = text("android-source/BlueVpnHomeActivity.kt")
        cls.account = text("android-source/BlueVpnAccountManager.kt")
        cls.location = text("android-source/BlueVpnLocationUtil.kt")
        cls.subscription = text("android-source/BlueVpnSubscriptionIntelligence.kt")
        cls.prepare = text("scripts/prepare_android.py")
        cls.workflow = text(".github/workflows/build-apk.yml")
        cls.profile = text("android-source/BlueVpnProfileManager.kt")

    def test_01_version(self):
        version = self.app["version_name"]
        match = re.fullmatch(r"(\d+)\.(\d+)\.(\d+)", version)
        self.assertIsNotNone(match)
        major, minor, patch = map(int, match.groups())
        self.assertLessEqual(patch, 10)
        self.assertEqual(self.app["version_code"], major * 10000 + minor * 100 + patch)

    def test_02_release_version(self):
        self.assertEqual(self.release["version"], self.app["version_name"])
        self.assertEqual(self.release["version_code"], self.app["version_code"])
        self.assertEqual(self.release["android_version"], self.app["version_name"])
        self.assertEqual(self.release["android_version_code"], self.app["version_code"])

    def test_03_official_pairing(self):
        self.assertEqual(self.app["upstream_ref"], "2.2.6")
        self.assertEqual(self.app["android_lib_xray_ref"], "v26.7.5")
        self.assertEqual(self.app["xray_core_release_label"], "v26.6.27")
        self.assertNotIn("xray_ref", self.app)
        self.assertNotIn("sing_box_ref", self.app)

    def test_04_direct_stock_start_stop(self):
        self.assertIn("CoreServiceManager.startVService(this, guid)", self.home)
        self.assertIn("CoreServiceManager.stopVService", self.home)
        self.assertNotIn("BlueVpnEngineManager", self.home + self.account)

    def test_05_alternate_engine_files_removed(self):
        for rel in (
            "android-source/BlueVpnEngineManager.kt",
            "android-source/BlueVpnSingBoxProcess.kt",
            "android-source/BlueVpnSingBoxProfileCompiler.kt",
            "android-source/BlueVpnAiActivity.kt",
        ):
            self.assertFalse((ROOT / rel).exists(), rel)

    def test_06_no_singbox_ci(self):
        self.assertNotIn("sing-box", self.workflow.lower())
        self.assertNotIn("actions/setup-go", self.workflow)

    def test_07_no_runtime_or_parser_patch_functions(self):
        self.assertNotIn("patch_v2rayng_runtime_lifecycle", self.prepare)
        self.assertNotIn("patch_shadowsocks_transport_queries", self.prepare)

    def test_08_runtime_hash_guard_exists(self):
        self.assertIn("UPSTREAM_RUNTIME_GUARD", self.prepare)
        for rel in (
            "core/CoreServiceManager.kt",
            "core/CoreConfigManager.kt",
            "service/CoreVpnService.kt",
            "viewmodel/MainViewModel.kt",
            "handler/AngConfigManager.kt",
        ):
            self.assertIn(rel, self.prepare)
        self.assertIn("runtime_snapshot = snapshot_upstream_runtime()", self.prepare)
        self.assertIn("assert_upstream_runtime_unchanged(runtime_snapshot)", self.prepare)

    def test_09_overlay_does_not_replace_protected_runtime(self):
        overrides = block(self.prepare, "plain_overrides = {", "    for target, source in plain_overrides.items()")
        for filename in (
            "CoreServiceManager.kt",
            "CoreConfigManager.kt",
            "CoreVpnService.kt",
            "MainViewModel.kt",
            "AngConfigManager.kt",
        ):
            self.assertNotIn(filename, overrides)

    def test_10_official_checkout(self):
        self.assertIn("repository: 2dust/v2rayNG", self.workflow)
        self.assertIn("ref: ${{ steps.config.outputs.upstream_ref }}", self.workflow)
        self.assertIn("Overlay BlueVPN UI and control plane on v2rayNG", self.workflow)

    def test_11_core_submodule_not_moved(self):
        self.assertNotIn('git checkout --force "$XRAY_REF"', self.workflow)
        self.assertIn('CURRENT_COMMIT="$(git rev-parse HEAD)"', self.workflow)
        self.assertIn('CURRENT_TAG="$(git describe --tags --abbrev=0', self.workflow)
        self.assertIn('DOCUMENTED_TAG="${{ steps.config.outputs.android_lib_xray_ref }}"', self.workflow)
        self.assertIn('XRAY_RELEASE_LABEL="${{ steps.config.outputs.xray_core_release_label }}"', self.workflow)
        self.assertNotIn('PINNED_COMMIT="$(git rev-list -n 1', self.workflow)
        self.assertNotIn('AndroidLibXrayLite release tag mismatch', self.workflow)
        self.assertIn('Building with the upstream-resolved tag', self.workflow)

    def test_12_immediate_stock_receiver_and_assets(self):
        listen = self.home.index("mainViewModel.startListenBroadcast()")
        assets = self.home.index("mainViewModel.initAssets(assets)")
        pipeline = self.home.index("scheduleStartupPipeline()")
        ads = self.home.index("BlueVpnTapsellManager.warmUp(this)")
        self.assertLess(listen, pipeline)
        self.assertLess(assets, pipeline)
        self.assertLess(listen, ads)
        self.assertLess(assets, ads)

    def test_13_real_start_failure_is_consumed(self):
        self.assertIn("AppConfig.MSG_STATE_START_FAILURE", self.home)
        self.assertIn('intent.getStringExtra("content")', self.home)
        self.assertIn("failCurrentAndTryNext(reason)", self.home)

    def test_14_no_authoritative_preflight(self):
        start = block(self.home, "private fun startCurrentCandidate", "private fun startExactCandidateCore")
        self.assertNotIn("preflightCandidate(", start)
        self.assertNotIn("validateExactConfig", self.home)

    def test_15_exact_handoff_has_no_duplicate_select_write(self):
        exact = block(self.home, "private fun startExactCandidateCore", "private fun scheduleConnectionVerification")
        self.assertIn("CoreServiceManager.startVService(this, guid)", exact)
        self.assertNotIn("MmkvManager.setSelectServer(guid)", exact)
        self.assertIn("handler.postDelayed(attemptTimeout, 30_000L)", exact)

    def test_16_location_util_does_not_reject_v2rayng_profiles(self):
        usable = block(self.location, "fun isUsable(", "fun invalidateCache()")
        self.assertIn("return true", usable)
        self.assertNotIn("server.isBlank", usable)
        self.assertNotIn("127.0.0.1", usable)

    def test_17_candidate_catalog_has_no_semantic_dedupe(self):
        self.assertNotIn("BlueVpnProfileManager.fingerprint", self.location)
        self.assertIn("Preserve every imported GUID", self.location)

    def test_18_stock_subscription_update_path(self):
        self.assertIn("AngConfigManager.updateConfigViaSub(row)", self.subscription)
        self.assertNotIn("compatibilityUserAgents", self.subscription)
        self.assertNotIn("Clash.Meta", self.subscription)
        self.assertNotIn('BlueVPN/${BuildConfig.VERSION_NAME}', self.subscription)

    def test_19_managed_subscription_uses_stock_ua(self):
        self.assertIn("userAgent = null", self.account)

    def test_20_location_mode_and_reserve_pool_preserved(self):
        self.assertIn("BlueVpnSelectionMode.MANUAL_LOCATION", self.home)
        self.assertIn("failoverReserveQueue", self.home)
        self.assertIn("val initialBatchSize = 8", self.home)

    def test_21_no_ai_runtime_controller(self):
        self.assertNotIn("private fun runSmartSelection()", self.home)
        self.assertNotIn("private fun monitorBlueAiHealth()", self.home)

    def test_22_only_product_mode_setting_is_forced(self):
        self.assertEqual(len(re.findall(r"MmkvManager\.encodeSettings\(", self.home)), 1)
        self.assertIn('MmkvManager.encodeSettings(AppConfig.PREF_MODE, "VPN")', self.home)

    def test_23_profile_no_engine_route(self):
        self.assertNotIn("EngineRoute", self.profile)
        self.assertNotIn("SING_BOX_JSON", self.profile)

    def test_24_profile_fingerprint_still_supports_selection_restore(self):
        self.assertIn('append("rawjson=")', self.profile)

    def test_25_plugin_version(self):
        version = re.escape(self.app["version_name"])
        self.assertRegex(text("bluevpn-manager/bluevpn-manager.php"), rf"Version:\s*{version}")
        self.assertRegex(text("bluevpn-manager/readme.txt"), rf"Stable tag:\s*{version}")

    def test_26_short_semver(self):
        self.assertLessEqual(int(self.app["version_name"].split(".")[2]), 10)

    def test_27_no_old_release_number(self):
        for rel in ("branding/app.json", "release.json", "README.md", "NOTICE.md"):
            self.assertNotIn("4.1.7", text(rel))

    def test_28_first_resume_is_local_only(self):
        self.assertIn("private var firstHomeResume = true", self.home)
        resume = block(self.home, "override fun onResume()", "private fun scheduleStartupPipeline")
        self.assertIn("val initialResume = firstHomeResume", resume)
        self.assertIn("if (!initialResume)", resume)

    def test_29_premium_startup_does_not_scan_free_pool(self):
        pipeline = block(self.home, "private fun scheduleStartupPipeline", "private fun scheduleIdleCandidateWarmup")
        self.assertIn("val premiumAtLaunch = BlueVpnAccountManager.premiumEntitlementActive", pipeline)
        self.assertIn("val needsFreeBootstrap = !premiumAtLaunch", pipeline)
        self.assertIn("val preparedFree = if (needsFreeBootstrap)", pipeline)
        self.assertNotIn("val hasFreeServer =", pipeline)

    def test_30_dashboard_uses_ui_safe_entitlement_check(self):
        dashboard = block(self.home, "private fun refreshDashboard", "private fun readTunnelTrafficBytes")
        self.assertIn("selectedServerAllowedUi", dashboard)
        self.assertNotIn("selectedServerAllowed(this)", dashboard)
        self.assertIn("fun selectedServerAllowedUi(", self.account)

    def test_31_hidden_compatibility_ui_does_no_ai_work(self):
        experience = block(self.home, "private fun refreshExperienceDashboard", "private fun recordCurrentConnection")
        self.assertIn("compatibilityParentVisible", experience)
        self.assertIn("qualityValue.visibility != View.VISIBLE", experience)

    def test_32_subscription_render_has_no_disk_clear(self):
        info = block(self.home, "private fun refreshSubscriptionInfo", "private fun formatAccountRemainingTime")
        self.assertNotIn('getSharedPreferences("bluevpn_subscription_info"', info)


    def test_33_sms_otp_timeout_budget_returns_provider_error_before_android_timeout(self):
        self.assertIn('val otpRequest = method == "POST"', self.account)
        self.assertIn('otpRequest -> 30_000', self.account)
        sms = text("bluevpn-manager/includes/class-bluevpn-sms-otp.php")
        self.assertIn("'timeout' => 10", sms)
        self.assertIn("provider_transport_failure", sms)
        self.assertIn("record_provider_health", sms)

    def test_34_sms_otp_api_never_falls_back_to_html_fatal(self):
        api = text("bluevpn-manager/includes/class-bluevpn-api.php")
        self.assertIn("private static function unexpected(Throwable $e,string $scope)", api)
        self.assertIn("catch(Throwable $e){return self::unexpected($e,'otp_request');}", api)
        self.assertIn("catch(Throwable $e){ return self::unexpected($e,'bind_phone_otp_request'); }", api)

    def test_35_sms_notification_provider_budget_is_bounded(self):
        sms = text("bluevpn-manager/includes/class-bluevpn-sms-notifications.php")
        self.assertIn("'timeout'=>10", sms)
        self.assertIn("record_provider_health", sms)

    def test_36_sms_pattern_sync_uses_official_endpoint_and_api_key_header(self):
        sms = text("bluevpn-manager/includes/class-bluevpn-sms-otp.php")
        self.assertIn("$base . '/patterns'", sms)
        self.assertIn("'method' => 'GET'", sms)
        self.assertIn("'Api-Key' => $apiKey", sms)
        self.assertIn("PATTERN_CACHE_OPTION", sms)

    def test_37_sms_pattern_sync_is_resilient_to_get_body_and_nested_payloads(self):
        sms = text("bluevpn-manager/includes/class-bluevpn-sms-otp.php")
        self.assertIn("'staus' => 'active'", sms)
        self.assertIn("'status' => 'active'", sms)
        self.assertIn("provider_pattern_candidates", sms)
        self.assertIn("json_decode($trimmed, true)", sms)

    def test_38_sms_admin_uses_synced_pattern_dropdowns(self):
        cc = text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        self.assertIn("bluevpn_cc_refresh_sms_patterns", cc)
        self.assertIn("sms_pattern_select", cc)
        self.assertIn("تازه‌سازی پترن‌ها", cc)
        self.assertNotIn("placeholder=\"Pattern UID\"", cc)

    def test_39_stale_patterns_are_reconciled_and_otp_variable_is_mapped(self):
        sms = text("bluevpn-manager/includes/class-bluevpn-sms-otp.php")
        cc = text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        self.assertIn("preferred_otp_parameter", sms)
        self.assertIn("SMS_PATTERN_INACTIVE", sms)
        self.assertIn("reconcile_sms_pattern_selections", cc)
        self.assertIn("active_pattern_codes", cc)

    def test_40_runtime_freeze_survives_sms_pattern_release(self):
        self.assertIn("CoreServiceManager.startVService(this, guid)", self.home)
        self.assertNotIn("BlueVpnEngineManager", self.home + self.account)
        self.assertEqual(self.app["upstream_ref"], "2.2.6")


    def test_41_site_theme_version_and_updater_are_synchronized(self):
        style = text("bluevpn-site/style.css")
        functions = text("bluevpn-site/functions.php")
        updater = text("bluevpn-site/inc/class-bluevpn-site-updater.php")
        m = re.search(r"(?m)^Version:\s*(\d+\.\d+\.\d+)\s*$", style)
        self.assertIsNotNone(m)
        version = m.group(1)
        self.assertRegex(version, r"^\d+\.\d+\.(?:[0-9]|10)$")
        self.assertIn(f"BLUEVPN_SITE_VERSION', '{version}", functions)
        self.assertIn("BlueVPN_Site_Updater::init();", functions)
        self.assertIn("pre_set_site_transient_update_themes", updater)
        self.assertIn("Theme_Upgrader", updater)

    def test_42_site_theme_uses_independent_release_asset_contract(self):
        updater = text("bluevpn-site/inc/class-bluevpn-site-updater.php")
        self.assertIn("bluevpn-site-theme-v", updater)
        self.assertIn("releases?per_page=100", updater)
        self.assertIn("BlueVPN_GitHub_Updater", updater)
        self.assertIn("BlueVPN_Telegram_Bot", updater)

    def test_43_site_theme_background_auto_update_is_enabled(self):
        updater = text("bluevpn-site/inc/class-bluevpn-site-updater.php")
        self.assertIn("bluevpn_ten_minutes", updater)
        self.assertIn("background_update_check", updater)
        self.assertIn("maybe_kick_background_check", updater)
        self.assertIn("auto_update_theme", updater)

    def test_44_site_theme_release_workflow_is_decoupled_from_android(self):
        workflow = text(".github/workflows/bluevpn-site-theme-release.yml")
        self.assertIn("Release BlueVPN Site Theme", workflow)
        self.assertIn("bluevpn-site-theme-v${THEME_VERSION}.zip", workflow)
        self.assertIn("bluevpn-site-v${THEME_VERSION}", workflow)
        self.assertNotIn("gradlew", workflow)
        self.assertNotIn("v2rayNG", workflow)

    def test_45_site_theme_release_requires_version_bump(self):
        workflow = text(".github/workflows/bluevpn-site-theme-release.yml")
        self.assertIn("Enforce theme version bump on source changes", workflow)
        self.assertIn("changed without a theme version bump", workflow)
        self.assertIn("patch must stay within x.y.0..x.y.10", workflow)


    def test_46_site_theme_redesign_contract(self):
        front = text("bluevpn-site/front-page.php")
        css = text("bluevpn-site/assets/css/site.css")
        self.assertIn("bv-product-stage", front)
        self.assertIn("bv-bento", front)
        self.assertIn("bv-network-visual-pro", front)
        self.assertIn("bv-premium-card", front)
        self.assertIn("bv-accordion", front)
        self.assertNotIn("BlueAI", front)
        self.assertNotIn("بخش AI", front)
        self.assertIn(".bv-hero", css)
        self.assertIn(".bv-product-stage", css)
        self.assertIn(".bv-bento", css)
        self.assertIn(".bv-network-visual-pro", css)
        self.assertIn(".bv-account-page", css)

    def test_repository_cleanup_handles_overlay_stale_files(self):
        cleanup = (ROOT / "scripts/cleanup_repository.py").read_text(encoding="utf-8")
        workflow = (ROOT / ".github/workflows/build-apk.yml").read_text(encoding="utf-8")
        for token in (
            "BlueVpnEngineManager.kt",
            "BlueVpnSingBoxProcess.kt",
            "BlueVpnSingBoxProfileCompiler.kt",
            "BlueVpnAiActivity.kt",
            "android-source/generated",
        ):
            self.assertIn(token, cleanup)
        self.assertIn("Remove retired BlueVPN runtime files", workflow)
        self.assertIn("python scripts/cleanup_repository.py", workflow)


    def test_48_release_validator_has_no_hardcoded_app_version(self):
        validator = text("scripts/validate_release.py")
        self.assertNotRegex(validator, r'app\["version_name"\]\s*==\s*"\d+\.\d+\.\d+"')
        self.assertNotRegex(validator, r'app\["version_code"\]\s*==\s*\d+')
        self.assertIn('expected_version_code = major * 10000 + minor * 100 + patch', validator)
        self.assertIn('plugin_header.group(1) == version', validator)
        self.assertIn('stable_tag.group(1) == version', validator)


    def test_web_login_does_not_consume_vpn_device_slot(self):
        auth = text("bluevpn-manager/includes/class-bluevpn-auth.php")
        db = text("bluevpn-manager/includes/class-bluevpn-db.php")
        self.assertIn("client_type", auth)
        self.assertIn("client_type='app'", auth)
        self.assertIn("$client_type === 'app'", auth)
        self.assertIn("device_id LIKE 'web-%'", db)
        self.assertIn("client_type varchar(16)", db)

    def test_website_otp_advances_optimistically(self):
        js = text("bluevpn-site/assets/js/site.js")
        self.assertIn("در حال ارسال کد تأیید", js)
        self.assertIn("otpReady=false", js)
        self.assertIn("verifyBtn.disabled=true", js)
        self.assertIn("otpRequestSeq", js)

    def test_campaign_banner_is_cache_first_and_not_delayed_by_ad_sdk(self):
        ads = text("android-source/BlueVpnAdsCarouselView.kt")
        theme = text("android-source/BlueVpnTheme.kt")
        home = text("android-source/BlueVpnHomeActivity.kt")
        self.assertIn('"bluevpn_ads_carousel_cache"', ads)
        self.assertIn('File(context.applicationContext.cacheDir, "bluevpn_ads")', ads)
        self.assertIn("hydrateCachedConfig()", ads)
        self.assertIn("readDiskBitmap(url)", ads)
        self.assertIn("writeDiskBytes(url, downloaded.bytes)", ads)
        self.assertIn("prefetchUpcomingImages()", ads)
        self.assertIn("downloadBitmapWithRetry(url)", ads)
        self.assertIn('connection.setRequestProperty("Accept-Encoding", "identity")', ads)
        self.assertIn("connection.useCaches = !forceNetwork", ads)
        self.assertNotIn("showPlaceholder()", ads)
        self.assertIn("fun bannerDelayMs", theme)
        self.assertIn("fun adSdkWarmupDelayMs", theme)
        self.assertIn("BlueVpnPerformance.bannerDelayMs(this)", home)
        self.assertIn("BlueVpnPerformance.adSdkWarmupDelayMs(this)", home)


    def test_campaign_assets_prefer_static_upload_urls_and_decode_healthcheck(self):
        ads_php = text("bluevpn-manager/includes/class-bluevpn-ads.php")
        admin_php = text("bluevpn-manager/includes/class-bluevpn-admin.php")
        self.assertIn("static_asset_url_for_row", ads_php)
        self.assertIn("/bluevpn-ads/", ads_php)
        self.assertIn("wp_upload_dir", ads_php)
        self.assertIn("Do not send Content-Length from PHP", ads_php)
        self.assertIn("getimagesizefromstring", admin_php)

    def test_campaign_banner_does_not_touch_stock_v2rayng_runtime(self):
        ads = text("android-source/BlueVpnAdsCarouselView.kt")
        home = text("android-source/BlueVpnHomeActivity.kt")
        self.assertNotIn("CoreServiceManager", ads)
        self.assertIn("CoreServiceManager.startVService(this, guid)", home)

    def test_public_theme_hides_internal_project_implementation_copy(self):
        public = "\n".join(text(p) for p in [
            "bluevpn-site/front-page.php",
            "bluevpn-site/page-plans.php",
            "bluevpn-site/page-download.php",
            "bluevpn-site/page-account.php",
            "bluevpn-site/page-support.php",
            "bluevpn-site/footer.php",
            "bluevpn-site/header.php",
        ])
        for term in ("GitHub", "hazhanhasani", "BlueVPN Manager", "v2rayNG Runtime", "Xray", "BluePay", "Build #", "WordPress"):
            self.assertNotIn(term, public)


    def test_web_account_uses_canonical_subscription_contract(self):
        js = text("bluevpn-site/assets/js/site.js")
        auth = text("bluevpn-manager/includes/class-bluevpn-auth.php")
        page = text("bluevpn-site/page-account.php")
        self.assertIn("function subscriptionOf(account)", js)
        self.assertIn("sub.entitlement_plan_id||sub.plan_id", js)
        self.assertIn("a.phone_display||a.display_identity||a.phone", js)
        self.assertNotIn("a.entitlement?.active", js)
        self.assertIn("'plan_title' => $planTitle", auth)
        self.assertIn("'current_plan' => $currentPlan", auth)
        self.assertIn("data-current-plan-title", page)

    def test_authenticated_account_hides_login_marketing_and_uses_unified_dashboard(self):
        js = text("bluevpn-site/assets/js/site.js")
        css = text("bluevpn-site/assets/css/site.css")
        page = text("bluevpn-site/page-account.php")
        self.assertIn("classList.add('is-authenticated')", js)
        self.assertIn(".bv-account-layout.is-authenticated .bv-account-intro{display:none}", css)
        self.assertIn("bv-current-subscription", page)
        self.assertIn("bv-dashboard-plans", page)

    def test_site_defaults_to_desktop_layout_on_mobile(self):
        header = text("bluevpn-site/header.php")
        self.assertIn('name="viewport" content="width=1080,viewport-fit=cover"', header)
        self.assertNotIn('width=device-width,initial-scale=1', header)

    def test_logout_cannot_resurrect_premium_session(self):
        self.assertIn("private val authSessionEpoch = AtomicLong(0L)", self.account)
        logout = block(self.account, "fun logout(c: Context)", "private fun invalidateSession")
        self.assertIn("authSessionEpoch.incrementAndGet()", logout)
        self.assertIn("reconcileSubscriptionMode(", logout)
        self.assertIn("premiumActive = false", logout)
        self.assertIn("prepareFreeAccess(appContext, force = false)", logout)
        refresh = block(self.account, "private fun refreshSession", "private fun authenticatedRequest")
        self.assertIn("expectedAuthEpoch", refresh)
        self.assertIn("expectedEpoch = expectedAuthEpoch", refresh)
        self.assertIn("!persisted", refresh)
        sync = block(self.account, "fun sync(", "fun plans(")
        self.assertIn("AUTH_SESSION_CHANGED", sync)
        self.assertIn("expectedAuthEpoch = expectedAuthEpoch", sync)

    def test_premium_requires_live_session_and_free_pool_is_reenabled(self):
        entitlement = text("android-source/BlueVpnEntitlement.kt")
        self.assertIn("BlueVpnAccountManager.premiumEntitlementActive(context)", entitlement)
        self.assertIn("fun premiumEntitlementActive(c: Context)", self.account)
        self.assertIn("hasSession(c) && active(c)", self.account)
        free_ready = block(self.account, "fun hasInstalledFreeServers", "fun prepareFreeAccess")
        self.assertIn("it.subscription.enabled", free_ready)
        self.assertIn("it.subscription.remarks.startsWith(FREE_SUB)", free_ready)
        self.assertIn("cached-but-disabled pool as not ready", free_ready)
        self.assertIn("!premiumEntitlementActive(c) && freeAccessEnabled(c)", self.account)

    def test_logout_invalidates_inflight_premium_candidate_generation(self):
        launcher = block(self.home, "private val accountLauncher", "private enum class OrbVisualState")
        self.assertIn("connectionPreparationGeneration += 1", launcher)
        self.assertIn("cancelFailover()", launcher)

    def test_mobile_config_uses_real_ads_and_free_payload_methods(self):
        api = text("bluevpn-manager/includes/class-bluevpn-api.php")
        ads = text("bluevpn-manager/includes/class-bluevpn-ads.php")
        self.assertIn("BlueVPN_Ads::advertising_payload($s, $r)", api)
        self.assertIn("BlueVPN_Ads::free_access_payload($s)", api)
        self.assertIn("public static function public_config", ads)
        self.assertIn("public static function free_public_config", ads)

    def test_free_session_policy_refreshes_independent_of_pool_readiness(self):
        update = text("android-source/BlueVpnUpdateManager.kt")
        self.assertIn("fun applyRemoteMobileConfig(c: Context, config: JSONObject): Boolean", self.account)
        self.assertIn("fun refreshFreePolicy(c: Context, force: Boolean = false)", self.account)
        self.assertIn("newMinutes < oldMinutes", self.account)
        self.assertIn('.putLong("session_ends_at", allowedEnd)', self.account)
        self.assertIn("refreshFreePolicy(", self.home)
        self.assertIn("force = true", self.home)
        self.assertIn("BlueVpnAccountManager.applyRemoteMobileConfig(activity, config)", update)


class BlueVPNElementorThemeTests(unittest.TestCase):
    def test_elementor_native_theme_contract(self):
        integration = text("bluevpn-site/inc/class-bluevpn-elementor.php")
        widgets = text("bluevpn-site/inc/elementor/widgets.php")
        functions = text("bluevpn-site/functions.php")
        self.assertIn("elementor/widgets/register", integration)
        self.assertIn("elementor/theme/register_locations", integration)
        self.assertIn("register_all_core_location", integration)
        self.assertIn("_elementor_data", integration)
        self.assertIn("elementor_library", integration)
        self.assertIn("BlueVPN_Elementor_Integration::init();", integration)
        self.assertIn("class-bluevpn-elementor.php", functions)
        self.assertIn("has_meaningful_output", integration)
        self.assertIn("ob_start()", integration)
        self.assertIn("get_builder_content_for_display($post_id, true)", integration)
        for widget in (
            "bluevpn-header", "bluevpn-footer", "bluevpn-hero", "bluevpn-features",
            "bluevpn-network", "bluevpn-how", "bluevpn-premium", "bluevpn-faq",
            "bluevpn-plans", "bluevpn-download", "bluevpn-account", "bluevpn-support",
        ):
            self.assertIn(widget, widgets)

    def test_elementor_pages_keep_legacy_fallback_and_runtime_contract(self):
        for rel in ("bluevpn-site/front-page.php", "bluevpn-site/page-plans.php", "bluevpn-site/page-download.php", "bluevpn-site/page-account.php", "bluevpn-site/page-support.php"):
            src = text(rel)
            self.assertIn("BlueVPN_Elementor_Integration::render_page", src)
            self.assertIn("get_header()", src)
            self.assertIn("get_footer()", src)
        header = text("bluevpn-site/header.php")
        footer = text("bluevpn-site/footer.php")
        self.assertIn("render_location('header')", header)
        self.assertIn("render_location('footer')", footer)
        self.assertEqual(json.loads(text("branding/app.json"))["upstream_ref"], "2.2.6")


class BlueVPNSiteSEOTests(unittest.TestCase):
    def test_seo_hardening_is_loaded_and_versioned(self):
        functions = text("bluevpn-site/functions.php")
        style = text("bluevpn-site/style.css")
        self.assertIn("class-bluevpn-seo.php", functions)
        self.assertIn("BLUEVPN_SITE_VERSION', '1.0.9", functions)
        self.assertRegex(style, r"(?m)^Version:\s*1\.0\.9\s*$")

    def test_private_account_is_noindex_and_excluded_from_sitemaps(self):
        seo = text("bluevpn-site/inc/class-bluevpn-seo.php")
        self.assertIn("'account' => [", seo)
        self.assertIn("'index' => false", seo)
        self.assertIn("wp_sitemaps_posts_query_args", seo)
        self.assertIn("wpseo_exclude_from_sitemap_by_post_ids", seo)
        self.assertIn("$robots['noindex'] = true", seo)

    def test_seo_meta_social_and_schema_contract(self):
        seo = text("bluevpn-site/inc/class-bluevpn-seo.php")
        for token in (
            'meta name="description"', 'rel="canonical"', 'og:title', 'twitter:card',
            'SoftwareApplication', 'FAQPage', 'Organization', 'WebSite', 'BreadcrumbList',
        ):
            self.assertIn(token, seo)
        self.assertTrue((ROOT / "bluevpn-site/assets/images/bluevpn-social.png").exists())
        self.assertTrue((ROOT / "bluevpn-site/assets/images/bluevpn-icon.png").exists())

    def test_robots_and_llms_endpoints_are_managed(self):
        seo = text("bluevpn-site/inc/class-bluevpn-seo.php")
        self.assertIn("Disallow: /account/", seo)
        self.assertIn("Sitemap: ", seo)
        self.assertIn("/llms.txt", seo)
        self.assertIn("Important public pages", seo)
        self.assertIn("Private areas", seo)

    def test_default_yoast_metadata_and_sample_cleanup_exist(self):
        seo = text("bluevpn-site/inc/class-bluevpn-seo.php")
        self.assertIn("_yoast_wpseo_title", seo)
        self.assertIn("_yoast_wpseo_metadesc", seo)
        self.assertIn("_yoast_wpseo_meta-robots-noindex", seo)
        self.assertIn("hello-world", seo)
        self.assertIn("sample-page", seo)
        self.assertIn("wp_trash_post", seo)

    def test_seo_hardening_does_not_touch_android_runtime(self):
        app = json.loads(text("branding/app.json"))
        home = text("android-source/BlueVpnHomeActivity.kt")
        self.assertEqual(app["upstream_ref"], "2.2.6")
        self.assertIn("CoreServiceManager.startVService(this, guid)", home)
        self.assertNotIn("BlueVpnEngineManager", home)


    def test_source_version_is_authoritative_and_build_does_not_auto_increment(self):
        workflow = text(".github/workflows/build-apk.yml")
        self.assertIn("resolved = project_version", workflow)
        self.assertIn("source_declared_release_version", workflow)
        self.assertIn("Project version is behind the latest published Android release", workflow)
        self.assertNotIn("project_release_or_latest_github_release_increment", workflow)
        self.assertNotIn("patch += 1", workflow)

    def test_telegram_rest_push_verification_tolerates_head_advancing(self):
        bot = text("bluevpn-manager/includes/class-bluevpn-telegram-bot.php")
        self.assertIn("$updatedRef = self::gh('PATCH'", bot)
        self.assertIn("verify_commit_on_branch", bot)
        self.assertIn("'/compare/'", bot)
        self.assertIn("['ahead', 'identical']", bot)
        self.assertIn("usleep(250000", bot)
        self.assertNotIn("SHA شاخه پس از Push تأیید نشد.", bot)
    def test_70_logged_in_nonpremium_account_bootstraps_free_plan(self):
        home = text("android-source/BlueVpnHomeActivity.kt")
        entitlement = text("android-source/BlueVpnEntitlement.kt")
        account = text("android-source/BlueVpnAccountManager.kt")
        self.assertIn("val premiumAtLaunch = BlueVpnAccountManager.premiumEntitlementActive", home)
        self.assertIn("val needsFreeBootstrap = !premiumAtLaunch", home)
        self.assertIn("private fun prepareFreePlanAccess", home)
        helper = block(home, "private fun prepareFreePlanAccess", "private fun reconcileDeferredEntitlementIfIdle")
        self.assertIn("BlueVpnAccountManager.premiumEntitlementActive(this)", helper)
        self.assertNotIn("BlueVpnAccountManager.hasSession(this) || freePreparationInProgress", helper)
        self.assertIn("fun freeAccessConfigured", account)
        self.assertIn("val freePlanEligible = !premiumEntitled && (!freeConfigKnown || free.enabled)", entitlement)
        self.assertIn('"پلن رایگان • ${account.email}"', entitlement)

    def test_71_free_entitlement_is_not_conflated_with_pool_readiness(self):
        entitlement = text("android-source/BlueVpnEntitlement.kt")
        self.assertNotIn("val freeReady = !premiumReady && free.enabled && free.subscriptions.isNotEmpty()", entitlement)
        free_block = block(entitlement, "BlueVpnPlanTier.FREE -> BlueVpnEntitlementSnapshot", "BlueVpnPlanTier.UNAVAILABLE ->")
        self.assertIn("canConnect = true", free_block)
        self.assertIn("poolReady = guids.isNotEmpty()", free_block)


    def test_release_channels_beta_stable_contract(self):
        db = text("bluevpn-manager/includes/class-bluevpn-db.php")
        self.assertIn("app_releases", db)
        self.assertIn("beta_tester", db)
        manager = text("bluevpn-manager/includes/class-bluevpn-app-release-manager.php")
        self.assertIn("'state' => $state", manager)
        self.assertIn("promote_to_stable", manager)
        self.assertIn("release_for_customer", manager)
        self.assertIn("normal users", manager.lower())

    def test_android_update_check_sends_authenticated_channel_identity(self):
        update = text("android-source/BlueVpnUpdateManager.kt")
        self.assertIn('"Authorization"', update)
        self.assertIn('"Bearer $accessToken"', update)
        self.assertIn('"X-Device-ID"', update)

    def test_mobile_config_exposes_release_channel(self):
        api = text("bluevpn-manager/includes/class-bluevpn-api.php")
        self.assertIn("'release_channel'=>$channel", api)
        self.assertIn("'beta_tester'=>(bool)", api)

    def test_76_blueai_learning_is_tier_isolated(self):
        db = text("bluevpn-manager/includes/class-bluevpn-db.php")
        ai = text("bluevpn-manager/includes/class-bluevpn-ai.php")
        self.assertIn("plan_tier varchar(16)", db)
        self.assertIn("uq_ai_route_context_tier", db)
        self.assertIn("plan_tier=%s AND operator=%s", ai)
        self.assertIn("learning_source", ai)

    def test_77_free_guest_live_reporter_does_not_require_login(self):
        reporter = text("android-source/BlueVpnLiveReporter.kt")
        report_once = block(reporter, "private fun reportOnce", "private fun nextDelaySeconds")
        self.assertNotIn("BlueVpnAccountManager.hasSession", report_once)
        self.assertIn("BlueVpnAi.hasActiveSession", report_once)
        self.assertIn("ACTIVE_DELAY_SECONDS = 45L", reporter)

    def test_78_android_blueai_sends_tier_and_schema_capability(self):
        ai = text("android-source/BlueVpnAi.kt")
        account = text("android-source/BlueVpnAccountManager.kt")
        self.assertIn("AI_SCHEMA_VERSION = 2", ai)
        self.assertIn('.put("plan_tier", planTier(context))', ai)
        self.assertIn('.put("ai_schema_version", AI_SCHEMA_VERSION)', ai)
        self.assertIn('"&plan_tier=" + java.net.URLEncoder.encode(planTier', account)

    def test_79_wordpress_blueai_has_live_dashboard_and_version_health(self):
        ai = text("bluevpn-manager/includes/class-bluevpn-ai.php")
        api = text("bluevpn-manager/includes/class-bluevpn-api.php")
        self.assertIn("wp_ajax_bluevpn_ai_live_snapshot", ai)
        self.assertIn("public static function live_snapshot", ai)
        self.assertIn("public static function version_health", ai)
        self.assertIn("پایش هوشمند همزمان Free + Premium", ai)
        self.assertIn("'engine_version'=>BlueVPN_AI::ENGINE_VERSION", api)
        self.assertIn("'capabilities'=>BlueVPN_AI::capabilities()", api)

    def test_80_mobile_update_config_is_cache_first_and_nonblocking(self):
        api = text("bluevpn-manager/includes/class-bluevpn-api.php")
        manager = text("bluevpn-manager/includes/class-bluevpn-app-release-manager.php")
        mobile = block(api, "public static function mobile_config", "public static function ad_asset")
        self.assertIn("maybe_kick($forced)", mobile)
        self.assertNotIn("sync_now(true, 'android_forced_refresh')", mobile)
        self.assertIn("release_refresh_mode'=>'background_cache_first'", mobile)
        self.assertIn("maybe_kick(bool $force = false)", manager)

    def test_81_mobile_config_release_channel_failure_falls_back_to_stable_settings(self):
        api = text("bluevpn-manager/includes/class-bluevpn-api.php")
        mobile = block(api, "public static function mobile_config", "public static function ad_asset")
        self.assertIn("release selection fallback", mobile)
        self.assertIn("$selection = ['release'=>null,'channel'=>'stable','beta_tester'=>false]", mobile)

    def test_82_blueai_admin_explains_legacy_schema_without_fake_live_count(self):
        ai = text("bluevpn-manager/includes/class-bluevpn-ai.php")
        self.assertIn("کلاینت قدیمی شناسایی شد", ai)
        self.assertIn("AI Schema v1", ai)
        self.assertIn("Android 4.3.2+", ai)

    def test_83_beta_and_stable_have_independent_auto_update_policy(self):
        db = text("bluevpn-manager/includes/class-bluevpn-db.php")
        api = text("bluevpn-manager/includes/class-bluevpn-api.php")
        cc = text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        self.assertIn("'auto_update_stable' => true", db)
        self.assertIn("'auto_update_beta' => true", db)
        self.assertIn("$autoUpdate = $channel === 'beta' ? $betaAutoUpdate : $stableAutoUpdate", api)
        self.assertIn("'auto_update'=>$autoUpdate", api)
        self.assertIn('name="auto_update_beta"', cc)
        self.assertIn('name="auto_update_stable"', cc)

    def test_84_beta_android_update_pipeline_is_same_as_stable(self):
        update = text("android-source/BlueVpnUpdateManager.kt")
        self.assertIn('KEY_RELEASE_CHANNEL = "remote_release_channel"', update)
        self.assertIn('.putString(KEY_RELEASE_CHANNEL, releaseChannel)', update)
        self.assertIn('if (updateChannel(activity) == "beta") "BlueVPN Beta $version آماده است"', update)
        apply = block(update, "private fun applyRemoteConfig", "private fun showStoredBlockIfNeeded")
        self.assertIn("updateAvailable && autoUpdate", apply)
        self.assertIn("startAutomaticDownload", apply)
        self.assertIn("forced ->", apply)
        self.assertIn("showForcedUpdateDialog", apply)

    def test_85_advertising_mobile_config_contract_survives_release_channel_changes(self):
        api = text("bluevpn-manager/includes/class-bluevpn-api.php")
        ads_android = text("android-source/BlueVpnAdsCarouselView.kt")
        mobile = block(api, "public static function mobile_config", "public static function ad_asset")
        self.assertIn("$advertising = BlueVPN_Ads::advertising_payload($s, $r);", mobile)
        self.assertIn("$tapsell = BlueVPN_Ads::tapsell_payload($s);", mobile)
        self.assertIn("'advertising'=>$advertising", mobile)
        self.assertIn("'ads'=>$advertising", mobile)
        self.assertIn("'tapsell'=>$tapsell", mobile)
        self.assertIn('root.optJSONObject("advertising") ?: root.optJSONObject("ads")', ads_android)

    def test_86_free_story_ads_are_exposed_only_as_free_connection_gate(self):
        api = text("bluevpn-manager/includes/class-bluevpn-api.php")
        ads = text("bluevpn-manager/includes/class-bluevpn-ads.php")
        mobile = block(api, "public static function mobile_config", "public static function ad_asset")
        self.assertIn("$freeStoryAds = BlueVPN_Ads::free_story_payload($s);", mobile)
        self.assertIn("'free_story_ads'=>$freeStoryAds", mobile)
        self.assertIn("'free_only' => true", ads)
        self.assertIn("'random' => true", ads)
        self.assertIn("'every_connection' => true", ads)

    def test_87_free_story_gate_finalizes_timer_only_after_completion(self):
        home = text("android-source/BlueVpnHomeActivity.kt")
        gate = text("android-source/BlueVpnFreeStoryAdGate.kt")
        complete = block(home, "private fun completeFailover", "private fun refreshVerifiedExitLocation")
        self.assertIn("beginFreeStoryGate", complete)
        self.assertIn("BlueVpnAccountManager.startFreeSession(this)", complete)
        self.assertGreater(complete.index("beginFreeStoryGate"), -1)
        self.assertIn("Outcome.COMPLETED", complete)
        self.assertIn("storyAdShown = true", complete)
        self.assertIn("weightedRandom(items)", gate)

    def test_88_mandatory_story_cannot_be_bypassed_by_backgrounding(self):
        home = text("android-source/BlueVpnHomeActivity.kt")
        on_stop = block(home, "override fun onStop()", "override fun onTrimMemory")
        self.assertIn("freeStoryGate?.abort()", on_stop)
        gate = text("android-source/BlueVpnFreeStoryAdGate.kt")
        self.assertIn("Outcome.ABORTED", gate)
        self.assertIn("setCancelable(!required)", gate)

    def test_89_story_media_failure_is_fail_open_and_does_not_stack_tapsell(self):
        home = text("android-source/BlueVpnHomeActivity.kt")
        complete = block(home, "private fun completeFailover", "private fun refreshVerifiedExitLocation")
        self.assertIn("Outcome.UNAVAILABLE", complete)
        self.assertIn("storyAdShown = false", complete)
        self.assertIn("!storyAdShown", complete)
        self.assertIn("BlueVpnTapsellManager.onVerifiedConnection", complete)

    def test_90_wordpress_convergence_accepts_newer_manager_and_schema(self):
        workflow = text(".github/workflows/build-apk.yml")
        wait = block(workflow, "- name: Wait for WordPress control-plane auto-update", "- name: Create GitHub Release metadata and checksums")
        self.assertIn("cv >= ev and cs >= es", wait)
        self.assertIn("installed=${LAST_VERSION} (minimum=${VERSION})", wait)
        self.assertNotIn('[ "$LAST_VERSION" = "$VERSION" ] && [ "$LAST_SCHEMA" = "$EXPECTED_SCHEMA" ]', wait)


    def test_91_ad_destinations_are_allow_listed_and_structured(self):
        ads = text("bluevpn-manager/includes/class-bluevpn-ads.php")
        self.assertIn("private const TARGET_ACTIONS", ads)
        for action in ("auth", "plans", "purchase", "account", "renew", "settings", "external"):
            self.assertIn(f"'{action}'", ads)
        self.assertIn("'target_action' => $targetAction", ads)
        self.assertIn("'target_plan_id' => $targetPlanId", ads)
        self.assertIn("'deep_link' => self::deep_link", ads)
        self.assertIn("wp_http_validate_url", ads)

    def test_92_android_ad_router_never_launches_arbitrary_components(self):
        router = text("android-source/BlueVpnAdActionRouter.kt")
        self.assertIn("private val allowed = setOf", router)
        self.assertIn("BlueVpnSubscriptionsActivity::class.java", router)
        self.assertIn("BlueVpnSettingsActivity::class.java", router)
        self.assertIn('scheme == "https" || scheme == "http"', router)
        self.assertNotIn("Class.forName", router)
        self.assertNotIn("setClassName", router)
        self.assertNotIn("Intent.parseUri", router)
        self.assertIn('bluevpn://$normalized', router)

    def test_93_banner_and_story_ctas_use_same_internal_router(self):
        banner = text("android-source/BlueVpnAdsCarouselView.kt")
        story = text("android-source/BlueVpnFreeStoryAdGate.kt")
        self.assertIn("BlueVpnAdActionRouter.open", banner)
        self.assertIn('targetAction = row.optString("target_action")', banner)
        self.assertIn("BlueVpnAdActionRouter.open", story)
        self.assertIn("Outcome.ACTION_OPENED", story)
        self.assertIn("stopConnectionImmediately()", text("android-source/BlueVpnHomeActivity.kt"))
        self.assertIn('bluevpn_dir / "BlueVpnAdActionRouter.kt"', text("scripts/prepare_android.py"))

    def test_94_ad_purchase_continues_after_auth_without_auto_charging(self):
        subs = text("android-source/BlueVpnSubscriptionsActivity.kt")
        self.assertIn("EXTRA_ENTRY_ROUTE", subs)
        self.assertIn("EXTRA_PLAN_ID", subs)
        self.assertIn("برای ادامه خرید یا تمدید", subs)
        self.assertIn("selectedByAd", subs)
        self.assertIn("پیشنهاد این تبلیغ", subs)
        # A deep-linked plan is highlighted, but payment still requires the explicit plan CTA.
        self.assertIn('BlueVpnUiGuard.bind(this){\n    buy(p.optInt("id"))', subs)



if __name__ == "__main__":
    unittest.main(verbosity=2)
