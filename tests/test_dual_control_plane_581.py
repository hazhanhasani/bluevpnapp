import json
import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]
DOMAINS = ["https://blluepanel.ir", "https://bot.blluepanel.ir"]


class DualControlPlane581Tests(unittest.TestCase):
    def test_shared_contract_contains_both_https_domains(self):
        branding = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
        windows = json.loads((ROOT / "bluevpn-windows/appsettings.json").read_text(encoding="utf-8"))
        self.assertEqual(branding["api_base_urls"], DOMAINS)
        self.assertEqual(windows["api_base_urls"], DOMAINS)

    def test_android_generates_and_uses_control_plane_failover(self):
        prepare = (ROOT / "scripts/prepare_android.py").read_text(encoding="utf-8")
        account = (ROOT / "android-source/BlueVpnAccountManager.kt").read_text(encoding="utf-8")
        self.assertIn('"BLUEVPN_API_BASE_URLS"', prepare)
        self.assertIn("BuildConfig.BLUEVPN_API_BASE_URLS", account)
        self.assertIn("requestAgainstBase", account)
        self.assertIn("val bases = apiBaseUrls()", account)
        self.assertIn("X-BlueVPN-Request-ID", account)
        self.assertIn("UUID.randomUUID().toString()", account)

    def test_manager_deduplicates_mutating_failover_requests(self):
        api = (ROOT / "bluevpn-manager/includes/class-bluevpn-api.php").read_text(encoding="utf-8")
        self.assertIn("idempotency_pre_dispatch", api)
        self.assertIn("x-bluevpn-request-id", api.lower())
        self.assertIn("IDEMPOTENCY_CONFLICT", api)
        self.assertIn("X-BlueVPN-Idempotent-Replay", api)
        self.assertIn("10 * MINUTE_IN_SECONDS", api)

    def test_windows_and_ios_use_both_domains(self):
        settings = (ROOT / "bluevpn-windows/Services/AppSettings.cs").read_text(encoding="utf-8")
        client = (ROOT / "bluevpn-windows/Services/BlueVpnApiClient.cs").read_text(encoding="utf-8")
        ios = (ROOT / "bluevpn-ios/BlueVPNApp/APIClient.swift").read_text(encoding="utf-8")
        self.assertIn("ControlPlaneBases", settings)
        self.assertIn("foreach (var client in allowTransportFallback", client)
        for domain in DOMAINS:
            self.assertIn(domain, ios)

    def test_android_settings_exposes_privacy_safe_dual_domain_diagnostics(self):
        settings = (ROOT / "android-source/BlueVpnSettingsActivity.kt").read_text(encoding="utf-8")
        updater = (ROOT / "android-source/BlueVpnUpdateManager.kt").read_text(encoding="utf-8")
        self.assertIn("عیب‌یابی BlueVPN", settings)
        self.assertIn("BlueVpnAccountManager.apiBaseUrls()", settings)
        self.assertIn("trimEnd('/') + \"/health\"", settings)
        self.assertIn("BlueVpnRuntimeGate.connectionPhase", settings)
        self.assertIn("No token, email, subscription URL or secret is included.", settings)
        self.assertIn("data class UpdateStatus", updater)
        self.assertIn("fun status(context: Context): UpdateStatus", updater)
        self.assertIn("KEY_UPDATE_CODE", updater)

    def test_android_connection_policy_is_remote_but_safely_bounded(self):
        api = (ROOT / "bluevpn-manager/includes/class-bluevpn-api.php").read_text(encoding="utf-8")
        account = (ROOT / "android-source/BlueVpnAccountManager.kt").read_text(encoding="utf-8")
        recovery = (ROOT / "android-source/BlueVpnNetworkRecoveryManager.kt").read_text(encoding="utf-8")
        runtime = (ROOT / "android-source/BlueVpnRuntimeGate.kt").read_text(encoding="utf-8")
        settings = (ROOT / "android-source/BlueVpnSettingsActivity.kt").read_text(encoding="utf-8")
        self.assertIn("'connection_policy'=>[", api)
        self.assertIn("android_recovery_window_seconds", api)
        self.assertIn("android_connection_gate_wait_ms", api)
        self.assertIn("BlueVpnNetworkRecoveryManager.applyRemotePolicy", account)
        self.assertIn("coerceIn(15L, 180L)", recovery)
        self.assertIn("coerceIn(500L, 8_000L)", recovery)
        self.assertIn("BlueVpnNetworkRecoveryManager.connectionGateWaitMs(context)", runtime)
        self.assertIn("Recovery window:", settings)
        self.assertIn("Connection gate wait:", settings)

    def test_health_monitor_probes_both_domains(self):
        workflow = (ROOT / ".github/workflows/external-health.yml").read_text(encoding="utf-8")
        for domain in DOMAINS:
            self.assertIn(domain, workflow)


if __name__ == "__main__":
    unittest.main()
