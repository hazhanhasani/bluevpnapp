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


class Release418Tests(unittest.TestCase):
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
        self.assertEqual((self.app["version_name"], self.app["version_code"]), ("4.1.8", 40108))

    def test_02_release_version(self):
        self.assertEqual((self.release["version"], self.release["version_code"]), ("4.1.8", 40108))

    def test_03_official_pairing(self):
        self.assertEqual(self.app["upstream_ref"], "2.2.6")
        self.assertEqual(self.app["xray_ref"], "v26.6.27")
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
        self.assertIn('EXPECTED_TAG="${{ steps.config.outputs.xray_ref }}"', self.workflow)
        self.assertNotIn('PINNED_COMMIT="$(git rev-list -n 1', self.workflow)
        self.assertIn('if [ -n "$EXPECTED_TAG" ] && [ "$CURRENT_TAG" != "$EXPECTED_TAG" ]; then', self.workflow)

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
        self.assertRegex(text("bluevpn-manager/bluevpn-manager.php"), r"Version:\s*4\.1\.8")
        self.assertRegex(text("bluevpn-manager/readme.txt"), r"Stable tag:\s*4\.1\.8")

    def test_26_short_semver(self):
        self.assertLessEqual(int(self.app["version_name"].split(".")[2]), 10)

    def test_27_no_old_release_number(self):
        for rel in ("branding/app.json", "release.json", "README.md", "NOTICE.md"):
            self.assertNotIn("4.1.7", text(rel))


if __name__ == "__main__":
    unittest.main(verbosity=2)
