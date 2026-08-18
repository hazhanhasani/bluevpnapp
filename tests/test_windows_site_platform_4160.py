import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]

def text(rel: str) -> str:
    return (ROOT / rel).read_text(encoding='utf-8')

class WindowsSitePlatform4160Tests(unittest.TestCase):
    def test_windows_release_channel_is_explicit(self):
        release = json.loads(text('release.json'))
        windows = release.get('windows') or {}
        self.assertEqual(windows.get('status'), 'beta')
        self.assertEqual(windows.get('channel'), 'beta')
        self.assertEqual(windows.get('stage'), 'v2rayn-warp-installer')
        self.assertEqual(windows.get('website_distribution'), 'github_release')

    def test_theme_discovers_actual_windows_release(self):
        helpers = text('bluevpn-site/inc/helpers.php')
        self.assertIn("/releases?per_page=30", helpers)
        self.assertIn("bluevpn-windows-v", helpers)
        self.assertIn("BlueVPN-Windows-Channel:", helpers)
        self.assertIn("'available' => true", helpers)
        self.assertIn("BlueVPN-Windows-", helpers)
        self.assertNotIn("$version ?: BLUEVPN_SITE_VERSION", helpers)

    def test_elementor_and_php_page_share_one_download_renderer(self):
        widget = text('bluevpn-site/inc/elementor/widgets.php')
        page = text('bluevpn-site/page-download.php')
        view = text('bluevpn-site/inc/download-view.php')
        shared = "require BLUEVPN_SITE_DIR . '/inc/download-view.php';"
        self.assertIn(shared, widget)
        self.assertIn(shared, page)
        self.assertIn('BlueVPN for Windows', view)
        self.assertIn('نصب برای Windows', view)
        self.assertIn('نصب Windows ARM', view)
        self.assertNotIn('وضعیت نامشخص', view)
        self.assertNotIn('جزئیات انتشار و SHA256', view)
        self.assertNotIn('کانال انتشار', view)
        self.assertIn("bluevpn_site_windows_downloads()", view)

    def test_windows_workflow_marks_beta_release_and_embeds_channel_marker(self):
        workflow = text('.github/workflows/build-windows.yml')
        self.assertIn('BlueVPN-Windows-Channel: ${WINDOWS_CHANNEL}', workflow)
        self.assertIn('ARGS+=(--prerelease)', workflow)
        self.assertIn('if [ "$WINDOWS_CHANNEL" = beta ]; then PRE=true; else PRE=false; fi', workflow)
        self.assertIn('releases/tags/${TAG}', workflow)

    def test_download_seo_covers_windows(self):
        seo = text('bluevpn-site/inc/class-bluevpn-seo.php')
        self.assertIn('BlueVPN for Windows', seo)
        self.assertIn("'operatingSystem' => 'Windows 10, Windows 11'", seo)
        self.assertIn("#software-windows", seo)

if __name__ == '__main__':
    unittest.main()
