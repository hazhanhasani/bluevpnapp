import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]

class WindowsWebView2AutoInstall557Tests(unittest.TestCase):
    def setUp(self):
        self.installer = (ROOT / "bluevpn-windows/Services/WebView2RuntimeInstaller.cs").read_text(encoding="utf-8")

    def test_detects_and_silently_installs_official_evergreen_runtime(self):
        self.assertIn("GetAvailableBrowserVersionString", self.installer)
        self.assertIn("WebView2RuntimeNotFoundException", self.installer)
        self.assertIn("LinkId=2124703", self.installer)
        self.assertIn('ArgumentList.Add("/silent")', self.installer)
        self.assertIn('ArgumentList.Add("/install")', self.installer)

    def test_download_is_bounded_and_microsoft_only(self):
        self.assertIn("using System.Net.Http;", self.installer)
        self.assertIn("AllowAutoRedirect = false", self.installer)
        self.assertIn("IsMicrosoftDownloadUri", self.installer)
        self.assertIn(".delivery.mp.microsoft.com", self.installer)
        self.assertIn("total > 20_000_000", self.installer)
        self.assertIn("Get-AuthenticodeSignature", self.installer)
        self.assertIn("Microsoft Corporation", self.installer)

    def test_ad_surface_installs_before_initialization_and_remains_fail_open(self):
        main = (ROOT / "bluevpn-windows/MainWindow.xaml.cs").read_text(encoding="utf-8")
        install = main.index("WebView2RuntimeInstaller.EnsureInstalledAsync")
        initialize = main.index("EnsureCoreWebView2Async", install)
        self.assertLess(install, initialize)
        self.assertIn("if (!await WebView2RuntimeInstaller.EnsureInstalledAsync", main)

if __name__ == "__main__":
    unittest.main()
