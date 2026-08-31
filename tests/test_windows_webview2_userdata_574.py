from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[1]


class WindowsWebView2UserData574Tests(unittest.TestCase):
    def test_webview_uses_writable_per_user_data_directory(self):
        installer = (ROOT / "bluevpn-windows/Services/WebView2RuntimeInstaller.cs").read_text(encoding="utf-8")
        main = (ROOT / "bluevpn-windows/MainWindow.xaml.cs").read_text(encoding="utf-8")
        self.assertIn("Environment.SpecialFolder.LocalApplicationData", installer)
        self.assertIn('"BlueVPN", "WebView2", "Tapsell"', installer)
        self.assertIn("CreatePerUserEnvironmentAsync", installer)
        self.assertIn("EnsureCoreWebView2Async(_tapsellWebEnvironment)", main)
        self.assertNotIn("EnsureCoreWebView2Async();", main)
        self.assertIn("ApprovedWindowsPublisherHost", main)
        settings = (ROOT / "bluevpn-windows/appsettings.json").read_text(encoding="utf-8")
        self.assertIn('"https://blluepanel.ir"', settings)
        self.assertIn('"https://bot.blluepanel.ir"', settings)

    def test_web_ad_failure_falls_back_without_crashing_home(self):
        main = (ROOT / "bluevpn-windows/MainWindow.xaml.cs").read_text(encoding="utf-8")
        block = main[main.index("ShowTapsellWebAdAsync"):main.index("AdCard_SizeChanged")]
        self.assertIn("catch (Exception ex)", block)
        self.assertIn("SetTapsellWebVisibility(Visibility.Collapsed)", block)
        self.assertIn("return false", block)


if __name__ == "__main__":
    unittest.main()
