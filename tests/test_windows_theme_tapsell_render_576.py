import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]


class WindowsThemeTapsellRender576Tests(unittest.TestCase):
    def test_global_controls_follow_theme_without_white_native_tooltips(self):
        app = (ROOT / "bluevpn-windows/App.xaml").read_text(encoding="utf-8")
        main = (ROOT / "bluevpn-windows/MainWindow.xaml").read_text(encoding="utf-8")
        story = (ROOT / "bluevpn-windows/StoryAdWindow.xaml").read_text(encoding="utf-8")
        theme = (ROOT / "bluevpn-windows/Services/WindowsThemeService.cs").read_text(encoding="utf-8")
        self.assertIn('<Style TargetType="ToolTip">', app)
        self.assertIn('TextElement.Foreground="{TemplateBinding Foreground}"', app)
        self.assertIn('x:Key="BlueVpnOverlay"', app)
        self.assertIn('Background="{DynamicResource BlueVpnOverlay}"', main)
        self.assertIn('Background="{DynamicResource BlueVpnSurface}"', story)
        self.assertIn('Set("BlueVpnOverlay"', theme)

    def test_tapsell_uses_real_https_origin_and_waits_for_rendered_media(self):
        main = (ROOT / "bluevpn-windows/MainWindow.xaml.cs").read_text(encoding="utf-8")
        installer = (ROOT / "bluevpn-windows/Services/WebView2RuntimeInstaller.cs").read_text(encoding="utf-8")
        self.assertNotIn("SetVirtualHostNameToFolderMapping", main)
        self.assertNotIn("WriteAdDocumentAsync(html", main)
        self.assertIn("TryGetApprovedWindowsBridge", main)
        self.assertIn("WaitForTapsellContentAsync", main)
        self.assertIn("ExecuteScriptAsync", main)
        self.assertIn("BLUEVPN_TAPSELL_READY", main)
        self.assertIn("IsWebMessageEnabled = true", main)
        self.assertIn("new Microsoft.Web.WebView2.Wpf.WebView2", main)
        self.assertNotIn("WebView2CompositionControl", main)
        self.assertIn("webView.DefaultBackgroundColor = System.Drawing.Color.Transparent", main)
        self.assertIn("SetTapsellWebVisibility(Visibility.Visible)", main)
        self.assertIn("_tapsellInitGate", main)
        self.assertIn("ScheduleTapsellWarmup()", main)
        self.assertIn("DispatcherPriority.ApplicationIdle", main)
        self.assertIn("EnsureTapsellWebInitializedAsync", main)
        self.assertIn("if (attempt % 4 != 3) continue;", main)
        self.assertNotIn("NavigateToString(html)", main)
        self.assertIn('"BlueVPN", "WebView2", "Tapsell"', installer)
        self.assertIn("CreatePerUserEnvironmentAsync", installer)
        site = (ROOT / "bluevpn-site/functions.php").read_text(encoding="utf-8")
        self.assertIn('script.src="https://s1.mediaad.org/serve/blluepanel.ir/loader.js"', site)
        self.assertIn('postMessage("BLUEVPN_TAPSELL_"+state)', site)


if __name__ == "__main__":
    unittest.main()
