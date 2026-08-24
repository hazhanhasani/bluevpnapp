import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]


class AdminMobileViewport543Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.ui = (ROOT / "bluevpn-manager/includes/class-bluevpn-unified-ui.php").read_text()
        cls.css = (ROOT / "bluevpn-manager/assets/admin-unified.css").read_text()

    def test_bluevpn_pages_force_a_real_device_width_viewport(self):
        self.assertIn("add_action('admin_head', [self::class, 'enforce_mobile_viewport'], 999)", self.ui)
        self.assertIn('m.name="viewport"', self.ui)
        self.assertIn('width=device-width,initial-scale=1', self.ui)
        self.assertIn('viewport-fit=cover', self.ui)

    def test_mobile_shell_cannot_keep_a_desktop_minimum_width(self):
        self.assertIn('html.bluevpn-standalone-html,body.bluevpn-standalone-admin,.bluevpn-admin-app,.bluevpn-main', self.css)
        self.assertIn('width:100%!important;max-width:100%!important;min-width:0!important', self.css)


if __name__ == "__main__":
    unittest.main()
