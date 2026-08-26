import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def text(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class Phase520SubscriptionClientStabilityTests(unittest.TestCase):
    def test_release_contract(self):
        release = json.loads(text("release.json"))
        self.assertEqual(release["version"], "5.10.5")
        self.assertEqual(release["version_code"], 51005)
        self.assertEqual(release["android_version"], "5.10.5")
        self.assertEqual(release["windows_version"], "5.10.5")
        features = set(release.get("features", []))
        for feature in {
            "subscription-custom-port-safe-validation",
            "subscription-urlsafe-base64-parser",
            "android-network-handover-sticky-recovery",
            "android-adaptive-real-tunnel-probe-targets",
            "windows-multisample-jitter-endpoint-ranking",
            "windows-recent-success-sticky-reconnect",
            "bot-runtime-diagnostics-command",
            "theme-reduced-motion-mobile-form-stability",
        }:
            self.assertIn(feature, features)

    def test_subscription_custom_ports_keep_ssrf_guards(self):
        source = text("bluevpn-manager/includes/class-bluevpn-subscription-sources.php")
        providers = text("bluevpn-manager/includes/class-bluevpn-providers.php")
        self.assertIn("fetch_url_configs", source)
        self.assertIn("http_allowed_safe_ports", source)
        self.assertIn("wp_safe_remote_get", source)
        self.assertIn("FILTER_FLAG_NO_PRIV_RANGE", source)
        self.assertIn("FILTER_FLAG_NO_RES_RANGE", source)
        self.assertIn("gethostbynamel", source)
        self.assertIn("strtr($compact,'-_','+/')", source)
        self.assertIn("hysteria|hy2|tuic", source)
        self.assertIn("Redirect loop", source)
        self.assertIn("transport_error_label", source)
        self.assertNotIn("$r->get_error_message()", source[source.index("public static function fetch_url_configs"):source.index("private static function validate_payload")])
        self.assertIn("BlueVPN_Subscription_Sources::fetch_url_configs", providers)
        self.assertIn("تست خودکار", source)

    def test_android_real_tunnel_and_handover_recovery(self):
        recovery = text("android-source/BlueVpnNetworkRecoveryManager.kt")
        selector = text("android-source/BlueVpnSmartSelector.kt")
        home = text("android-source/BlueVpnHomeActivity.kt")
        gate = text("android-source/BlueVpnRuntimeGate.kt")
        self.assertIn("KEY_RECOVERY_UNTIL", recovery)
        self.assertIn("recoveryWindowActive", recovery)
        self.assertIn("RECOVERY_WINDOW_MS = 60_000L", recovery)
        self.assertIn("scoreTolerance = if (recovery) 18 else 7", selector)
        self.assertIn("latencyToleranceMs = if (recovery) 180L else 60L", selector)
        self.assertIn("BlueVpnIrcfIntelligence.adaptiveProbeUrls", home)
        self.assertIn("requestThroughLocalXrayProxy", home)
        self.assertIn("Proxy.Type.HTTP", home)
        self.assertIn("KEY_CONNECTION_OWNER_PID", gate)
        self.assertIn("stale_owner_pid", gate)

    def test_windows_quality_ranking_and_sticky_success(self):
        endpoint = text("bluevpn-windows/Models/ProxyEndpoint.cs")
        selector = text("bluevpn-windows/Services/EndpointSelector.cs")
        ai = text("bluevpn-windows/Services/WindowsBlueAiService.cs")
        connection = text("bluevpn-windows/Services/ConnectionOrchestrator.cs")
        api = text("bluevpn-windows/Services/BlueVpnApiClient.cs")
        for token in ("ProbeJitterMs", "ProbeSuccessCount", "ProbeSampleCount"):
            self.assertIn(token, endpoint)
        self.assertIn("ProbeQualityAsync", selector)
        self.assertIn("QualityCost", selector)
        self.assertIn("jitter * 2L", selector)
        self.assertIn("RecentSuccessBonus", ai)
        self.assertIn("jitterPenalty", ai)
        self.assertIn("Take(8)", connection)
        self.assertIn("ValidateSubscriptionUri", api)
        self.assertIn("target.Port is <= 0 or > 65535", api)

    def test_bot_and_theme_receive_same_phase_upgrade(self):
        bot = text("bluevpn-manager/includes/class-bluevpn-telegram-bot.php")
        footer = text("bluevpn-site/footer.php")
        site_js = text("bluevpn-site/assets/js/site.js")
        site_css = text("bluevpn-site/assets/css/site.css")
        self.assertIn("/diagnose", bot)
        self.assertIn("🩺 عیب‌یابی", bot)
        self.assertIn("send_diagnostics", bot)
        self.assertIn("Pending Telegram updates", bot)
        self.assertIn("data-bv-network-status", footer)
        self.assertIn("prefers-reduced-motion", site_js)
        self.assertIn("networkStatus()", site_js)
        self.assertIn(":focus-visible", site_css)
        self.assertIn("min-height:44px", site_css)


if __name__ == "__main__":
    unittest.main()
