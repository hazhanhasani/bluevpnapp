import pathlib, unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]
class WarpBackoffRecovery481(unittest.TestCase):
    def test_all_backed_off_forces_recovery_probe(self):
        s=(ROOT/"android-source/BlueVpnWarpEngine.kt").read_text()
        self.assertIn("allStrategiesBackedOff", s)
        self.assertIn("recoveryProbeAll", s)
        self.assertIn("!quick && !recoveryProbeAll", s)
        self.assertIn("attemptedStrategies += 1", s)
    def test_terminal_diagnostics_include_skip_counts(self):
        s=(ROOT/"android-source/BlueVpnWarpEngine.kt").read_text()
        self.assertIn("attempted=$attemptedStrategies", s)
        self.assertIn("skipped=${skippedStrategies.joinToString", s)
    def test_failed_free_warp_hides_stale_location_ready_state(self):
        s=(ROOT/"android-source/BlueVpnHomeActivity.kt").read_text()
        self.assertIn("freeWarpFailed", s)
        self.assertIn("مسیر قبلی موقتاً کنار گذاشته شد", s)
        self.assertIn('locationValue.text = "—"', s)
if __name__=="__main__": unittest.main()
