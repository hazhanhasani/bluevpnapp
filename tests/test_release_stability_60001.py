from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]

class ReleaseStability60001Tests(unittest.TestCase):
    def test_sentinel_has_green_health_job(self):
        text=(ROOT/".github/workflows/bluevpn-sentinel.yml").read_text(encoding="utf-8")
        self.assertIn("sentinel-status:", text)
        self.assertIn("Sentinel health status", text)
        self.assertIn("report-failure:", text)

    def test_admin_pages_are_centrally_registered_without_duplicate_hooks(self):
        admin=(ROOT/"bluevpn-manager/includes/class-bluevpn-admin.php").read_text(encoding="utf-8")
        self.assertIn("add_action('admin_menu',[self::class,'menu'],1)", admin)
        self.assertIn("['bluevpn-subscription-sources','منابع اشتراک','sources']", admin)
        self.assertIn("add_submenu_page('bluevpn-manager',$label,$label,'manage_options',$slug", admin)
        for slug in ["bluevpn-support","bluevpn-telegram-bot","bluevpn-error-monitor"]:
            self.assertIn(slug, admin)
        for rel in [
            "bluevpn-manager/includes/class-bluevpn-support.php",
            "bluevpn-manager/includes/class-bluevpn-telegram-bot.php",
            "bluevpn-manager/includes/class-bluevpn-error-monitor.php",
        ]:
            text=(ROOT/rel).read_text(encoding="utf-8")
            self.assertNotIn("add_action('admin_menu'", text)

    def test_windows_startup_ads_are_non_fatal(self):
        text=(ROOT/"bluevpn-windows/MainWindow.xaml.cs").read_text(encoding="utf-8")
        self.assertIn("LoadAdsSafeAsync()", text)
        self.assertIn("BlueVPN اجرا شد؛ بخشی از اطلاعات اولیه", text)
        self.assertIn("تبلیغات فعلاً در دسترس نیست؛ BlueVPN بدون تبلیغ ادامه می‌دهد", text)

if __name__ == "__main__":
    unittest.main()
