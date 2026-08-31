from pathlib import Path
import json
import unittest

ROOT = Path(__file__).resolve().parents[1]


class AdsAllTierResponsive617Tests(unittest.TestCase):
    def test_android_uses_bot_as_primary_control_plane(self):
        branding = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
        self.assertEqual(branding["api_base_url"], "https://bot.blluepanel.ir")
        self.assertEqual(
            branding["api_base_urls"],
            ["https://bot.blluepanel.ir"],
        )

    def test_tapsell_android_has_no_subscription_tier_gate(self):
        manager = (ROOT / "android-source/BlueVpnTapsellManager.kt").read_text(encoding="utf-8")
        placement = manager[manager.index("private fun placementEligible("):]
        self.assertNotIn('policy.type != "standard_banner"', placement)
        bridge = manager[manager.index("fun attachPlacement("):manager.index("private fun placementEligible(")]
        self.assertNotIn("resolveUi(activity).isFree", bridge)
        self.assertIn("account-tier agnostic", manager)

    def test_control_plane_marks_ads_all_tier(self):
        ads = (ROOT / "bluevpn-manager/includes/class-bluevpn-ads.php").read_text(encoding="utf-8")
        tapsell = ads[ads.index("public static function tapsell_payload"):ads.index("public static function free_sources")]
        story = ads[ads.index("public static function free_story_payload"):ads.index("private static function tapsell_mediation_app_id")]
        self.assertNotIn("'free_only' => true", tapsell)
        self.assertIn("'free_only' => false", tapsell)
        self.assertIn("'free_only' => false", story)
        self.assertIn("فعال‌سازی Tapsell Android برای همه کاربران", ads)

    def test_android_campaign_artwork_is_fit_not_cropped(self):
        carousel = (ROOT / "android-source/BlueVpnAdsCarouselView.kt").read_text(encoding="utf-8")
        self.assertIn("ImageView.ScaleType.FIT_CENTER", carousel)
        self.assertNotIn("ImageView.ScaleType.CENTER_CROP", carousel)
        self.assertIn("artworkAspectRatio", carousel)
        self.assertIn('Regex("""BANNER_(\\d+)_(\\d+)""")', carousel)
        self.assertIn("coerceIn(dp(96), dp(280))", carousel)

    def test_windows_web_reports_real_creative_height(self):
        main = (ROOT / "bluevpn-windows/MainWindow.xaml.cs").read_text(encoding="utf-8")
        xaml = (ROOT / "bluevpn-windows/MainWindow.xaml").read_text(encoding="utf-8")
        site = (ROOT / "bluevpn-site/functions.php").read_text(encoding="utf-8")
        self.assertIn("BLUEVPN_TAPSELL_SIZE:", main)
        self.assertIn("ApplyTapsellContentHeight", main)
        self.assertIn('MinHeight="90" MaxHeight="360"', xaml)
        self.assertIn("ResizeObserver", site)
        self.assertIn("document.body.scrollHeight", site)


if __name__ == "__main__":
    unittest.main()
