import pathlib
import unittest

ROOT=pathlib.Path(__file__).resolve().parents[1]

class WarpOffSmartPool4117(unittest.TestCase):
    def text(self,p):
        return (ROOT/p).read_text()

    def test_panel_exposes_smart_curated_pool_as_first_class_subscription(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-ads.php")
        self.assertIn("'id' => 'smart-curated'",s)
        self.assertIn("'name' => 'BlueVPN Smart Free Pool'",s)
        self.assertIn("BlueVPN_Free_Sources::has_enabled_sources()",s)
        self.assertIn("/api/v1/free/subscriptions/",s)

    def test_warp_off_does_not_disable_free_entitlement(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-ads.php")
        self.assertIn("$poolRequested",s)
        self.assertIn("(!$warpEnabled && $mode !== 'warp_only')",s)
        self.assertIn("$enabled = $warpEnabled || $legacyPoolEnabled",s)

    def test_old_4116_settings_are_migration_safe(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-ads.php")
        self.assertIn("Migration-safe intent inference",s)
        self.assertIn("$poolEnabled = !empty($settings['free_access_enabled']) || (!$warpEnabled && $mode !== 'warp_only');",s)

    def test_smart_curated_endpoint_is_local_not_loopback_http(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-ads.php")
        block=s[s.index("public static function free_subscription"):
                s.index("public static function serve_raw_response")]
        self.assertIn("$id === 'smart-curated'",block)
        self.assertIn("BlueVPN_Free_Sources::subscription_text(160)",block)
        self.assertIn("self::raw_text_response",block)
        self.assertNotIn("wp_remote_get",block)

    def test_disabling_warp_in_admin_switches_to_pool_only(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-ads.php")
        block=s[s.index("public static function save_free_settings"):
                s.index("public static function add_free_source")]
        self.assertIn("if (!$warpRequested && $mode !== 'warp_only') $mode = 'pool_only';",block)
        self.assertIn("(!$warpRequested && $mode === 'pool_only')",block)

    def test_admin_no_longer_calls_pool_legacy(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-ads.php")
        self.assertIn("Smart Free Pool فعال",s)
        self.assertIn("WARP اصلی + Smart Pool پشتیبان",s)
        self.assertIn("فقط Smart Free Pool",s)
        self.assertNotIn("Pool رایگان قدیمی فعال",s)

    def test_pool_only_android_prepares_configs_on_resume(self):
        s=self.text("android-source/BlueVpnHomeActivity.kt")
        start=s.index("override fun onResume")
        resume=s[start:start+2600]
        self.assertIn("BlueVpnAccountManager.freeAccessEnabled(this)",resume)
        self.assertIn("!BlueVpnAccountManager.warpFreeEnabled(this)",resume)
        self.assertIn("prepareFreePlanAccess(force = false)",resume)

    def test_successful_pool_import_starts_background_optimizer(self):
        s=self.text("android-source/BlueVpnHomeActivity.kt")
        block=s[s.index("private fun prepareFreePlanAccess"):s.index("private fun reconcileDeferredEntitlementIfIdle")]
        self.assertIn("BlueVpnBackgroundOptimizer.markPending",block)
        self.assertIn("BlueVpnBackgroundOptimizer.maybeStart",block)

    def test_free_source_collector_has_enabled_source_probe(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-free-sources.php")
        self.assertIn("public static function has_enabled_sources()",s)
        self.assertIn("SELECT COUNT(*) FROM {$t} WHERE enabled=1",s)
        self.assertIn("public static function ensure_seeded_pool()",s)

if __name__=="__main__":
    unittest.main()
