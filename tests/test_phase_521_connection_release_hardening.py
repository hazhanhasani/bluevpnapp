import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def text(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")

class Phase521ConnectionReleaseHardeningTests(unittest.TestCase):
    def test_release_contract(self):
        r=json.loads(text("release.json"))
        self.assertEqual(r["version"],"5.6.9")
        self.assertEqual(r["version_code"],50609)
        self.assertEqual(r["android_version"],"5.6.9")
        self.assertEqual(r["windows_version"],"5.6.9")
        features=set(r.get("features",[]))
        for f in {
            "subscription-last-known-good-stale-if-error",
            "android-handover-two-success-tunnel-confirmation",
            "windows-two-phase-tunnel-verification",
            "windows-adaptive-third-jitter-probe",
            "bot-cross-platform-release-health",
            "theme-api-timeout-bounded-get-retry",
            "sentinel-respects-suppressed-php-errors",
            "sentinel-user-validation-noise-filter",
            "bot-diagnose-failed-github-step",
            "build-failure-actionable-log-extract",
            "build-failure-diagnostics-artifact",
        }:
            self.assertIn(f,features)

    def test_source_stale_if_error_is_bounded(self):
        s=text("bluevpn-manager/includes/class-bluevpn-subscription-sources.php")
        self.assertIn("CACHE_TTL_SECONDS = 300",s)
        self.assertIn("STALE_IF_ERROR_SECONDS = 1800",s)
        self.assertIn("cache_success",s)
        self.assertIn("stale_fallback",s)
        self.assertIn("get_transient",s)
        self.assertIn("set_transient",s)
        self.assertIn("[408,425,429,500,502,503,504]",s)
        self.assertNotIn("payload_enc'=>", s[s.index("private static function cache_key"):s.index("public static function fetch_url_configs")])

    def test_android_handover_confirmation(self):
        s=text("android-source/BlueVpnHomeActivity.kt")
        self.assertIn("val firstSuccess = race(Proxy.Type.HTTP)",s)
        self.assertIn("BlueVpnNetworkRecoveryManager.recoveryWindowActive",s)
        self.assertIn("Thread.sleep(180L)",s)
        self.assertIn('endpoint = "https://cp.cloudflare.com/generate_204"',s)
        self.assertIn("maxOf(firstSuccess, confirmation)",s)

    def test_windows_double_verify_and_adaptive_probe(self):
        selector=text("bluevpn-windows/Services/EndpointSelector.cs")
        conn=text("bluevpn-windows/Services/ConnectionOrchestrator.cs")
        self.assertIn("jitter >= 90",selector)
        self.assertIn("var third = await ProbeOnceAsync",selector)
        self.assertIn("samples.Max() - samples.Min()",selector)
        self.assertIn("ConfirmStableTunnelAsync",conn)
        self.assertIn("ConfirmStableXrayProxyAsync",conn)
        self.assertIn("Task.Delay(280, ct)",conn)
        self.assertGreaterEqual(conn.count("ConfirmStableTunnelAsync"),2)


    def test_operational_diagnostics_and_noise_hardening(self):
        monitor=text("bluevpn-manager/includes/class-bluevpn-error-monitor.php")
        bot=text("bluevpn-manager/includes/class-bluevpn-telegram-bot.php")
        workflow=text(".github/workflows/build-apk.yml")
        self.assertIn("(error_reporting() & $severity) === 0",monitor)
        self.assertIn("expected_rest_client_outcome",monitor)
        self.assertIn("'email_invalid'",monitor)
        self.assertIn("'weak_password'",monitor)
        self.assertIn("github_run_failure_summary",bot)
        self.assertIn("/actions/runs/' . $runId . '/jobs?per_page=100",bot)
        self.assertIn("Failed step:",bot)
        self.assertIn("set -euo pipefail",workflow)
        self.assertIn("=== Extracted failure lines ===",workflow)
        self.assertIn("Execution failed for task",workflow)
        self.assertIn("android-build.log",workflow)
        self.assertIn("BlueVPN-build-failure-${{ github.run_id }}",workflow)
        self.assertIn("telegram-build-error.txt",workflow)

    def test_bot_and_theme_upgrade(self):
        bot=text("bluevpn-manager/includes/class-bluevpn-telegram-bot.php")
        js=text("bluevpn-site/assets/js/site.js")
        css=text("bluevpn-site/assets/css/site.css")
        self.assertIn("/releasecheck",bot)
        self.assertIn("🧭 سلامت نسخه",bot)
        self.assertIn("send_release_health",bot)
        self.assertIn("Android Stable/Beta",bot)
        self.assertIn("Windows Stable/Beta",bot)
        self.assertIn("AbortController",js)
        self.assertIn("REQUEST_TIMEOUT",js)
        self.assertIn("[502,503,504]",js)
        self.assertIn("bv-offline",css)

if __name__ == "__main__":
    unittest.main()
