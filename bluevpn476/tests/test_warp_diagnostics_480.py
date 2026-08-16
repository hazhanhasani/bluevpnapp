import pathlib, unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]
class WarpDiagnostics480(unittest.TestCase):
    def test_engine_persists_structured_failure(self):
        s=(ROOT/"android-source/BlueVpnWarpEngine.kt").read_text()
        for x in ["lastFailure", "persistDiagnostic", "bluevpn_warp_diagnostics_v1", "duration_ms", "sanitizeDiagnostic"]:
            self.assertIn(x,s)
    def test_home_surfaces_real_failure(self):
        s=(ROOT/"android-source/BlueVpnHomeActivity.kt").read_text()
        self.assertIn("warpFailureTitle",s); self.assertIn("warpFailureCaption",s)
        self.assertIn("EXIT_IRAN",s); self.assertIn("WARP_START_TIMEOUT",s)
if __name__=="__main__": unittest.main()
