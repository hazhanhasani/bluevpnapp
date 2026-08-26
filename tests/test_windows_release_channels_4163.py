from pathlib import Path
import json
import unittest

ROOT = Path(__file__).resolve().parents[1]
def text(rel): return (ROOT / rel).read_text(encoding='utf-8')

class WindowsReleaseChannels4163(unittest.TestCase):
    def test_release_and_schema_versions(self):
        release=json.loads(text('release.json'))
        self.assertEqual(release['version'],'5.10.10')
        self.assertEqual(release['version_code'],51010)
        self.assertEqual(release['windows']['release_authority'],'wordpress_manager')
        plugin=text('bluevpn-manager/bluevpn-manager.php')
        self.assertIn("BLUEVPN_MANAGER_SCHEMA_VERSION', '1.31.0", plugin)

    def test_windows_release_table_and_manager_exist(self):
        db=text('bluevpn-manager/includes/class-bluevpn-db.php')
        mgr=text('bluevpn-manager/includes/class-bluevpn-windows-release-manager.php')
        self.assertIn("windows_releases", db)
        self.assertIn("UNIQUE KEY uq_windows_release_version", db)
        self.assertIn("private const VALID_STATES = ['beta','stable','stopped','archived']", mgr)
        self.assertIn("'site_channel' => 'stable'", mgr)
        self.assertIn("'auto_update_stable' => true", mgr)
        self.assertIn("'auto_update_beta' => true", mgr)
        self.assertIn("public static function promote_to_stable", mgr)
        self.assertIn("public static function release_for_customer", mgr)
        self.assertIn("Installer کامل x64/ARM64", mgr)

    def test_panel_has_windows_channel_controls(self):
        cc=text('bluevpn-manager/includes/class-bluevpn-control-center.php')
        for token in (
            'Windows Stable / رسمی','Windows Beta فعال','انتشار رسمی Windows','توقف Beta',
            'windows_auto_update_stable','windows_auto_update_beta','windows_minimum_version_stable',
            'windows_minimum_version_beta','windows_site_channel','همگام‌سازی Windows همین حالا'
        ):
            self.assertIn(token, cc)

    def test_windows_update_api_is_control_plane_authoritative(self):
        api=text('bluevpn-manager/includes/class-bluevpn-api.php')
        self.assertIn("['/windows/update','GET','windows_update']", api)
        self.assertIn("BlueVPN_Windows_Release_Manager::release_for_customer", api)
        self.assertIn("'release_channel'=>$channel", api)
        self.assertIn("'beta_tester'=>(bool)", api)
        self.assertIn("'sha256'=>(string)", api)
        self.assertIn("'force_update'=>$force", api)

    def test_windows_client_uses_panel_not_github_for_app_channel(self):
        updater=text('bluevpn-windows/Services/AppUpdateService.cs')
        api=text('bluevpn-windows/Services/BlueVpnApiClient.cs')
        settings=json.loads(text('bluevpn-windows/appsettings.json'))
        self.assertIn('GetWindowsUpdateAsync', updater)
        self.assertIn('GetWindowsUpdateAsync', api)
        self.assertNotIn('GetReleasesAsync(_settings.WindowsUpdateRepository', updater)
        self.assertEqual(settings['windows_channel'],'panel-managed')
        self.assertEqual(settings['windows_update_path'],'/wp-json/bluevpn/v1/windows/update')
        self.assertIn('if (candidate.ForceUpdate)', text('bluevpn-windows/MainWindow.xaml.cs'))
        self.assertIn('if (!candidate.AutoUpdate)', text('bluevpn-windows/MainWindow.xaml.cs'))
        self.assertIn('userInitiated', text('bluevpn-windows/MainWindow.xaml.cs'))

    def test_new_builds_always_enter_beta_before_panel_promotion(self):
        wf=text('.github/workflows/build-windows.yml')
        self.assertIn('WINDOWS_CHANNEL="beta"', wf)
        self.assertIn('BlueVPN-Windows-Channel-Authority: wordpress-manager', wf)
        self.assertIn('--draft --prerelease --latest=false', wf)
        self.assertIn('-F draft=false -F prerelease=true', wf)
        mgr=text('bluevpn-manager/includes/class-bluevpn-windows-release-manager.php')
        self.assertIn(":'beta';", mgr)

    def test_site_uses_panel_release_channel_when_manager_is_active(self):
        helper=text('bluevpn-site/inc/helpers.php')
        self.assertIn("BlueVPN_Windows_Release_Manager::public_site_release()", helper)
        self.assertIn("bluevpn_manager_release_channels", helper)

if __name__ == '__main__': unittest.main()
