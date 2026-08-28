from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class AndroidLocationsActivePayloadTest(unittest.TestCase):
    def text(self, path: str) -> str:
        return (ROOT / path).read_text(encoding="utf-8")

    def test_diffutil_emits_server_and_country_state_payloads(self):
        diff = self.text("android-source/BlueVpnLocationRowDiff.kt")
        self.assertIn('PAYLOAD_SERVER_STATE', diff)
        self.assertIn('PAYLOAD_COUNTRY_ACTIVE', diff)
        self.assertIn("oldItem.active != newItem.active", diff)
        self.assertIn("oldItem.automaticActive != newItem.automaticActive", diff)
        self.assertIn("oldItem.manualActive != newItem.manualActive", diff)

    def test_server_payload_updates_without_rebuilding_row(self):
        src = self.text("android-source/BlueVpnServersActivity.kt")
        start = src.index("private fun bindServerStatePayload")
        end = src.index("private fun bindCountryActivePayload", start)
        body = src[start:end]
        for token in [
            "TAG_SERVER_SURFACE",
            "TAG_SERVER_RAIL",
            "TAG_SERVER_TITLE",
            "TAG_SERVER_HEALTH",
            "TAG_SERVER_SIGNAL",
            "TAG_SERVER_ACTION",
        ]:
            self.assertIn(token, body)
        self.assertNotIn("removeAllViews()", body)
        self.assertIn('item.manualActive -> "دستی"', body)
        self.assertIn('item.active -> "وصل"', body)

    def test_country_payload_updates_active_visuals_only(self):
        src = self.text("android-source/BlueVpnServersActivity.kt")
        start = src.index("private fun bindCountryActivePayload")
        end = src.index("\n    }\n\n    private val mainViewModel", start)
        body = src[start:end]
        self.assertIn("TAG_COUNTRY_SURFACE", body)
        self.assertIn("TAG_COUNTRY_AVAILABILITY", body)
        self.assertIn("TAG_COUNTRY_ACTION", body)
        self.assertIn("item.automaticActive -> \"AUTO\"", body)
        self.assertNotIn("removeAllViews()", body)

    def test_active_rail_is_always_mounted_for_partial_updates(self):
        src = self.text("android-source/BlueVpnServersActivity.kt")
        start = src.index("private fun createServerRow")
        end = src.index("private fun createLocationSection", start)
        body = src[start:end]
        self.assertIn("tag = TAG_SERVER_RAIL", body)
        self.assertIn("android.graphics.Color.TRANSPARENT", body)
        self.assertNotIn("if (active) {\n            row.addView", body)

    def test_active_server_uses_single_accent_rail_not_blue_everywhere(self):
        src = self.text("android-source/BlueVpnServersActivity.kt")
        start = src.index("private fun createServerRow")
        end = src.index("private fun createLocationSection", start)
        body = src[start:end]
        self.assertIn("stroke = android.graphics.Color.TRANSPARENT", body)
        self.assertIn("strokeWidth = 0", body)
        self.assertIn("tag = TAG_SERVER_RAIL", body)
        self.assertIn("if (active) palette.accent else android.graphics.Color.TRANSPARENT", body)
        self.assertIn("background = rounded(palette.surfaceStrong, 11)", body)

    def test_row_models_capture_exact_selection_ownership(self):
        model = self.text("android-source/BlueVpnLocationListRow.kt")
        self.assertIn("val automaticActive: Boolean", model)
        self.assertIn("val manualActive: Boolean", model)


if __name__ == "__main__":
    unittest.main()
