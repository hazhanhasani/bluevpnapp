import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]


class AndroidLocationsOemUi5311Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.source = (ROOT / "android-source/BlueVpnServersActivity.kt").read_text()

    def test_tabs_do_not_inherit_oem_material_button_shapes(self):
        self.assertIn("private fun tabButton(label: String, action: () -> Unit): TextView", self.source)
        self.assertIn("private fun applyTab(button: TextView, active: Boolean)", self.source)
        self.assertIn("button.background = rounded(", self.source)

    def test_favorite_control_and_cards_have_explicit_visuals(self):
        self.assertIn("val favoriteButton = TextView(this).apply", self.source)
        self.assertIn("if (group.favorite)", self.source)
        self.assertIn("android.graphics.Color.TRANSPARENT", self.source)
        self.assertIn("0xFFE5A91B.toInt()", self.source)
        self.assertIn("rippleColor = ColorStateList.valueOf(android.graphics.Color.TRANSPARENT)", self.source)

    def test_unknown_routes_remain_auto_only_not_fake_manual_locations(self):
        self.assertIn('if (location.key == "unknown") return@mapNotNull null', self.source)


if __name__ == "__main__":
    unittest.main()
