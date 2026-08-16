import pathlib
import unittest

ROOT=pathlib.Path(__file__).resolve().parents[1]

class BackgroundFullPoolOptimizer4113(unittest.TestCase):
    def text(self,p):
        return (ROOT/p).read_text()

    def test_optimizer_is_copied_into_android_runtime(self):
        prep=self.text("scripts/prepare_android.py")
        self.assertIn("BlueVpnBackgroundOptimizer.kt",prep)

    def test_permission_transition_triggers_optimizer(self):
        reliability=self.text("android-source/BlueVpnBackgroundReliability.kt")
        settings=self.text("android-source/BlueVpnSettingsActivity.kt")
        home=self.text("android-source/BlueVpnHomeActivity.kt")
        self.assertIn("observeAndMaybeOptimize",reliability)
        self.assertIn("force = !previousReady",reliability)
        self.assertIn("BlueVpnBackgroundReliability.observeAndMaybeOptimize(this)",settings)
        self.assertIn("BlueVpnBackgroundReliability.observeAndMaybeOptimize(this)",home)

    def test_optimizer_tests_entire_entitlement_pool_not_fast_sample_only(self):
        s=self.text("android-source/BlueVpnBackgroundOptimizer.kt")
        self.assertIn("BlueVpnLocationUtil.allCandidates(context, forceRefresh = true)",s)
        self.assertIn(".distinctBy { it.guid }",s)
        self.assertIn("for (batch in candidates.chunked(BATCH_SIZE))",s)
        self.assertNotIn(".take(8)",s)

    def test_optimizer_uses_two_fresh_real_ping_passes(self):
        s=self.text("android-source/BlueVpnBackgroundOptimizer.kt")
        self.assertIn("val first = measurePass(context, guids)",s)
        self.assertIn("val second = measurePass(context, guids)",s)
        self.assertIn("MmkvManager.clearAllTestDelayResults(guids)",s)
        self.assertIn("MSG_MEASURE_CONFIG_START",s)
        self.assertIn("jitter = if (samples.size >= 2)",s)

    def test_optimizer_is_network_and_entitlement_specific(self):
        s=self.text("android-source/BlueVpnBackgroundOptimizer.kt")
        self.assertIn("BlueVpnIntelligenceCore.networkFingerprint(context).id",s)
        self.assertIn("entitlementIdentityFingerprint(context)",s)
        self.assertIn('return "$network|${entitlementId(context)}"',s)

    def test_optimizer_does_not_benchmark_through_active_vpn(self):
        s=self.text("android-source/BlueVpnBackgroundOptimizer.kt")
        self.assertIn("while (CoreServiceManager.isRunning())",s)
        self.assertIn("if (CoreServiceManager.isRunning()) return",s)
        self.assertNotIn("stopVService",s)

    def test_full_pool_results_are_categorized_and_persisted(self):
        s=self.text("android-source/BlueVpnBackgroundOptimizer.kt")
        for bucket in ["FAST","STABLE","RESERVE","FAILED"]:
            self.assertIn(bucket,s)
        self.assertIn('"fast"',s)
        self.assertIn('"stable"',s)
        self.assertIn('"reserve"',s)
        self.assertIn('"failed"',s)
        self.assertIn("persistFinal",s)

    def test_full_pool_results_feed_route_intelligence(self):
        s=self.text("android-source/BlueVpnBackgroundOptimizer.kt")
        self.assertIn("BlueVpnIntelligenceCore.recordRouteOutcome",s)
        self.assertIn("packetLossX100 = lossX100",s)
        self.assertIn("BACKGROUND_POOL_PROBE_",s)

    def test_smart_selector_uses_background_measurements(self):
        s=self.text("android-source/BlueVpnSmartSelector.kt")
        self.assertIn("BlueVpnBackgroundOptimizer.rankingAdjustment",s)
        self.assertIn("BlueVpnBackgroundOptimizer.evidence",s)
        self.assertIn("score += backgroundAdjustment",s)
        self.assertIn("backgroundEvidence != null",s)

    def test_servers_ui_shows_user_network_category(self):
        s=self.text("android-source/BlueVpnServersActivity.kt")
        self.assertIn("BlueVpnBackgroundOptimizer.bestBucket",s)
        self.assertIn("دسته‌بندی شبکه شما",s)
        self.assertIn("bucketLabel",s)

    def test_settings_allows_manual_full_rescan(self):
        s=self.text("android-source/BlueVpnSettingsActivity.kt")
        self.assertIn("تست کامل کانفیگ‌ها",s)
        self.assertIn("BlueVpnBackgroundOptimizer.forceStart(this)",s)
        self.assertIn("سریع ${optimizer.fast}",s)
        self.assertIn("پایدار ${optimizer.stable}",s)

if __name__=="__main__":
    unittest.main()
