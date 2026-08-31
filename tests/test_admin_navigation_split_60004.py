from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]

class AdminNavigationSplit60201Tests(unittest.TestCase):
    def text(self,path):
        return (ROOT/path).read_text(encoding="utf-8")

    def test_android_and_windows_have_independent_routes(self):
        admin=self.text("bluevpn-manager/includes/class-bluevpn-admin.php")
        control=self.text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        sidebar=self.text("bluevpn-manager/includes/class-bluevpn-unified-ui.php")
        for slug in ["bluevpn-android-update","bluevpn-windows-update"]:
            self.assertIn(slug,admin)
            self.assertIn(slug,control)
            self.assertIn(slug,sidebar)
        self.assertIn("private static function tab_android(): void",control)
        self.assertIn("private static function tab_windows(): void",control)

    def test_legacy_app_page_is_only_a_router(self):
        control=self.text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        start=control.index("private static function tab_app(): void")
        end=control.index("private static function render_windows_release_management",start)
        body=control[start:end]
        self.assertIn("تنظیمات اندروید",body)
        self.assertIn("تنظیمات ویندوز",body)
        self.assertNotIn("BlueVPN_App_Release_Manager::releases",body)
        self.assertNotIn("BlueVPN_Windows_Release_Manager::releases",body)

    def test_android_page_no_longer_renders_windows_management(self):
        control=self.text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        start=control.index("private static function tab_android(): void")
        end=control.index("private static function tab_windows(): void",start)
        body=control[start:end]
        self.assertNotIn("render_windows_release_management",body)

    def test_primary_navigation_is_persian_and_grouped(self):
        sidebar=self.text("bluevpn-manager/includes/class-bluevpn-unified-ui.php")
        start=sidebar.index("private static function nav(): array")
        end=sidebar.index("private static function current_group",start)
        nav=sidebar[start:end]
        for label in ["کاربران و فروش","سرورها و اشتراک","برنامه‌ها و انتشار","ارتباط با کاربران","سلامت و نگهداری","هوشمندسازی"]:
            self.assertIn(label,nav)
        for english in ["BluePal","WARP","GuardCore","Migration","Tapsell","Sentinel","Manager","BlueAI"]:
            self.assertNotIn(english,nav)

    def test_visible_control_center_tabs_are_persian(self):
        control=self.text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        start=control.index("private const TABS")
        end=control.index("private const PAGE_SLUGS",start)
        tabs=control[start:end]
        for english in ["BlueAI","Backup","PasarGuard","Marzban","Shahrah","GuardCore","Source","Gateway","SMS","OTP"]:
            self.assertNotIn(english,tabs)

if __name__=="__main__":
    unittest.main()
