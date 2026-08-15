import unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
ENGINE=(ROOT/'android-source/BlueVpnWarpEngine.kt').read_text()
POLICY=(ROOT/'android-source/BlueVpnWarpPolicy.kt').read_text()
class WarpEndpointRacing4610(unittest.TestCase):
    def test_bounded_parallel_race(self):
        self.assertIn('val concurrency = min(4, max(2, candidates.size))',ENGINE); self.assertIn('Channel<ProbeOutcome>',ENGINE); self.assertIn('jobs += launch(Dispatchers.IO)',ENGINE)
    def test_direct_peer_skips_native_scan(self):
        self.assertIn('if (peer.isNullOrBlank()) command += listOf("--scan"',ENGINE); self.assertIn('Direct peer fast path must not run native scan',ENGINE)
    def test_scored_lkg_and_history(self):
        self.assertIn('candidateScore(',ENGINE); self.assertIn('edge_stat:',ENGINE); self.assertIn('lkgFresh',POLICY)
    def test_adaptive_backoff_distinguishes_failures(self):
        self.assertIn('"EXIT_IRAN", "WARP_EXIT_COUNTRY_BLOCKED" -> 30 * 60_000L',POLICY); self.assertIn('"NETWORK_CHANGED", "WARP_NETWORK_CHANGED" -> 2_000L',POLICY)
    def test_network_signature_is_privacy_safe(self):
        self.assertIn('operatorHash',ENGINE); self.assertIn('sha256(operator).take(10)',ENGINE); self.assertNotIn('BSSID',ENGINE)
