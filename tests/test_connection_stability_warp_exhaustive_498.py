import pathlib, unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]

class ConnectionStabilityWarpExhaustive498(unittest.TestCase):
    def text(self,p): return (ROOT/p).read_text()

    def test_predictive_failover_requires_repeated_bad_samples(self):
        s=self.text("android-source/BlueVpnIntelligenceCore.kt")
        self.assertIn("badStreak >= 3", s)
        self.assertIn("sessionAgeMs >= 25_000L", s)
        self.assertIn("A single noisy RTT/loss probe must never restart a working VPN", s)

    def test_live_reporter_records_confirmed_degradation_without_tearing_down_vpn(self):
        s=self.text("android-source/BlueVpnLiveReporter.kt")
        self.assertIn("health.shouldWarmFailover", s)
        self.assertNotIn("if (health.degraded && selectedGuid.isNotBlank())", s)
        self.assertIn("PREDICTIVE_DEGRADATION_OBSERVED", s)
        block=s[s.index("if (health.shouldWarmFailover"):s.index("BlueVpnAi.heartbeat")]
        self.assertNotIn("predictiveFailover", block)

    def test_crash_recovery_does_not_force_low_end_runtime(self):
        s=self.text("android-source/BlueVpnTheme.kt")
        self.assertNotIn("BlueVpnUiGuard.safeMode(app) ||", s)
        self.assertIn("Low-end mode is based only on actual device capability", s)

    def test_warp_retries_alternate_fresh_scan_before_strategy_failure(self):
        s=self.text("android-source/BlueVpnWarpEngine.kt")
        self.assertIn("freshScanPlan", s)
        self.assertIn("retryableFreshScanFailure", s)
        self.assertIn("val hasAnotherPass = passIndex < passes.lastIndex", s)
        self.assertIn("Route candidate failed; retrying strategy=", s)
        self.assertIn("scanModeOverride", s)

    def test_warp_scan_plan_is_bounded_to_two_distinct_profiles(self):
        s=self.text("android-source/BlueVpnWarpEngine.kt")
        self.assertIn("return listOf(primary, fallback).distinct()", s)
        self.assertIn('"turbo" -> "ironclad"', s)
        self.assertIn('"ironclad" -> "turbo"', s)

    def test_real_tunnel_validation_remains_mandatory(self):
        s=self.text("android-source/BlueVpnWarpEngine.kt")
        self.assertIn("socksGreetingAndRemoteConnect", s)
        self.assertIn("validateViaSocks", s)
        self.assertIn("No tunneled HTTPS probe succeeded", s)
        self.assertIn("Exit country could not be validated", s)

if __name__=="__main__":
    unittest.main()
