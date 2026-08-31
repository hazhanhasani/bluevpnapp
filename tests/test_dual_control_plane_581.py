import json
import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]
DOMAINS = ["https://blluepanel.ir", "https://bot.blluepanel.ir"]
ANDROID_DOMAINS = ["https://bot.blluepanel.ir"]


class DualControlPlane581Tests(unittest.TestCase):
    def test_android_bot_only_and_windows_dual_domains(self):
        branding = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
        windows = json.loads((ROOT / "bluevpn-windows/appsettings.json").read_text(encoding="utf-8"))
        self.assertEqual(branding["api_base_url"], "https://bot.blluepanel.ir")
        self.assertEqual(branding["api_base_urls"], ANDROID_DOMAINS)
        self.assertEqual(windows["api_base_urls"], DOMAINS)

    def test_android_generates_and_uses_control_plane_failover(self):
        prepare = (ROOT / "scripts/prepare_android.py").read_text(encoding="utf-8")
        account = (ROOT / "android-source/BlueVpnAccountManager.kt").read_text(encoding="utf-8")
        self.assertIn('"BLUEVPN_API_BASE_URLS"', prepare)
        self.assertIn("BuildConfig.BLUEVPN_API_BASE_URLS", account)
        self.assertIn("requestAgainstBase", account)

    def test_windows_and_ios_use_both_domains(self):
        settings = (ROOT / "bluevpn-windows/Services/AppSettings.cs").read_text(encoding="utf-8")
        client = (ROOT / "bluevpn-windows/Services/BlueVpnApiClient.cs").read_text(encoding="utf-8")
        ios = (ROOT / "bluevpn-ios/BlueVPNApp/APIClient.swift").read_text(encoding="utf-8")
        self.assertIn("ControlPlaneBases", settings)
        self.assertIn("foreach (var client in allowTransportFallback", client)
        for domain in DOMAINS:
            self.assertIn(domain, ios)

    def test_health_monitor_probes_both_domains(self):
        workflow = (ROOT / ".github/workflows/external-health.yml").read_text(encoding="utf-8")
        for domain in DOMAINS:
            self.assertIn(domain, workflow)


if __name__ == "__main__":
    unittest.main()
