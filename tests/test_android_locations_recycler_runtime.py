from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class AndroidLocationsRecyclerRuntimeTest(unittest.TestCase):
    def text(self, path: str) -> str:
        return (ROOT / path).read_text(encoding="utf-8")

    def test_locations_screen_uses_recyclerview_listadapter(self):
        src = self.text("android-source/BlueVpnServersActivity.kt")
        self.assertIn("private lateinit var locationsRecyclerView: RecyclerView", src)
        self.assertIn("private lateinit var locationsAdapter: LocationsAdapter", src)
        self.assertIn("ListAdapter<BlueVpnLocationListRow", src)
        self.assertIn("LinearLayoutManager(this@BlueVpnServersActivity)", src)
        self.assertNotIn("ScrollView(this).apply", src)

    def test_country_and_server_rows_are_flattened(self):
        src = self.text("android-source/BlueVpnServersActivity.kt")
        start = src.index("val rows = buildList")
        end = src.index("locationsAdapter.submitList(rows)", start)
        body = src[start:end]
        self.assertIn("BlueVpnLocationListRow.Country(", body)
        self.assertIn("BlueVpnLocationListRow.Server(", body)
        self.assertIn("if (expanded)", body)
        self.assertIn("stableServerRows", body)

    def test_country_view_no_longer_mounts_nested_server_tree(self):
        src = self.text("android-source/BlueVpnServersActivity.kt")
        start = src.index("private fun createLocationSection")
        end = src.index("private fun openSubscriptionForPremium", start)
        body = src[start:end]
        self.assertNotIn("serverBox.addView", body)
        self.assertNotIn("stableServerRows(group.location", body)
        self.assertIn("flattened into the RecyclerView adapter", body)

    def test_ping_refresh_routes_through_diffutil(self):
        src = self.text("android-source/BlueVpnServersActivity.kt")
        start = src.index("private fun refreshVisibleHealthPresentation")
        end = src.index("private fun stableServerRows", start)
        body = src[start:end]
        self.assertIn("renderLocations()", body)
        self.assertNotIn("view.text =", body)

    def test_recycler_scroll_state_is_persisted(self):
        src = self.text("android-source/BlueVpnServersActivity.kt")
        self.assertIn("locationsRecyclerView.computeVerticalScrollOffset()", src)
        self.assertIn("locationsRecyclerView.scrollToPosition(0)", src)
        self.assertIn("val delta = targetScrollY - currentScrollY", src)
        self.assertIn("locationsRecyclerView.scrollBy(0, delta)", src)
        self.assertNotIn("locationsRecyclerView.scrollBy(0, targetScrollY)", src)


if __name__ == "__main__":
    unittest.main()
