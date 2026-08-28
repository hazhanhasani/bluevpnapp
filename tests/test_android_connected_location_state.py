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

    def test_connected_country_is_highlighted_without_reordering_master_list(self):
        src=self.text("android-source/BlueVpnServersActivity.kt")
        start=src.index("private fun renderLocationsNow")
        end=src.index("private fun availabilityLabel",start)
        body=src[start:end]
        self.assertIn("group.location.key == activeLocationKey",body)
        sort=body.split(".sortedWith(",1)[1].split("emptyText.text",1)[0]
        self.assertIn("LocationTab.ALL",sort)
        self.assertIn("compareBy<LocationGroup> { it.location.title }",sort)
        self.assertNotIn("it.location.key == activeLocationKey",sort)
        self.assertNotIn("it.location.key == preferred",sort)

if __name__=="__main__":
    unittest.main()
