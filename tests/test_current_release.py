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


class Release4110Tests(unittest.TestCase):
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
        self.assertEqual((self.app["version_name"], self.app["version_code"]), ("4.1.10", 40110))

    def test_02_release_version(self):
        self.assertEqual((self.release["version"], self.release["version_code"]), ("4.1.10", 40110))

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
        self.assertRegex(text("bluevpn-manager/bluevpn-manager.php"), r"Version:\s*4\.1\.10")
        self.assertRegex(text("bluevpn-manager/readme.txt"), r"Stable tag:\s*4\.1\.10")

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
        self.assertIn("val needsGuestBootstrap = if (!hadSession)", pipeline)
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


if __name__ == "__main__":
    unittest.main(verbosity=2)
