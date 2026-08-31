import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]


class WindowsTapsellGithubResilience587Tests(unittest.TestCase):
    def test_windows_tapsell_accepts_https_bridge_without_script_html(self):
        service = (ROOT / "bluevpn-windows/Services/AdvertisementService.cs").read_text(encoding="utf-8")
        self.assertIn('ApprovedWindowsPublisherHost = "blluepanel.ir"', service)
        self.assertIn("TryGetApprovedWindowsBridge(out Uri bridge)", service)
        self.assertIn("candidate.Scheme.Equals(Uri.UriSchemeHttps", service)
        self.assertIn("candidate.Host.Equals(ApprovedWindowsPublisherHost", service)
        self.assertIn("!cfg.Enabled || !TryGetApprovedWindowsBridge(out _)", service)
        self.assertNotIn("hasRenderableSource", service)
        self.assertNotIn("string.IsNullOrWhiteSpace(cfg.ScriptHtml)", service)

    def test_github_release_poll_has_bounded_retry_and_sentinel_suppression(self):
        resilience = (ROOT / "bluevpn-manager/includes/class-bluevpn-github-http-resilience.php").read_text(encoding="utf-8")
        bootstrap = (ROOT / "bluevpn-manager/bluevpn-manager.php").read_text(encoding="utf-8")
        self.assertIn("pre_http_request", resilience)
        self.assertIn("$timeouts = [8, 15, 24]", resilience)
        self.assertIn("X-BlueVPN-Sentinel-Ignore", resilience)
        self.assertIn("[408, 425, 429, 500, 502, 503, 504]", resilience)
        self.assertIn("/repos/' . self::OWNER . '/' . self::REPO . '/releases", resilience)
        self.assertIn("class-bluevpn-github-http-resilience.php", bootstrap)
        self.assertIn("BlueVPN_GitHub_HTTP_Resilience::init()", bootstrap)


if __name__ == "__main__":
    unittest.main()
