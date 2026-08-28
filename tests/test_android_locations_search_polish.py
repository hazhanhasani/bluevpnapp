from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class AndroidLocationsSearchPolishTest(unittest.TestCase):
    def text(self, path: str) -> str:
        return (ROOT / path).read_text(encoding="utf-8")

    def test_search_matches_server_labels_and_remarks(self):
        src = self.text("android-source/BlueVpnServersActivity.kt")
        self.assertIn("private fun groupMatchesQuery", src)
        self.assertIn("private fun serverMatchesQuery", src)
        self.assertIn('group.location.title + " " + ordinal', src)
        self.assertIn("candidate.profile.remarks.orEmpty()", src)

    def test_server_search_can_surface_matching_rows_without_mutating_user_expansion(self):
        src = self.text("android-source/BlueVpnServersActivity.kt")
        self.assertIn("val matchingServerRows =", src)
        self.assertIn("query.isNotBlank() && !locationMatchesQuery", src)
        self.assertIn("matchingServerRows.forEach", src)
        self.assertNotIn("expandedLocationKeys.add(group.location.key)", src.split("val matchingServerRows =", 1)[1].split("BlueVpnLocationListRow.Country(", 1)[0])

    def test_primary_country_controls_have_larger_touch_targets(self):
        src = self.text("android-source/BlueVpnServersActivity.kt")
        self.assertIn("LinearLayout.LayoutParams(dp(48), dp(48))", src)
        self.assertIn("LinearLayout.LayoutParams(dp(44), dp(48))", src)


if __name__ == "__main__":
    unittest.main()
