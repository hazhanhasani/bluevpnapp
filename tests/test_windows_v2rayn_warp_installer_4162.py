import json
import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
def read(p): return (ROOT/p).read_text(encoding='utf-8')

class WindowsV2rayNWarpInstaller4162(unittest.TestCase):
    def test_release_contract(self):
        r=json.loads(read('release.json'))
        self.assertEqual(r['version'],'5.1.4')
        self.assertEqual(r['version_code'],50104)
        self.assertEqual(r['windows']['runtime_base'],'v2rayN')
        self.assertEqual(r['windows']['artifact'],'inno_setup_exe')
        self.assertTrue(r['windows']['warp_x64'])
        self.assertFalse(r['windows']['warp_arm64'])

    def test_no_false_connected_process_gate(self):
        c=read('bluevpn-windows/Services/ConnectionOrchestrator.cs')
        self.assertNotIn('public bool IsConnected => _xray.IsRunning',c)
        self.assertIn('_verifiedConnected',c)
        self.assertIn('SystemTunnelVerifier.VerifyAsync',c)

    def test_runtime_configs_are_smoke_checked_by_real_cores(self):
        wf=read('.github/workflows/build-windows.yml')
        self.assertIn('xray-local-proxy-smoke.json',wf)
        self.assertIn('run -test -config',wf)
        self.assertIn('Validate generated sing-box configs from Windows builders',wf)
        self.assertIn('SmokeConfigGenerator',wf)
        self.assertIn('singbox-v2rayn-generated.json',wf)
        self.assertIn('singbox-warp-generated.json',wf)
        self.assertIn('check -c',wf)

    def test_runtime_bootstrap_avoids_rest_api_rate_limits_and_keeps_integrity(self):
        wf=read('.github/workflows/build-windows.yml')
        self.assertNotIn('api.github.com/repos/2dust/v2rayN/releases', wf)
        self.assertNotIn('api.github.com/repos/CluvexStudio/Aether/releases', wf)
        self.assertIn('v2rayn_sha256: 20fc30526fe5a0164ae7b9a1f8b807bf724e87759ad0fb642bd008276a4239e7', wf)
        self.assertIn('v2rayn_sha256: 075cf40437ca9617496201ae35ea8ff4e5835c07b0f29dae7aa01415064f35a0', wf)
        self.assertIn('EXPECTED_SHA256: ${{ matrix.v2rayn_sha256 }}', wf)
        self.assertIn('releases/download/$env:V2RAYN_VERSION/$env:ASSET', wf)
        self.assertIn('$assetName.sha256', wf)
        self.assertIn('Get-FileHash $zip -Algorithm SHA256', wf)
        self.assertGreaterEqual(wf.count('Download-WithRetry'), 5)
        self.assertIn('@(0,403,408,425,429,500,502,503,504)', wf)

    def test_warp_is_full_tun_not_system_proxy_only(self):
        c=read('bluevpn-windows/Services/SingBoxWarpConfigBuilder.cs')
        self.assertIn('type = "tun"',c)
        self.assertIn('auto_route = true',c)
        self.assertIn('strict_route = true',c)
        self.assertIn('final = "warp-socks"',c)
        self.assertIn('aether.exe',c)

    def test_runtime_updates_are_stable_and_validated(self):
        u=read('bluevpn-windows/Services/RuntimeUpdateService.cs')
        self.assertIn('pre.GetBoolean()',u)
        self.assertIn('.validated',u)
        self.assertIn('DownloadVerifiedAsync',u)
        self.assertIn('sing-box.exe',u)
        gh=read('bluevpn-windows/Services/GitHubReleaseClient.cs')
        self.assertIn('digest.StartsWith("sha256:"',gh)
        self.assertIn('SHA256 معتبر ارائه نکرد',gh)

    def test_ads_reuse_wordpress_control_plane(self):
        m=read('bluevpn-windows/Models/WindowsRuntimeModels.cs')
        api=read('bluevpn-windows/Services/BlueVpnApiClient.cs')
        self.assertIn('advertising',m)
        self.assertIn('free_story_ads',m)
        self.assertIn('MobileConfigPath',api)

    def test_warp_port_and_periodic_updates_are_policy_driven(self):
        w=read('bluevpn-windows/Services/WarpConnectionController.cs')
        ui=read('bluevpn-windows/MainWindow.xaml.cs')
        self.assertIn('_settings.Warp.SocksPort',w)
        self.assertIn('SingBoxWarpConfigBuilder.Build(_settings, socksPort)',w)
        self.assertIn('TimeSpan.FromHours(4)',ui)
        self.assertIn('MaintenanceTimer_Tick',ui)

    def test_installer_launch_keeps_elevation_and_xray_has_proxy_fallback(self):
        installer=read('bluevpn-windows/installer/BlueVPN.iss')
        xray=read('bluevpn-windows/Services/XrayConfigBuilder.cs')
        ctrl=read('bluevpn-windows/Services/XrayProcessController.cs')
        proxy=read('bluevpn-windows/Services/WindowsSystemProxyController.cs')
        self.assertNotIn('runascurrentuser',installer)
        self.assertIn('postinstall skipifsilent shellexec',installer)
        self.assertIn('LocalHttpPort = 20809',xray)
        self.assertIn('FallbackToSystemProxyAsync',ctrl)
        self.assertIn('ProxyEnable',proxy)
        self.assertIn('RecoverStaleState',proxy)

    def test_singbox_113_configs_use_route_actions_not_legacy_inbound_fields(self):
        for rel in [
            'bluevpn-windows/runtime-config/singbox-v2rayn-tun-smoke.json',
            'bluevpn-windows/runtime-config/singbox-warp-smoke.json',
        ]:
            cfg=json.loads(read(rel))
            self.assertNotIn('sniff', cfg['inbounds'][0])
            self.assertEqual(cfg['route']['rules'][0].get('action'), 'sniff')
            self.assertFalse(any(o.get('type') == 'block' for o in cfg.get('outbounds', [])))
        for rel in [
            'bluevpn-windows/Services/V2RayNTunConfigBuilder.cs',
            'bluevpn-windows/Services/SingBoxWarpConfigBuilder.cs',
        ]:
            code=read(rel)
            self.assertNotIn('sniff = true', code)
            self.assertIn('action = "sniff"', code)
            self.assertNotIn('type = "block"', code)

if __name__ == '__main__': unittest.main()
