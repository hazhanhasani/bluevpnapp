import unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
ENGINE=(ROOT/'android-source/BlueVpnWarpEngine.kt').read_text()
class WarpExitGuard4610(unittest.TestCase):
    def test_iran_is_hard_reject(self):
        self.assertIn('if (country == "IR") ErrorCode.EXIT_IRAN',ENGINE); self.assertIn('country in policy.warpBlockedExitCountries',ENGINE)
    def test_connected_requires_real_data_plane_before_bridge_profile(self):
        self.assertIn('awaitValidatedDataPlane',ENGINE); self.assertIn('socksGreetingAndRemoteConnect',ENGINE); self.assertIn('No tunneled HTTPS probe succeeded',ENGINE)
    def test_exit_validation_distinguishes_unavailable_from_blocked(self):
        self.assertIn('ErrorCode.EXIT_VALIDATION_FAILED',ENGINE); self.assertIn('ErrorCode.EXIT_IRAN',ENGINE)
