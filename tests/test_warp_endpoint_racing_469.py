from pathlib import Path
import json
import unittest

ROOT = Path(__file__).resolve().parents[1]
WARP = (ROOT / 'android-source/BlueVpnWarpEngine.kt').read_text()
ACCOUNT = (ROOT / 'android-source/BlueVpnAccountManager.kt').read_text()
ADS = (ROOT / 'bluevpn-manager/includes/class-bluevpn-ads.php').read_text()
DB = (ROOT / 'bluevpn-manager/includes/class-bluevpn-db.php').read_text()
BUILD = (ROOT / 'scripts/build_aether_android.py').read_text()
REL = json.loads((ROOT / 'release.json').read_text())

class WarpEndpointRacing469(unittest.TestCase):
    def test_release(self):
        self.assertEqual(REL['version'], '4.6.9')
        self.assertEqual(REL['version_code'], 40609)

    def test_cloudflare_documented_matrix_present(self):
        for prefix in ['162.159.192', '162.159.193', '162.159.197']:
            self.assertIn(prefix, WARP)
        for port in ['443', '500', '1701', '2408', '4443', '4500', '8095', '8443']:
            self.assertIn(port, WARP)

    def test_direct_peer_fast_path_and_native_fallback(self):
        self.assertIn('RACING_ENDPOINTS', WARP)
        self.assertIn('listOf("--peer", peer)', WARP)
        self.assertIn('edgeCandidates(', WARP)
        self.assertIn('startProcess(app, strategy, port, quick, policy, peer = null)', WARP)
        self.assertIn('--peer', BUILD)

    def test_network_scoped_cache_and_cooldown(self):
        self.assertIn('edge:$signature:${strategy.name}', WARP)
        self.assertIn('edge_backoff:', WARP)
        self.assertIn('edge_cursor:', WARP)
        self.assertIn('edge_ms:', WARP)

    def test_policy_defaults_are_fast_but_bounded(self):
        self.assertIn('warpEndpointRacingEnabled', ACCOUNT)
        self.assertIn('warpEndpointRaceBreadth', ACCOUNT)
        self.assertIn('warpEndpointProbeSeconds', ACCOUNT)
        self.assertIn('optString("scan_mode", "turbo")', ACCOUNT)
        self.assertIn('optBoolean("wireguard_enabled", true)', ACCOUNT)
        self.assertIn("'free_warp_scan_mode' => 'turbo'", DB)
        self.assertIn("'free_warp_wireguard_enabled' => true", DB)

    def test_wordpress_controls_exposed(self):
        for key in ['endpoint_racing_enabled', 'endpoint_race_breadth', 'endpoint_probe_seconds']:
            self.assertIn(key, ADS)
        self.assertIn('Cloudflare Endpoint Racing', ADS)

    def test_exit_guard_is_still_mandatory_by_default(self):
        self.assertIn('warpRequireExitTrace', ACCOUNT)
        self.assertIn('warpBlockedExitCountries', ACCOUNT)
        self.assertIn('WARP_EXIT_COUNTRY_BLOCKED', WARP)
        self.assertNotIn('--no-data-check', WARP)

if __name__ == '__main__':
    unittest.main()
