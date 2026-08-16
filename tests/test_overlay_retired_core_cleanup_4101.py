import pathlib, unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]
class OverlayRetiredCoreCleanup4101(unittest.TestCase):
    def test_cleanup_explicitly_retires_old_core_flavor(self):
        s=(ROOT/"scripts/cleanup_repository.py").read_text()
        self.assertIn('"android-source/BlueVpnCoreFlavor.kt"',s)
        self.assertIn('"third_party/MAHSA_CORE_CANARY.md"',s)
    def test_release_contains_no_alternate_core_artifacts(self):
        self.assertFalse((ROOT/"android-source/BlueVpnCoreFlavor.kt").exists())
        self.assertFalse((ROOT/"third_party/MAHSA_CORE_CANARY.md").exists())
if __name__=="__main__": unittest.main()
