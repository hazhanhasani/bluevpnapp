import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]


class TapsellPremiumCarouselWindowsBridge577Tests(unittest.TestCase):
    def test_android_all_tiers_can_use_every_configured_tapsell_surface(self):
        carousel = (ROOT / "android-source/BlueVpnAdsCarouselView.kt").read_text(encoding="utf-8")
        manager = (ROOT / "android-source/BlueVpnTapsellManager.kt").read_text(encoding="utf-8")
        rotate = carousel[carousel.index("private fun scheduleNext"):carousel.index("private fun showTapsellBanner")]
        standard = carousel[carousel.index("private fun showTapsellBanner"):carousel.index("private fun hideTapsellBanner")]
        self.assertNotIn("isFree", rotate)
        self.assertNotIn("isFree", standard)
        self.assertNotIn('policy.type != "standard_banner"', manager)
        other = manager[manager.index("fun attachPlacement("):manager.index("private fun placementEligible(")]
        self.assertNotIn("resolveUi(activity).isFree", other)
        self.assertIn("account-tier agnostic", manager)

    def test_windows_uses_real_wordpress_origin_and_never_labels_fallback_as_tapsell(self):
        ads = (ROOT / "bluevpn-manager/includes/class-bluevpn-ads.php").read_text(encoding="utf-8")
        model = (ROOT / "bluevpn-windows/Models/WindowsRuntimeModels.cs").read_text(encoding="utf-8")
        code = (ROOT / "bluevpn-windows/MainWindow.xaml.cs").read_text(encoding="utf-8")
        xaml = (ROOT / "bluevpn-windows/MainWindow.xaml").read_text(encoding="utf-8")
        self.assertIn("admin_post_nopriv_bluevpn_windows_tapsell", ads)
        self.assertIn("serve_windows_tapsell", ads)
        site = (ROOT / "bluevpn-site/functions.php").read_text(encoding="utf-8")
        self.assertIn("mediaad-", site)
        self.assertIn("bluevpnLoaderState", site)
        self.assertIn("s1.mediaad.org/serve/blluepanel.ir/loader.js", site)
        self.assertIn("'bridge_url' => add_query_arg", ads)
        self.assertIn("normalize_windows_web_placement_id", ads)
        self.assertIn("'slot' => $placementId", ads)
        self.assertIn("'type' => $type", ads)
        self.assertIn("'placements' => $windowsWebPlacements", ads)
        self.assertIn("$target = add_query_arg(['bluevpn_tapsell_windows'=>'1','slot'=>$slot]", ads)
        self.assertIn("wp_redirect($target, 302, 'BlueVPN')", ads)
        self.assertIn("preg_match('/mediaad-[A-Za-z0-9_-]{2,120}/'", site)
        self.assertIn("wp_strip_all_tags($slotRaw)", site)
        self.assertIn('JsonPropertyName("bridge_url")', model)
        self.assertIn("NavigateTapsellAsync", code)
        self.assertIn("NavigationCompleted", code)
        self.assertIn('x:Name="AdProviderLabel" Visibility="Collapsed"', xaml)
        self.assertIn('x:Name="TapsellLoadingPanel" Visibility="Collapsed"', xaml)


if __name__ == "__main__":
    unittest.main()
