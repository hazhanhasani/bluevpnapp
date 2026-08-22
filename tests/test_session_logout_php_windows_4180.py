from pathlib import Path
import json
import unittest

ROOT = Path(__file__).resolve().parents[1]

def text(rel):
    return (ROOT / rel).read_text(encoding='utf-8')

class SessionLogoutPhpWindows4180Tests(unittest.TestCase):
    def test_release_version(self):
        r=json.loads(text('release.json'))
        b=json.loads(text('branding/app.json'))
        self.assertEqual(r['version'],'5.1.7')
        self.assertEqual(r['version_code'],50107)
        self.assertEqual(b['version_name'],'5.1.7')
        self.assertEqual(b['version_code'],50107)

    def test_php_optional_warp_fields_never_read_unguarded(self):
        ads=text('bluevpn-manager/includes/class-bluevpn-ads.php')
        self.assertIn("$_POST['free_warp_scan_mode'] ?? 'turbo'", ads)
        self.assertIn("$_POST['free_warp_ip_mode'] ?? 'auto'", ads)
        self.assertNotIn("? sanitize_key((string)$_POST['free_warp_scan_mode'])", ads)
        self.assertNotIn("? sanitize_key((string)$_POST['free_warp_ip_mode'])", ads)

    def test_logout_deletes_device_sessions_and_releases_slot(self):
        auth=text('bluevpn-manager/includes/class-bluevpn-auth.php')
        self.assertIn('DELETE FROM {$sessions} WHERE customer_id=%d AND device_id=%s', auth)
        self.assertIn("'active' => 0", auth)
        self.assertIn('private static function prune_orphaned_app_devices', auth)
        self.assertIn("AND d.client_type='app' AND d.active=1", auth)
        self.assertIn('NOT EXISTS (', auth)
        self.assertIn("$data = ['active'=>0", auth)

    def test_email_and_phone_share_issue_session(self):
        api=text('bluevpn-manager/includes/class-bluevpn-api.php')
        otp=text('bluevpn-manager/includes/class-bluevpn-sms-otp.php')
        self.assertGreaterEqual(api.count('BlueVPN_Auth::issue_session('),2)
        self.assertIn('BlueVPN_Auth::issue_session($customer, $deviceId, $deviceName)',otp)

    def test_windows_logout_is_server_authoritative(self):
        api=text('bluevpn-windows/Services/BlueVpnApiClient.cs')
        ui=text('bluevpn-windows/MainWindow.xaml.cs')
        self.assertIn('public async Task LogoutAsync',api)
        self.assertIn('wp-json/bluevpn/v1/auth/logout',api)
        self.assertIn('finally',api)
        self.assertIn('ClearLocalSession();',api)
        self.assertIn('await _api.LogoutAsync(logoutCts.Token);',ui)

    def test_windows_tls_hardening_does_not_disable_certificate_validation(self):
        api=text('bluevpn-windows/Services/BlueVpnApiClient.cs')
        self.assertIn('useSystemProxy: false',api)
        self.assertIn('useSystemProxy: true',api)
        self.assertIn('CONTROL_PLANE_TLS',api)
        self.assertNotIn('ServerCertificateCustomValidationCallback',api)
        self.assertNotIn('DangerousAcceptAnyServerCertificateValidator',api)

    def test_windows_transport_retry_uses_qualified_io_exception(self):
        api=text('bluevpn-windows/Services/BlueVpnApiClient.cs')
        self.assertIn('ex is System.IO.IOException', api)
        self.assertNotIn('ex is IOException ||', api)

if __name__=='__main__':
    unittest.main()
