import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]

class WindowsTapsellWeb553Tests(unittest.TestCase):
    def test_windows_uses_real_webview2_surface(self):
        project = (ROOT / "bluevpn-windows/BlueVPN.Windows.csproj").read_text(encoding="utf-8")
        xaml = (ROOT / "bluevpn-windows/MainWindow.xaml").read_text(encoding="utf-8")
        code = (ROOT / "bluevpn-windows/MainWindow.xaml.cs").read_text(encoding="utf-8")
        self.assertIn('Microsoft.Web.WebView2', project)
        self.assertIn('x:Name="TapsellWebHost"', xaml)
        self.assertNotIn('x:Name="TapsellWebView"', xaml)
        self.assertIn('Microsoft.Web.WebView2.Wpf.WebView2', code)
        self.assertNotIn('WebView2CompositionControl', code)
        self.assertIn('TryCreateTapsellWebSurface', code)
        self.assertIn('EnsureCoreWebView2Async', code)
        self.assertIn('TryReserveWindowsWebImpression', code)
        self.assertIn('|| _ads.WindowsWeb.Enabled', code)

    def test_panel_separates_android_and_windows_and_exposes_all_web_models(self):
        ads = (ROOT / "bluevpn-manager/includes/class-bluevpn-ads.php").read_text(encoding="utf-8")
        db = (ROOT / "bluevpn-manager/includes/class-bluevpn-db.php").read_text(encoding="utf-8")
        self.assertIn("📱 Tapsell Android — Mediation SDK", ads)
        self.assertIn("🖥️ Tapsell Windows — Web Publisher", ads)
        self.assertIn("tapsell_windows_web_fields", ads)
        for type_name in (
            "rewarded_video",
            "interstitial_video",
            "pre_roll_video",
            "native_video",
            "standard_banner",
            "interstitial_banner",
            "native_banner",
        ):
            key = "tapsell_windows_web_" + type_name + "_placement_id"
            self.assertIn(key, ads)
            self.assertIn(key, db)
        self.assertIn("'schema_version' => 2", ads)
        self.assertIn("'placements' => $windowsWebPlacements", ads)
        self.assertIn("'bridge_url' => add_query_arg", ads)
        self.assertIn("'https://blluepanel.ir/'", ads)
        self.assertIn("'script_html' => ''", ads)
        self.assertNotIn("self::textarea('tapsell_windows_web_script_html'", ads)

    def test_premium_gate_and_fail_open_exist(self):
        service = (ROOT / "bluevpn-windows/Services/AdvertisementService.cs").read_text(encoding="utf-8")
        main = (ROOT / "bluevpn-windows/MainWindow.xaml.cs").read_text(encoding="utf-8")
        self.assertIn("cfg.FreeOnly && premium", service)
        self.assertIn("tapsell-windows-state.json", service)
        self.assertIn("SaveWindowsWebState", service)
        self.assertIn("catch", main[main.index("ShowTapsellWebAdAsync"):])
        self.assertIn("return false", main[main.index("ShowTapsellWebAdAsync"):])

if __name__ == "__main__":
    unittest.main()
