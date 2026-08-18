import json
import pathlib
import re
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]

def text(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")

class WindowsClient4157Tests(unittest.TestCase):
    def test_release_versions_are_unified(self):
        release = json.loads(text("release.json"))
        branding = json.loads(text("branding/app.json"))
        self.assertEqual(release["version"], "4.15.7")
        self.assertEqual(release["windows_version"], "4.15.7")
        self.assertEqual(release["windows_version_code"], 41507)
        self.assertEqual(branding["version_name"], "4.15.7")
        self.assertIn("<Version>4.15.7</Version>", text("bluevpn-windows/BlueVPN.Windows.csproj"))

    def test_windows_client_uses_existing_bluevpn_control_plane(self):
        api = text("bluevpn-windows/Services/BlueVpnApiClient.cs")
        self.assertIn("auth/login", api)
        self.assertIn("auth/otp/request", api)
        self.assertIn("auth/otp/verify", api)
        self.assertIn("wp-json/bluevpn/v1/account", api)
        self.assertIn("wp-json/bluevpn/v1/plans", api)
        self.assertIn("GetPremiumSubscriptionAsync", api)
        self.assertIn("GetFreeSubscriptionAsync", api)

    def test_windows_tun_and_runtime_are_explicit(self):
        xray = text("bluevpn-windows/Services/XrayConfigBuilder.cs")
        manifest = text("bluevpn-windows/app.manifest")
        workflow = text(".github/workflows/build-windows.yml")
        self.assertIn('["protocol"] = "tun"', xray)
        self.assertIn("autoSystemRoutingTable", xray)
        self.assertIn("autoOutboundsInterface", xray)
        self.assertIn('level="requireAdministrator"', manifest)
        self.assertIn("Xray-windows-64.zip", workflow)
        self.assertIn("Xray-windows-arm64-v8a.zip", workflow)
        self.assertIn("v26.7.28", workflow)

    def test_windows_compile_gate_and_telegram_delivery_are_enforced(self):
        api = text("bluevpn-windows/Services/BlueVpnApiClient.cs")
        probe = text("bluevpn-windows/Services/ConnectivityProbe.cs")
        workflow = text(".github/workflows/build-windows.yml")
        telegram = text("scripts/send_windows_telegram.ps1")
        self.assertIn("using System.Net.Http;", api)
        self.assertIn("using System.Net.Http;", probe)
        self.assertIn("dotnet build bluevpn-windows/BlueVPN.Windows.csproj", workflow)
        self.assertIn("dotnet publish bluevpn-windows/BlueVPN.Windows.csproj", workflow)
        self.assertIn("TELEGRAM_BOT_TOKEN", workflow)
        self.assertIn("TELEGRAM_CHAT_ID", workflow)
        self.assertIn("send_windows_telegram.ps1", workflow)
        self.assertIn("sendDocument", telegram)
        self.assertIn("TelegramDirectLimitBytes", telegram)
        self.assertIn("SplitPartSizeBytes", telegram)
        self.assertIn("JOIN-BLUEVPN-PARTS.cmd", telegram)

    def test_windows_system_io_imports_are_explicit(self):
        for path in (
            "bluevpn-windows/Services/XrayProcessController.cs",
            "bluevpn-windows/Services/AppSettings.cs",
            "bluevpn-windows/Services/DeviceIdentity.cs",
        ):
            self.assertIn("using System.IO;", text(path), path)

    def test_windows_connection_is_verified_before_connected(self):
        c = text("bluevpn-windows/Services/ConnectionOrchestrator.cs")
        self.assertIn("EndpointSelector.RankAsync", c)
        self.assertIn("ConnectivityProbe.VerifyAsync", c)
        self.assertIn("GetPremiumSubscriptionAsync", c)
        self.assertIn("GetFreeSubscriptionAsync", c)

    def test_supported_subscription_protocols(self):
        parser = text("bluevpn-windows/Services/SubscriptionParser.cs")
        for scheme in ("vless://", "vmess://", "trojan://", "ss://"):
            self.assertIn(scheme, parser)

if __name__ == "__main__":
    unittest.main()
