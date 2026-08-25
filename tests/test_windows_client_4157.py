import json
import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
def text(path: str) -> str: return (ROOT / path).read_text(encoding="utf-8")

class WindowsClient4162Tests(unittest.TestCase):
    def test_release_versions_are_unified(self):
        release = json.loads(text("release.json")); branding = json.loads(text("branding/app.json"))
        self.assertEqual(release["version"], "5.8.7")
        self.assertEqual(release["windows_version"], "5.8.7")
        self.assertEqual(release["windows_version_code"], 50807)
        self.assertEqual(branding["version_name"], "5.8.7")
        self.assertIn("<Version>5.8.7</Version>", text("bluevpn-windows/BlueVPN.Windows.csproj"))

    def test_existing_bluevpn_control_plane_is_preserved(self):
        api = text("bluevpn-windows/Services/BlueVpnApiClient.cs")
        for token in ("auth/login", "auth/otp/request", "auth/otp/verify", "wp-json/bluevpn/v1/account", "wp-json/bluevpn/v1/plans", "GetMobileConfigAsync"):
            self.assertIn(token, api)

    def test_v2rayn_runtime_is_the_windows_baseline(self):
        workflow = text(".github/workflows/build-windows.yml")
        settings = json.loads(text("bluevpn-windows/appsettings.json"))
        self.assertEqual(settings["v2rayn_version"], "7.24.4")
        self.assertIn("v2rayN-windows-64.zip", workflow)
        self.assertIn("v2rayN-windows-arm64.zip", workflow)
        self.assertIn("2dust/v2rayN", workflow)
        self.assertIn("Get-PeMachine", workflow)
        self.assertIn("third_party/V2RAYN.md", workflow)

    def test_connected_state_requires_system_verification(self):
        c = text("bluevpn-windows/Services/ConnectionOrchestrator.cs")
        v = text("bluevpn-windows/Services/SystemTunnelVerifier.cs")
        self.assertIn("public bool IsConnected => _verifiedConnected", c)
        self.assertIn("SystemTunnelVerifier.VerifyAsync", c)
        self.assertIn("IP سیستم تغییر نکرد", v)
        self.assertIn("Get-NetRoute", v)

    def test_warp_x64_and_arm64_fallback_are_explicit(self):
        workflow = text(".github/workflows/build-windows.yml")
        warp = text("bluevpn-windows/Services/WarpConnectionController.cs")
        config = text("bluevpn-windows/Services/SingBoxWarpConfigBuilder.cs")
        self.assertIn("aether-windows-x86_64.zip", workflow)
        self.assertIn("ARM64-FALLBACK.txt", workflow)
        self.assertIn("--quick-reconnect", warp)
        self.assertIn('process_name = new[] { "aether.exe" }', config)
        self.assertIn("strict_route = true", config)

    def test_real_installer_and_self_update(self):
        workflow = text(".github/workflows/build-windows.yml")
        installer = text("bluevpn-windows/installer/BlueVPN.iss")
        updater = text("bluevpn-windows/Services/AppUpdateService.cs")
        self.assertIn("DefaultDirName={autopf}\\BlueVPN", installer)
        self.assertIn("BlueVPN-Setup-${VERSION}-win-x64.exe", workflow)
        self.assertIn("BlueVPN-Setup-${VERSION}-win-arm64.exe", workflow)
        self.assertIn("VERYSILENT", updater)

    def test_website_prefers_setup_assets(self):
        helpers = text("bluevpn-site/inc/helpers.php")
        view = text("bluevpn-site/inc/download-view.php")
        self.assertIn("BlueVPN-Setup-", helpers)
        self.assertIn("artifact_kind", helpers)
        self.assertIn("نصب برای Windows", view)
        self.assertIn("نصب Windows ARM", view)

    def test_windows_ui_and_ads_follow_bluevpn_home_model(self):
        xaml = text("bluevpn-windows/MainWindow.xaml")
        cs = text("bluevpn-windows/MainWindow.xaml.cs")
        for token in ("StatusOrb", "EndpointText", "IpValue", "PingValue", "DurationValue", "SpeedValue", "AdCard"):
            self.assertIn(token, xaml)
        self.assertIn("ShowFreeStoryAdSafe", cs)
        self.assertIn("window.Show()", cs)

if __name__ == "__main__": unittest.main()
