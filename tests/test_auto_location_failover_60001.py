from pathlib import Path
import unittest
ROOT=Path(__file__).resolve().parents[1]
class AutoLocationFailover60001Tests(unittest.TestCase):
    def test_auto_groups_hidden_routes_by_location(self):
        s=(ROOT/"android-source/BlueVpnHomeActivity.kt").read_text(encoding="utf-8")
        self.assertIn("locationAwareAutoQueue",s)
        self.assertIn("groupBy { it.candidate.location.key }",s)
        self.assertIn("val effectiveQueue = if (selectionMode == BlueVpnSelectionMode.AUTO)",s)
        self.assertIn(") 900L else 650L",s)
if __name__=="__main__": unittest.main()
