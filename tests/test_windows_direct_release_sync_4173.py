import json
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding='utf-8')

class WindowsDirectReleaseSync4173Tests(unittest.TestCase):
    def test_release_version(self):
        r=json.loads(text('release.json'))
        self.assertEqual(r['version'],'5.8.0')
        self.assertEqual(r['version_code'],50800)

    def test_signed_direct_push_endpoint_exists(self):
        api=text('bluevpn-manager/includes/class-bluevpn-api.php')
        bot=text('bluevpn-manager/includes/class-bluevpn-telegram-bot.php')
        wm=text('bluevpn-manager/includes/class-bluevpn-windows-release-manager.php')
        self.assertIn("['/windows/release-sync','POST','windows_release_sync']",api)
        self.assertIn('x-bluevpn-release-signature',api.lower())
        self.assertIn("hash_hmac('sha256', $timestamp . \"\\n\" . $raw, $secret)",api)
        self.assertIn('release_sync_secret_for_internal_requests',bot)
        self.assertIn('ingest_direct_payload',wm)
        self.assertIn('github_signed_push',api)

    def test_workflow_pushes_metadata_without_github_polling(self):
        wf=text('.github/workflows/build-windows.yml')
        self.assertIn('Push signed Windows release metadata to WordPress',wf)
        self.assertIn('/wp-json/bluevpn/v1/windows/release-sync',wf)
        self.assertIn('X-BlueVPN-Release-Signature',wf)
        self.assertIn('TELEGRAM_BOT_TOKEN: ${{ secrets.TELEGRAM_BOT_TOKEN }}',wf)
        self.assertIn('WordPress pull fallback',wf)
        self.assertIn("--write-out '%{http_code}'",wf)
        self.assertNotIn('Authoritative Windows release metadata push failed after 5 attempts',wf)

    def test_publish_intent_is_saved_before_network_fallback(self):
        wm=text('bluevpn-manager/includes/class-bluevpn-windows-release-manager.php')
        block=wm.split('public static function promote_version_to_stable',1)[1].split('private static function latest_by_state',1)[0]
        self.assertLess(block.index('request_stable_when_available($version)'), block.index("sync_now(true,'coordinated_stable_publish_fallback')"))
        self.assertIn('درخواست Stable محفوظ ماند',block)

    def test_public_theme_does_not_poll_github_when_manager_active(self):
        helper=text('bluevpn-site/inc/helpers.php')
        block=helper.split("if (class_exists('BlueVPN_Windows_Release_Manager'))",1)[1].split('$repo = bluevpn_site_windows_release_repository();',1)[0]
        self.assertIn("return $empty;",block)
        self.assertNotIn('wp_remote_get',block)

    def test_fallback_http_is_sentinel_quiet(self):
        wm=text('bluevpn-manager/includes/class-bluevpn-windows-release-manager.php')
        monitor=text('bluevpn-manager/includes/class-bluevpn-error-monitor.php')
        self.assertIn("$headers['X-BlueVPN-Sentinel-Ignore'] = '1';",wm)
        self.assertIn("x-bluevpn-sentinel-ignore",monitor)

if __name__=='__main__': unittest.main()
