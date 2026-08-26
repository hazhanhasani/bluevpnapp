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
        self.assertIn("SetVirtualHostNameToFolderMapping", main)
        self.assertIn("WriteAdDocumentAsync", main)
        self.assertIn("WaitForTapsellContentAsync", main)
        self.assertIn("ExecuteScriptAsync", main)
        self.assertNotIn("NavigateToString(html)", main)
        self.assertIn('VirtualHost = "ads.bluevpn.local"', installer)
        self.assertIn("Directory.CreateDirectory(ContentFolder)", installer)


if __name__ == "__main__":
    unittest.main()
