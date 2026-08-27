from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]

class AndroidConnectedLocationStateTests(unittest.TestCase):
    def text(self,path):
        return (ROOT/path).read_text(encoding="utf-8")

    def test_connected_route_overrides_stale_preferred_country_in_locations_ui(self):
        src=self.text("android-source/BlueVpnServersActivity.kt")
        start=src.index("private fun renderLocationsNow")
        end=src.index("private fun availabilityLabel",start)
        body=src[start:end]
        self.assertIn("val connectedNow = BlueVpnRuntimeGate.connectionActive(this)",body)
        self.assertIn("connectedNow -> selectedLocation.orEmpty()",body)
        self.assertIn("else -> preferred.ifBlank { selectedLocation.orEmpty() }",body)
        self.assertIn("group.location.key == activeLocationKey",body)
        self.assertNotIn(
            "group.location.key == preferred ||\n                            (preferred.isBlank() && group.location.key == selectedLocation)",
            body,
        )

    def test_connected_country_is_sorted_from_actual_active_key(self):
        src=self.text("android-source/BlueVpnServersActivity.kt")
        start=src.index("private fun renderLocationsNow")
        end=src.index("private fun availabilityLabel",start)
        body=src[start:end]
        self.assertIn("it.location.key == activeLocationKey",body)
        self.assertIn("!connectedNow && it.location.key == preferred",body)

if __name__=="__main__":
    unittest.main()
