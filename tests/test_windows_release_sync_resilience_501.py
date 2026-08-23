import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def text(rel):
    return (ROOT / rel).read_text(encoding='utf-8')

class WindowsReleaseSyncResilience501Tests(unittest.TestCase):
    def test_release_is_501(self):
        release = json.loads(text('release.json'))
        branding = json.loads(text('branding/app.json'))
        self.assertEqual(release['version'], '5.2.4')
        self.assertEqual(release['version_code'], 50204)
        self.assertEqual(branding['version_name'], '5.2.4')
        self.assertEqual(branding['version_code'], 50204)

    def test_publish_does_not_fail_after_installers_are_public(self):
        wf = text('.github/workflows/build-windows.yml')
        block = wf.split('- name: Push signed Windows release metadata to WordPress', 1)[1].split('- name: Verify WordPress Windows distribution state (diagnostic)', 1)[0]
        self.assertIn('BLUEVPN_RELEASE_SYNC_SECRET: ${{ secrets.BLUEVPN_RELEASE_SYNC_SECRET }}', block)
        self.assertIn('SYNC_SECRET="${BLUEVPN_RELEASE_SYNC_SECRET:-${TELEGRAM_BOT_TOKEN:-}}"', block)
        self.assertIn("--write-out '%{http_code}'", block)
        self.assertIn('release-sync attempt ${attempt}/5: curl=${CURL_STATUS}, http=${LAST_HTTP}', block)
        self.assertIn('/wp-json/bluevpn/v1/windows/update?arch=win-x64&current_version=0.0.0&refresh=1', block)
        self.assertIn("data.get('release_refresh_ok') is True", block)
        self.assertIn('Windows installers were built, checksummed, published and publicly verified', block)
        self.assertNotIn('Authoritative Windows release metadata push failed after 5 attempts', block)

    def test_distribution_probe_is_diagnostic_not_release_blocker(self):
        wf = text('.github/workflows/build-windows.yml')
        block = wf.split('- name: Verify WordPress Windows distribution state (diagnostic)', 1)[1]
        self.assertIn('WordPress distribution state is temporarily unavailable', block)
        self.assertIn('exit 0', block)

    def test_manager_accepts_dedicated_or_legacy_sync_secret(self):
        bot = text('bluevpn-manager/includes/class-bluevpn-telegram-bot.php')
        api = text('bluevpn-manager/includes/class-bluevpn-api.php')
        self.assertIn('release_sync_secrets_for_internal_requests', bot)
        self.assertIn("defined('BLUEVPN_RELEASE_SYNC_SECRET')", bot)
        self.assertIn("getenv('BLUEVPN_RELEASE_SYNC_SECRET')", bot)
        self.assertIn('$values[] = trim(self::bot_token());', bot)
        self.assertIn('foreach ($secrets as $secret)', api)
        self.assertIn("'manager_version'=>BLUEVPN_MANAGER_VERSION", api)

if __name__ == '__main__':
    unittest.main()
