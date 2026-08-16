import pathlib, unittest, json, re

ROOT=pathlib.Path(__file__).resolve().parents[1]

class BackgroundGuardCoreVisibility4110(unittest.TestCase):
    def text(self,p): return (ROOT/p).read_text()

    def test_background_reliability_manager_detects_battery_and_data_restrictions(self):
        s=self.text("android-source/BlueVpnBackgroundReliability.kt")
        self.assertIn("isIgnoringBatteryOptimizations",s)
        self.assertIn("restrictBackgroundStatus",s)
        self.assertIn("ACTION_IGNORE_BACKGROUND_DATA_RESTRICTIONS_SETTINGS",s)
        self.assertIn("ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS",s)

    def test_settings_exposes_background_reliability(self):
        s=self.text("android-source/BlueVpnSettingsActivity.kt")
        self.assertIn("پایداری اتصال در پس‌زمینه",s)
        self.assertIn("showBackgroundReliability()",s)
        self.assertIn("BlueVpnBackgroundReliability.state(this)",s)

    def test_verified_premium_and_recovered_sessions_start_application_keepalive(self):
        s=self.text("android-source/BlueVpnHomeActivity.kt")
        self.assertGreaterEqual(s.count("BlueVpnWarpKeepAliveService.start(this)"),2)
        self.assertIn("maybePromptBackgroundReliability()",s)

    def test_system_premium_start_uses_stock_core_foreground_owner(self):
        s=self.text("android-source/BlueVpnSystemController.kt")
        premium=s[s.index("} else {",s.index("fun start(context")):s.index("private suspend fun startFreeWarp")]
        self.assertIn("CoreServiceManager.startVServiceFromToggle(app)",premium)
        self.assertNotIn("BlueVpnWarpKeepAliveService.start(app)",premium)

    def test_guardcore_snapshot_records_provider_specific_count_without_raw_configs(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        self.assertIn("'guardcore'=>'guardcore_subscription_url'",s)
        self.assertIn("'count'=>count($providerLines)",s)
        self.assertIn("'content_hash'=>hash('sha256'",s)
        self.assertIn("subscription_snapshot_stats",s)
        stats=s[s.index("public static function subscription_snapshot_stats"):
                s.index("public static function request_background_snapshot")]
        self.assertIn("'guardcore_count'",stats)
        self.assertNotIn("'lines'=>",stats)

    def test_guardcore_admin_shows_assignments_and_config_counts(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        self.assertIn("آمار تخصیص GuardCore",s)
        self.assertIn("کانفیگ GuardCore",s)
        self.assertIn("guardcore_username",s)
        self.assertIn("guardcore_subscription_id",s)
        self.assertIn("subscription_snapshot_stats",s)
        self.assertIn("بروزرسانی آمار GuardCore",s)

    def test_guardcore_refresh_is_background_queued(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        block=s[s.index("public static function refresh_guardcore_stats"):
                s.index("public static function attach_guardcore")]
        self.assertIn("request_background_snapshot",block)
        self.assertNotIn("wp_remote_get",block)

if __name__=="__main__":
    unittest.main()
