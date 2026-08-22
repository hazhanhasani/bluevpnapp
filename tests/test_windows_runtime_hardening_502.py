import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def text(rel):
    return (ROOT / rel).read_text(encoding='utf-8')

def test_release_is_502_and_codes_match():
    rel=json.loads(text('release.json')); app=json.loads(text('branding/app.json'))
    assert rel['version']=='5.1.3' and rel['version_code']==50103
    assert app['version_name']=='5.1.3' and app['version_code']==50103
    assert rel['windows_version']=='5.1.3' and rel['windows_version_code']==50103

def test_subscription_is_https_and_uses_no_bearer_raw_client():
    s=text('bluevpn-windows/Services/BlueVpnApiClient.cs')
    assert 'if (!string.Equals(target.Scheme, Uri.UriSchemeHttps' in s
    assert '_rawDirectHttp' in s and '_rawProxyHttp' in s
    assert 'Never attach the BlueVPN bearer token' in s
    assert 'AllowAutoRedirect = allowAutoRedirect' in s

def test_windows_update_blocks_downgrade_and_verifies_signature():
    s=text('bluevpn-windows/Services/AppUpdateService.cs')
    assert 'latestVersion <= currentVersion' in s
    assert 'VerifyAuthenticode(path, "BlueVPN")' in s
    assert 'LaunchInstaller' in s

def test_runtime_has_manifest_integrity_gate():
    u=text('bluevpn-windows/Services/RuntimeUpdateService.cs')
    l=text('bluevpn-windows/Services/RuntimeLocator.cs')
    assert '.manifest.json' in u and 'HashFile' in u
    assert 'IsValidatedRuntime' in l and '.manifest.json' in l
    assert 'SHA256.HashData(stream)' in l

def test_tunnel_verifier_binds_to_configured_tun_and_checks_ipv6_bypass():
    s=text('bluevpn-windows/Services/SystemTunnelVerifier.cs')
    c=text('bluevpn-windows/Services/ConnectionOrchestrator.cs')
    assert 'FindTunnelAdapter(string expectedName)' in s
    assert 'string.Equals(nic.Name, expectedName' in s
    assert '$v6bypass' in s and 'v6safe=' in s
    assert '_settings.Tun.Name' in c

def test_logout_failure_is_not_silently_successful():
    s=text('bluevpn-windows/MainWindow.xaml.cs')
    assert 'خروج از حساب روی سرور انجام نشد' in s
    api=text('bluevpn-windows/Services/BlueVpnApiClient.cs')
    assert 'ClearLocalSession();\n    }' in api and 'finally' not in api[api.index('public async Task LogoutAsync'):api.index('public void ClearLocalSession')]
    assert 'return;' in s[s.index('private async void Logout_Click'):s.index('private async void Logout_Click')+1800]

def test_windows_login_session_survives_normal_app_restart_securely():
    api=text('bluevpn-windows/Services/BlueVpnApiClient.cs')
    store=text('bluevpn-windows/Services/WindowsSessionStore.cs')
    ui=text('bluevpn-windows/MainWindow.xaml.cs')
    assert 'WindowsSessionStore.Load()' in api
    assert 'WindowsSessionStore.Save(_token, _cachedAccount)' in api
    assert 'WindowsSessionStore.Delete()' in api
    assert 'CryptProtectData' in store and 'CryptUnprotectData' in store
    assert 'CryptProtectUiForbidden' in store
    assert 'LocalApplicationData' in store and 'session.dat' in store
    assert 'DeviceIdentity.GetOrCreate()' in store
    assert '_api.CachedAccount' in ui
    assert 'RestoreAccountSessionSafeAsync()' in ui
    closing=ui[ui.index('private void MainWindow_Closing'):ui.index('private async void MaintenanceTimer_Tick')]
    assert 'ClearLocalSession' not in closing
