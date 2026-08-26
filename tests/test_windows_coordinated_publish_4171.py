import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def text(rel):
    return (ROOT / rel).read_text(encoding='utf-8')

class WindowsCoordinatedPublish4171Tests(unittest.TestCase):
    def test_release_version(self):
        release=json.loads(text('release.json'))
        branding=json.loads(text('branding/app.json'))
        self.assertEqual(release['version'],'5.9.3')
        self.assertEqual(release['version_code'],50903)
        self.assertEqual(release['windows_version'],'5.9.3')
        self.assertEqual(release['windows_version_code'],50903)
        self.assertEqual(branding['version_name'],'5.9.3')
        self.assertEqual(branding['version_code'],50903)

    def test_windows_workflow_kicks_wordpress_after_publish(self):
        workflow=text('.github/workflows/build-windows.yml')
        self.assertIn('Push signed Windows release metadata to WordPress', workflow)
        self.assertIn('/wp-json/bluevpn/v1/windows/release-sync', workflow)
        self.assertIn('X-BlueVPN-Release-Signature', workflow)

    def test_windows_update_refresh_forces_release_sync_kick(self):
        api=text('bluevpn-manager/includes/class-bluevpn-api.php')
        self.assertIn("$forced = rest_sanitize_boolean($r->get_param('refresh'));", api)
        self.assertIn("BlueVPN_Windows_Release_Manager::sync_now(true, 'rest_force_refresh')", api)
        self.assertIn("'release_refresh_mode'=>$forced?'synchronous_force_refresh':'cache_first_background'", api)

    def test_android_default_promotion_coordinates_matching_windows_release(self):
        cc=text('bluevpn-manager/includes/class-bluevpn-control-center.php')
        wm=text('bluevpn-manager/includes/class-bluevpn-windows-release-manager.php')
        app=text('bluevpn-manager/includes/class-bluevpn-app-release-manager.php')
        self.assertIn('انتشار رسمی Android + Windows', cc)
        self.assertIn('فقط Android', cc)
        self.assertIn('promote_version_to_stable($version,true)', cc)
        self.assertIn('public static function release_by_id(int $releaseId)', app)
        self.assertIn('public static function release_by_version(string $version)', wm)
        self.assertIn('public static function promote_version_to_stable(string $version,bool $syncIfMissing=true)', wm)
        self.assertIn("sync_now(true,'coordinated_stable_publish_fallback')", wm)
        self.assertIn('request_stable_when_available($version)', wm)

if __name__ == '__main__':
    unittest.main()
