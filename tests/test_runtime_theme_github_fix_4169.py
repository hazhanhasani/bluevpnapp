from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]

def text(rel):
    return (ROOT / rel).read_text(encoding="utf-8")

class RuntimeThemeGithubFix4169(unittest.TestCase):
    def test_runtime_locator_does_not_shadow_architecture_enum(self):
        s = text("bluevpn-windows/Services/RuntimeLocator.cs")
        self.assertIn("System.Runtime.InteropServices.Architecture.Arm64", s)
        self.assertNotIn("== Architecture.Arm64", s)
        for source in (ROOT / "bluevpn-windows/Services").glob("*.cs"):
            body = source.read_text(encoding="utf-8")
            self.assertNotIn(" Architecture.Arm64", body, source.name)

    def test_elementor_empty_fallback_is_not_reported_as_runtime_error(self):
        s = text("bluevpn-site/inc/class-bluevpn-elementor.php")
        self.assertIn("bluevpn_site_diagnostic_log('BlueVPN Elementor '.$location.' fallback", s)
        self.assertIn("repair_managed_location_template", s)
        self.assertIn("bluevpn_site_error_log('BlueVPN Elementor render failed:", s)

    def test_cancel_endpoint_is_preflighted_and_expected_statuses_are_classified(self):
        s = text("bluevpn-manager/includes/class-bluevpn-telegram-bot.php")
        self.assertIn("$run = self::gh('GET', $runPath, null, $s);", s)
        self.assertIn("expect_http_status_once($cancelUrl, [403, 404, 409])", s)
        self.assertIn("GITHUB_CANCEL_NOT_PERMITTED", s)

    def test_windows_workflow_uses_node24_ready_artifact_actions(self):
        s = text(".github/workflows/build-windows.yml")
        self.assertNotIn("actions/upload-artifact@v4", s)
        self.assertNotIn("actions/download-artifact@v4", s)
        self.assertIn("actions/upload-artifact@v7", s)
        self.assertIn("actions/download-artifact@v8", s)

if __name__ == '__main__':
    unittest.main()
