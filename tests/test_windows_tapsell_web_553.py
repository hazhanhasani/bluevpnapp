import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]

class WindowsTapsellWeb553Tests(unittest.TestCase):
    def test_windows_uses_real_webview2_surface(self):
        project = (ROOT / "bluevpn-windows/BlueVPN.Windows.csproj").read_text(encoding="utf-8")
        xaml = (ROOT / "bluevpn-windows/MainWindow.xaml").read_text(encoding="utf-8")
        code = (ROOT / "bluevpn-windows/MainWindow.xaml.cs").read_text(encoding="utf-8")
        self.assertIn('Microsoft.Web.WebView2', project)
        self.assertIn('x:Name="TapsellWebView"', xaml)
        self.assertIn('EnsureCoreWebView2Async', code)
        self.assertIn('TryReserveWindowsWebImpression', code)
        self.assertIn('|| _ads.WindowsWeb.Enabled', code)

    def test_panel_exposes_independent_windows_schedule(self):
        ads = (ROOT / "bluevpn-manager/includes/class-bluevpn-ads.php").read_text(encoding="utf-8")
        db = (ROOT / "bluevpn-manager/includes/class-bluevpn-db.php").read_text(encoding="utf-8")
        for key in ("tapsell_windows_web_enabled", "tapsell_windows_web_script_html", "tapsell_windows_web_min_interval_seconds", "tapsell_windows_web_daily_cap", "tapsell_windows_web_every_slides"):
            self.assertIn(key, ads)
            self.assertIn(key, db)
        self.assertIn("'windows_web' => [", ads)

    def test_premium_gate_and_fail_open_exist(self):
        service = (ROOT / "bluevpn-windows/Services/AdvertisementService.cs").read_text(encoding="utf-8")
        main = (ROOT / "bluevpn-windows/MainWindow.xaml.cs").read_text(encoding="utf-8")
        self.assertIn("cfg.FreeOnly && premium", service)
        self.assertIn("tapsell-windows-state.json", service)
        self.assertIn("SaveWindowsWebState", service)
        self.assertIn("catch", main[main.index("ShowTapsellWebAdAsync"):])
        self.assertIn("return false", main[main.index("ShowTapsellWebAdAsync"):])

    def test_windows_bridge_failover_and_success_only_accounting(self):
        service = (ROOT / "bluevpn-windows/Services/AdvertisementService.cs").read_text(encoding="utf-8")
        reserve = service[service.index("public bool TryReserveWindowsWebImpression"):service.index("public void MarkWindowsWebImpressionShown")]
        self.assertNotIn("_windowsWebDailyCount++", reserve)
        self.assertNotIn("SaveWindowsWebState()", reserve)
        self.assertIn("WindowsWebBridgeCandidates", service)
        self.assertIn("_settings.ControlPlaneBases()", service)
        self.assertIn("WindowsWebStateSchema = 2", service)
        main = (ROOT / "bluevpn-windows/MainWindow.xaml.cs").read_text(encoding="utf-8")
        self.assertIn("_ads.MarkWindowsWebImpressionShown();", main)
        self.assertIn("foreach (var address in _ads.WindowsWebBridgeCandidates())", main)
        detector = main[main.index("private async Task<bool> WaitForTapsellContentAsync"):main.index("private static string ShortUiError")]
        self.assertIn("backgroundImage", detector)
        self.assertIn("shadowRoot", detector)

if __name__ == "__main__":
    unittest.main()
