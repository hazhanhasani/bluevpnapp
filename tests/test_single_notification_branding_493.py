import pathlib, unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]

class SingleNotificationBranding493(unittest.TestCase):
    def text(self,p): return (ROOT/p).read_text()

    def test_keepalive_shares_core_notification_id(self):
        s=self.text("android-source/BlueVpnWarpKeepAliveService.kt")
        self.assertIn("private const val NOTIFICATION_ID = 1", s)
        self.assertNotIn("private const val NOTIFICATION_ID = 7319", s)
        self.assertIn("single visible BlueVPN notification", s)

    def test_keepalive_does_not_compete_with_core_speed_updates(self):
        s=self.text("android-source/BlueVpnWarpKeepAliveService.kt")
        command=s[s.index("override fun onStartCommand"):s.index("private fun createChannel")]
        self.assertNotIn("handler.postDelayed(updater, UPDATE_MS)", command)

    def test_free_bridge_remark_is_branded_only(self):
        s=self.text("android-source/BlueVpnWarpEngine.kt")
        self.assertIn('remarks = "BlueVPN Free"', s)
        self.assertNotIn('remarks = "BlueVPN Free • Cloudflare WARP"', s)

    def test_public_ui_hides_provider_and_transport_names(self):
        home=self.text("android-source/BlueVpnHomeActivity.kt")
        ent=self.text("android-source/BlueVpnEntitlement.kt")
        self.assertNotIn('"Cloudflare WARP"', home)
        self.assertNotIn('"☁️ Cloudflare WARP"', home)
        self.assertNotIn('"Cloudflare WARP"', ent)
        self.assertIn('"BlueVPN Free"', ent)

    def test_upstream_core_notification_is_rebranded(self):
        s=self.text("scripts/prepare_android.py")
        self.assertIn("Public system UI uses BlueVPN branding", s)
        self.assertIn("BlueVpnPublicProfileName.forProfile(service, currentConfig)", s)
        self.assertIn("Raw v2rayNG profile remarks still leak into notification title", s)

if __name__=="__main__": unittest.main()
