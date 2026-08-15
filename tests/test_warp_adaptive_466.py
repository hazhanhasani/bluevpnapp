from pathlib import Path
import json
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
WARP = (ROOT / 'android-source/BlueVpnWarpEngine.kt').read_text()
HOME = (ROOT / 'android-source/BlueVpnHomeActivity.kt').read_text()
ACCOUNT = (ROOT / 'android-source/BlueVpnAccountManager.kt').read_text()
ADS = (ROOT / 'bluevpn-manager/includes/class-bluevpn-ads.php').read_text()
DB = (ROOT / 'bluevpn-manager/includes/class-bluevpn-db.php').read_text()
BUILD = (ROOT / 'scripts/build_aether_android.py').read_text()
RELEASE = json.loads((ROOT / 'release.json').read_text())

class WarpAdaptive466Tests(unittest.TestCase):
    def test_version_is_fixed_466(self):
        self.assertEqual(RELEASE['version'], '4.6.6')
        self.assertEqual(RELEASE['version_code'], 40606)
        self.assertNotIn('autobump', BUILD.lower())

    def test_full_state_machine_present(self):
        for name in ['STOPPED','PREPARING','TRYING_CACHED_ROUTE','SCANNING','AETHER_DATA_PLANE_VALIDATING','SOCKS_READY','STARTING_XRAY_BRIDGE','VERIFYING_TUNNEL','CONNECTED','RECONNECTING','SWITCHING_STRATEGY','FALLING_BACK_TO_POOL','STOPPING','FAILED']:
            self.assertIn(name, WARP)

    def test_structured_errors_present(self):
        for name in ['WARP_BINARY_MISSING','WARP_UNSUPPORTED_ABI','WARP_PORT_OCCUPIED','WARP_PROCESS_EXITED','WARP_INTERACTIVE_STALL','WARP_START_TIMEOUT','WARP_NO_ENDPOINT','WARP_SOCKS_HANDSHAKE_FAILED','WARP_DATA_PLANE_FAILED','WARP_BRIDGE_CORE_FAILED','WARP_POST_BRIDGE_VERIFY_FAILED','WARP_NETWORK_CHANGED','WARP_RECONNECT_EXHAUSTED','WARP_FALLBACK_STARTED','WARP_CANCELLED','WARP_UNKNOWN']:
            self.assertIn(name, WARP)

    def test_no_shell_and_noninteractive_flags(self):
        self.assertIn('ProcessBuilder(command)', WARP)
        self.assertNotIn('Runtime.getRuntime().exec', WARP)
        self.assertIn('--quick-reconnect', WARP)
        self.assertIn('--no-quick-reconnect', WARP)
        self.assertIn('outputStream.close()', WARP)
        self.assertIn('--startup-secs', WARP)

    def test_data_check_is_not_disabled(self):
        self.assertNotIn('--no-data-check', WARP)
        self.assertNotIn('AETHER_MASQUE_NO_DATA_CHECK', WARP)

    def test_strategy_order(self):
        order = [WARP.index('Strategy.MASQUE_H3'), WARP.index('Strategy.MASQUE_H2'), WARP.index('Strategy.MASQUE_H2_FRAGMENT'), WARP.index('Strategy.WIREGUARD'), WARP.index('Strategy.GOOL')]
        self.assertEqual(order, sorted(order))

    def test_real_socks_and_proxy_validation(self):
        self.assertIn('out.write(byteArrayOf(0x05, 0x01, 0x00))', WARP)
        self.assertIn('cdn-cgi/trace', WARP)
        self.assertIn('warp=on', WARP)
        self.assertIn('warp=plus', WARP)
        self.assertIn('successes >= 2', WARP)

    def test_dynamic_loopback_port(self):
        self.assertIn('private const val PORT_MIN = 1819', WARP)
        self.assertIn('private const val PORT_MAX = 1829', WARP)
        self.assertIn('PORT_MIN..PORT_MAX', WARP)
        self.assertIn('--bind', WARP)
        self.assertIn('BRIDGE_SUBSCRIPTION_ID', WARP)

    def test_network_scoped_memory_and_backoff(self):
        self.assertIn('networkSignature', WARP)
        self.assertIn('networkOperator.take(6)', WARP)
        self.assertNotIn('BSSID', WARP)
        for minutes in ['15 * 60_000L', '5 * 60_000L', '60_000L']:
            self.assertIn(minutes, WARP)

    def test_post_bridge_failure_falls_back_same_generation(self):
        self.assertIn('failedWasWarpBridge', HOME)
        self.assertIn('warpFallbackGeneration = connectionPreparationGeneration', HOME)
        self.assertIn('BlueVpnWarpEngine.stopAsync()', HOME)
        self.assertNotIn('warpFallbackUntilElapsed', HOME)

    def test_policy_schema_two_and_typed_values(self):
        self.assertIn("'schema' => 2", ADS)
        self.assertIn("'free_warp_schema' => 2", DB)
        for key in ['adaptive_strategy_enabled','allowed_transports','quick_reconnect','scan_mode','ip_mode','h2_enabled','fragment_enabled','wireguard_enabled','warp_in_warp_enabled','warm_timeout_seconds','cold_timeout_seconds','total_timeout_seconds']:
            self.assertIn(key, ADS)
        self.assertIn('coerceIn(30, 90)', ACCOUNT)

    def test_log_rotation_is_bounded(self):
        self.assertIn('512L * 1024L', WARP)
        self.assertIn('bluevpn-aether.log.1', WARP)

    def test_aether_build_is_locked_and_pinned(self):
        self.assertIn('a26159b82a70048b459e0128213c71767abecb8a', BUILD)
        self.assertGreaterEqual(BUILD.count('"--locked"'), 2)
        self.assertIn('required_help_flags', BUILD)
        self.assertIn('sha256', BUILD)

    def test_premium_core_not_replaced(self):
        self.assertNotIn('sing-box', WARP.lower())
        self.assertIn('CoreServiceManager', HOME)

    def test_no_sleep_in_warp_supervisor(self):
        self.assertNotIn('Thread.sleep', WARP)

if __name__ == '__main__':
    unittest.main()
