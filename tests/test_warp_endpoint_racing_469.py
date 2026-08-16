import unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
ENGINE=(ROOT/'android-source/BlueVpnWarpEngine.kt').read_text()
POLICY=(ROOT/'android-source/BlueVpnWarpPolicy.kt').read_text()
BUILD=(ROOT/'scripts/build_aether_android.py').read_text()

class WarpEndpointRacing4610(unittest.TestCase):
    def test_native_single_process_racing(self):
        self.assertIn('delegate the actual race', ENGINE)
        self.assertIn('launchMutex.withLock', ENGINE)
        self.assertNotIn('Channel<ProbeOutcome>', ENGINE)
        self.assertNotIn('startIndependentAttempt(', ENGINE)
        self.assertNotIn('raceCandidates(', ENGINE)

    def test_native_scan_and_quick_reconnect_share_one_process(self):
        self.assertIn('command += if (quick && peer.isNullOrBlank()) "--quick-reconnect" else "--no-quick-reconnect"', ENGINE)
        self.assertIn('command += listOf("--scan", normalizedScanMode(scanModeOverride ?: p.warpScanMode))', ENGINE)
        self.assertIn('val quick = policy.warpQuickReconnect && cachedStrategy', ENGINE)

    def test_persistent_per_device_identity(self):
        self.assertIn('context.noBackupFilesDir', ENGINE)
        self.assertIn('legacy.copyRecursively(target, overwrite = false)', ENGINE)
        self.assertIn('XDG_CONFIG_HOME', ENGINE)
        self.assertIn('XDG_DATA_HOME', ENGINE)

    def test_transport_history_is_network_aware(self):
        self.assertIn('strategyScore(prefs, sig, it)', ENGINE)
        self.assertIn('strategy_stat:$sig:${strategy.name}', ENGINE)
        self.assertIn('recordStrategySuccess(', ENGINE)
        self.assertIn('lkgFresh', POLICY)

    def test_pinned_aether_runtime_contract_covers_v16_flags(self):
        self.assertIn('"--perf"', BUILD)
        self.assertIn('"--log-level"', BUILD)
        self.assertIn('command += listOf("--log-level", "info", "--perf", "medium")', ENGINE)

    def test_adaptive_backoff_distinguishes_failures(self):
        self.assertIn('"EXIT_IRAN", "WARP_EXIT_COUNTRY_BLOCKED" -> 30 * 60_000L',POLICY)
        self.assertIn('"NETWORK_CHANGED", "WARP_NETWORK_CHANGED" -> 2_000L',POLICY)

    def test_network_signature_is_privacy_safe(self):
        self.assertIn('operatorHash',ENGINE)
        self.assertIn('sha256(operator).take(10)',ENGINE)
        self.assertNotIn('BSSID',ENGINE)
