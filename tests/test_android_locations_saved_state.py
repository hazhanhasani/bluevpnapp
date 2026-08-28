from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class AndroidLocationsSavedStateTest(unittest.TestCase):
    def text(self, path: str) -> str:
        return (ROOT / path).read_text(encoding="utf-8")

    def test_activity_saves_navigation_state(self):
        src = self.text("android-source/BlueVpnServersActivity.kt")
        for token in [
            "STATE_TAB",
            "STATE_QUERY",
            "STATE_EXPANDED",
            "STATE_SCROLL_Y",
            "override fun onSaveInstanceState",
            "outState.putString(STATE_TAB, selectedTab.name)",
            "outState.putString(STATE_QUERY, queryText)",
            "ArrayList(expandedLocationKeys)",
        ]:
            self.assertIn(token, src)

    def test_activity_restores_state_before_screen_creation(self):
        src = self.text("android-source/BlueVpnServersActivity.kt")
        restore = src.index("savedInstanceState?.let")
        create = src.index("setContentView(createScreen())")
        self.assertLess(restore, create)
        body = src[restore:create]
        self.assertIn("LocationTab.valueOf", body)
        self.assertIn("expandedLocationKeys.addAll", body)
        self.assertIn("restoredScrollY", body)

    def test_search_text_and_normalized_query_are_separate(self):
        src = self.text("android-source/BlueVpnServersActivity.kt")
        self.assertIn('private var queryText = ""', src)
        self.assertIn("queryText = s?.toString().orEmpty()", src)
        self.assertIn("normalizeForSearch(queryText)", src)
        self.assertIn("searchField.setText(queryText)", src)

    def test_scroll_memory_is_scoped_by_tab_and_query(self):
        src = self.text("android-source/BlueVpnServersActivity.kt")
        start = src.index("private fun scrollPreferenceKey")
        end = src.index("private fun rememberLocationScroll", start)
        body = src[start:end]
        self.assertIn("selectedTab.name", body)
        self.assertIn("query.hashCode()", body)
        self.assertNotIn('"scroll_y"', body)


if __name__ == "__main__":
    unittest.main()
