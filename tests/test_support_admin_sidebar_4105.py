import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]

class SupportAdminSidebar4105(unittest.TestCase):
    def text(self, path):
        return (ROOT / path).read_text()

    def test_support_is_registered_in_wordpress_submenu(self):
        support=self.text("bluevpn-manager/includes/class-bluevpn-support.php")
        self.assertIn(
            "add_submenu_page('bluevpn-manager','پشتیبانی آنلاین','پشتیبانی آنلاین'",
            support,
        )

    def test_support_is_visible_in_custom_bluevpn_sidebar(self):
        ui=self.text("bluevpn-manager/includes/class-bluevpn-unified-ui.php")
        services=ui[
            ui.index("'کاربران و فروش' => ["):
            ui.index("'شبکه و سرویس' => [")
        ]
        self.assertIn(
            "['bluevpn-support', 'پشتیبانی آنلاین', 'support']",
            services,
        )
        self.assertIn("'support' =>", ui)

    def test_support_page_uses_unified_bluevpn_shell(self):
        support=self.text("bluevpn-manager/includes/class-bluevpn-support.php")
        start=support.index("public static function admin_page(): void")
        end=support.index("public static function admin_reply(): void", start)
        page=support[start:end]
        self.assertIn("BlueVPN_Unified_UI::shell_open(", page)
        self.assertIn("'پشتیبانی آنلاین'", page)
        self.assertIn("BlueVPN_Unified_UI::shell_close();", page)

    def test_support_link_slug_matches_registered_page(self):
        support=self.text("bluevpn-manager/includes/class-bluevpn-support.php")
        ui=self.text("bluevpn-manager/includes/class-bluevpn-unified-ui.php")
        self.assertIn("'bluevpn-support'", support)
        self.assertIn("'bluevpn-support'", ui)

if __name__ == "__main__":
    unittest.main()
