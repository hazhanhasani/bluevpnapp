import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]


class TapsellSliderPremium575Tests(unittest.TestCase):
    def test_android_carousel_allows_standard_banner_for_premium(self):
        carousel = (ROOT / "android-source/BlueVpnAdsCarouselView.kt").read_text(encoding="utf-8")
        manager = (ROOT / "android-source/BlueVpnTapsellManager.kt").read_text(encoding="utf-8")
        apply_config = carousel[carousel.index("private fun applyConfig"):carousel.index("private fun prefetchUpcomingImages")]
        standard = manager[manager.index("fun attachStandardBanner("):manager.index("fun attachPlacement(")]
        self.assertNotIn("resolveUi(context).isFree", apply_config)
        self.assertNotIn("resolveUi(activity).isFree", standard)
        self.assertIn('policy.type != "standard_banner"', manager)

    def test_control_plane_marks_only_slider_banner_as_premium_capable(self):
        ads = (ROOT / "bluevpn-manager/includes/class-bluevpn-ads.php").read_text(encoding="utf-8")
        self.assertIn("'free_only' => $type !== 'standard_banner'", ads)
        self.assertIn("'windows_web' => [", ads)
        windows_payload = ads[ads.index("'windows_web' => ["):ads.index("'build_embed_required'")]
        self.assertIn("'free_only' => false", windows_payload)
        self.assertIn("$s['tapsell_windows_web_free_only'] = false", ads)

    def test_windows_carousel_banner_defaults_to_all_plans(self):
        models = (ROOT / "bluevpn-windows/Models/WindowsRuntimeModels.cs").read_text(encoding="utf-8")
        service = (ROOT / "bluevpn-windows/Services/AdvertisementService.cs").read_text(encoding="utf-8")
        config = models[models.index("class TapsellWindowsWebConfig"):models.index("class TapsellPlacementConfig")]
        self.assertIn('JsonPropertyName("free_only")', config)
        self.assertNotIn("= true", config)
        self.assertIn("cfg.FreeOnly && premium", service)


if __name__ == "__main__":
    unittest.main()
