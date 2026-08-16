from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]

class SupportTehranTime41110(unittest.TestCase):
    def test_shared_parser_accepts_mysql_utc(self):
        text = (ROOT / 'android-source/BlueVpnAccountManager.kt').read_text()
        self.assertIn('"yyyy-MM-dd HH:mm:ss.SSS"', text)
        self.assertIn('"yyyy-MM-dd HH:mm:ss"', text)
        self.assertIn('timeZone = TimeZone.getTimeZone("UTC")', text)
        self.assertIn('Calendar.getInstance(tehran)', text)

    def test_support_uses_tehran_parser_not_raw_clock_regex(self):
        text = (ROOT / 'android-source/BlueVpnSupportActivity.kt').read_text()
        self.assertIn('import com.v2ray.ang.bluevpn.BlueVpnPersianDate', text)
        self.assertIn('BlueVpnPersianDate.formatIso(raw, includeTime = true)', text)
        self.assertIn('.substringAfter("ساعت ")', text)
        self.assertNotIn('Regex("""(\\d{2}):(\\d{2}):\\d{2}""").find(raw)', text)

    def test_release_version(self):
        import json
        app = json.loads((ROOT / 'branding/app.json').read_text())
        release = json.loads((ROOT / 'release.json').read_text())
        self.assertEqual((app['version_name'], app['version_code']), ('4.11.10', 41110))
        self.assertEqual((release['version'], release['version_code']), ('4.11.10', 41110))
        self.assertEqual(app['version_source'], 'v4.11.10-iran-time-fix')
        self.assertEqual(release['version_source'], 'v4.11.10-iran-time-fix')

if __name__ == '__main__':
    unittest.main()
