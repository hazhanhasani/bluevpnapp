from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]

def text(rel: str) -> str:
    return (ROOT / rel).read_text(encoding="utf-8")

class SiteThemeAutoUpdate4161Tests(unittest.TestCase):
    def test_public_download_copy_hides_internal_release_diagnostics(self):
        view = text("bluevpn-site/inc/download-view.php")
        self.assertIn("BlueVPN for Windows", view)
        self.assertIn("نصب برای Windows", view)
        self.assertIn("نسخه Windows به‌زودی منتشر می‌شود", view)
        for internal in ("وضعیت نامشخص", "جزئیات انتشار و SHA256", "کانال انتشار", "Windows یک انتشار مستقل"):
            self.assertNotIn(internal, view)

    def test_updater_accepts_beta_theme_releases(self):
        updater = text("bluevpn-site/inc/class-bluevpn-site-updater.php")
        self.assertIn("Do not skip prereleases", updater)
        self.assertNotIn("!empty($release['prerelease'])) continue", updater)
        self.assertIn("'prerelease' => !empty($release['prerelease'])", updater)

    def test_updater_uses_real_active_stylesheet(self):
        updater = text("bluevpn-site/inc/class-bluevpn-site-updater.php")
        self.assertIn("private static function stylesheet()", updater)
        self.assertIn("get_stylesheet()", updater)
        self.assertIn("$transient->checked[$stylesheet]", updater)
        self.assertIn("$upgrader->upgrade($stylesheet", updater)

    def test_updater_has_stale_release_and_upgrader_recovery(self):
        updater = text("bluevpn-site/inc/class-bluevpn-site-updater.php")
        self.assertIn("/contents/release.json?ref=main", updater)
        self.assertIn("/releases/tags/", updater)
        self.assertIn("ZipArchive::CHECKCONS", updater)
        self.assertIn("'overwrite_package' => true", updater)
        self.assertIn("site_transient_update_themes", updater)

    def test_updater_runs_frequently_and_cpanel_kick_is_not_too_short(self):
        updater = text("bluevpn-site/inc/class-bluevpn-site-updater.php")
        self.assertIn("bluevpn_two_minutes", updater)
        self.assertIn("'timeout' => 1", updater)
        self.assertNotIn("'timeout' => 0.01", updater)

if __name__ == '__main__':
    unittest.main()
