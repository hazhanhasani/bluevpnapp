import pathlib
import unittest

ROOT=pathlib.Path(__file__).resolve().parents[1]

class ConnectionContinuity4112(unittest.TestCase):
    def text(self,p):
        return (ROOT/p).read_text()

    def test_live_reporter_never_restarts_live_vpn_from_quality_only(self):
        s=self.text("android-source/BlueVpnLiveReporter.kt")
        block=s[s.index("if (health.shouldWarmFailover"):s.index("BlueVpnAi.heartbeat")]
        self.assertNotIn("BlueVpnSystemController.predictiveFailover",block)
        self.assertIn("PREDICTIVE_DEGRADATION_OBSERVED",block)

    def test_predictive_failover_entry_point_is_non_destructive(self):
        s=self.text("android-source/BlueVpnSystemController.kt")
        block=s[s.index("fun predictiveFailover"):s.index("fun restart")]
        self.assertNotIn("restart(app)",block)
        self.assertNotIn("stopVService",block)
        self.assertIn("PREDICTIVE_DEGRADATION_NON_DESTRUCTIVE",block)

    def test_recovery_notice_preserves_live_premium_and_free_warp(self):
        s=self.text("android-source/BlueVpnHomeActivity.kt")
        block=s[s.index("if (BlueVpnUiGuard.consumeRecoveryNotice"):s.index("connectButton = findViewById")]
        self.assertIn("CoreServiceManager.isRunning()",block)
        self.assertIn("BlueVpnWarpEngine.isRunning()",block)
        self.assertIn("اتصال فعال بدون قطع‌شدن حفظ شد",block)
        # Clearing is allowed only in the real-dead branch.
        self.assertLess(block.index("if (transportAlive)"),block.index("BlueVpnPreferences.clearConnected"))

    def test_transient_verification_failure_does_not_stop_live_transport(self):
        s=self.text("android-source/BlueVpnHomeActivity.kt")
        block=s[s.index("private fun recoverUnverifiedExistingSession"):
                s.index("private fun verifyTunnelThroughCore")]
        self.assertIn("val transportAlive",block)
        alive=block[block.index("if (transportAlive)"):block.index("// Hard recovery")]
        self.assertNotIn("stopVService",alive)
        self.assertNotIn("BlueVpnWarpEngine.stop",alive)
        self.assertIn("15_000L",alive)
        self.assertIn("preserveServiceOnFailure = true",alive)

    def test_hard_recovery_requires_actual_transport_death(self):
        s=self.text("android-source/BlueVpnHomeActivity.kt")
        block=s[s.index("private fun recoverUnverifiedExistingSession"):
                s.index("private fun verifyTunnelThroughCore")]
        hard=block[block.index("// Hard recovery"):]
        self.assertIn("LauncherManager.stopService(this)",hard)
        self.assertIn("سرویس اتصال متوقف شده",hard)

    def test_premium_does_not_start_second_foreground_owner(self):
        s=self.text("android-source/BlueVpnSystemController.kt")
        start=s[s.index("fun start(context"):s.index("private suspend fun startFreeWarp")]
        premium=start[start.index("} else {"):]
        self.assertIn("LauncherManager.startServiceFromToggle(app)",premium)
        self.assertNotIn("BlueVpnWarpKeepAliveService.start(app)",premium)

    def test_keepalive_is_conditional_to_free_warp_in_home(self):
        s=self.text("android-source/BlueVpnHomeActivity.kt")
        self.assertGreaterEqual(
            s.count("BlueVpnAccountManager.isFreeMode(this) && BlueVpnAccountManager.warpFreeEnabled(this)"),
            2,
        )

    def test_free_warp_notification_updater_is_started(self):
        s=self.text("android-source/BlueVpnWarpKeepAliveService.kt")
        block=s[s.index("override fun onStartCommand"):s.index("private fun createChannel")]
        self.assertIn("handler.removeCallbacks(updater)",block)
        self.assertIn("handler.post(updater)",block)
        self.assertLess(block.index("handler.removeCallbacks(updater)", block.index("startForeground")),
                        block.index("handler.post(updater)"))

if __name__=="__main__":
    unittest.main()
