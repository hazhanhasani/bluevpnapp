import json
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

class WarpExitGuard469(unittest.TestCase):
    def test_release_version(self):
        app = json.loads((ROOT/'branding/app.json').read_text())
        rel = json.loads((ROOT/'release.json').read_text())
        self.assertEqual(app['version_name'], '4.6.9')
        self.assertEqual(app['version_code'], 40609)
        self.assertEqual(rel['version'], '4.6.9')

    def test_android_policy_defaults_to_ir_block(self):
        text = (ROOT/'android-source/BlueVpnAccountManager.kt').read_text()
        self.assertIn('warpRequireExitTrace', text)
        self.assertIn('warpBlockedExitCountries', text)
        self.assertIn('setOf("IR")', text)
        self.assertIn('blocked_exit_countries', text)

    def test_aether_rejects_blocked_exit(self):
        text = (ROOT/'android-source/BlueVpnWarpEngine.kt').read_text()
        self.assertIn('WARP_EXIT_COUNTRY_BLOCKED', text)
        self.assertIn('WARP_EXIT_TRACE_UNAVAILABLE', text)
        self.assertRegex(text, r'traceCountry\s+in\s+policy\.warpBlockedExitCountries')
        self.assertIn('https://www.cloudflare.com/cdn-cgi/trace', text)

    def test_post_xray_verification_rejects_blocked_exit(self):
        text = (ROOT/'android-source/BlueVpnHomeActivity.kt').read_text()
        self.assertIn('fetchExitTraceThroughLocalXray', text)
        self.assertIn('https://1.1.1.1/cdn-cgi/trace', text)
        self.assertRegex(text, r'country\s+in\s+policy\.warpBlockedExitCountries')
        self.assertIn('return@withContext null', text)

    def test_wordpress_exposes_exit_policy(self):
        ads = (ROOT/'bluevpn-manager/includes/class-bluevpn-ads.php').read_text()
        db = (ROOT/'bluevpn-manager/includes/class-bluevpn-db.php').read_text()
        self.assertIn("'require_exit_trace'", ads)
        self.assertIn("'blocked_exit_countries'", ads)
        self.assertIn("free_warp_blocked_exit_countries", ads)
        self.assertIn("'free_warp_blocked_exit_countries' => ['IR']", db)

    def test_fallback_path_exists(self):
        home = (ROOT/'android-source/BlueVpnHomeActivity.kt').read_text()
        self.assertIn('failedWasWarpBridge && BlueVpnAccountManager.warpFallbackEnabled(this)', home)
        self.assertIn('beginSmartConnection()', home)

if __name__ == '__main__':
    unittest.main()
