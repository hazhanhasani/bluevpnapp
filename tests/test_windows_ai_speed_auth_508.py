import json
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
def text(path): return (ROOT/path).read_text(encoding='utf-8')

class WindowsAiSpeedAuth508Tests(unittest.TestCase):
    def test_release_is_508(self):
        r=json.loads(text('release.json'))
        self.assertEqual(r['version'],'5.3.1')
        self.assertEqual(r['version_code'],50301)

    def test_windows_blueai_is_real_closed_loop(self):
        ai=text('bluevpn-windows/Services/WindowsBlueAiService.cs')
        api=text('bluevpn-windows/Services/BlueVpnApiClient.cs')
        orchestrator=text('bluevpn-windows/Services/ConnectionOrchestrator.cs')
        self.assertIn('RefreshRecommendationsAsync',ai)
        self.assertIn('Preselect(',ai)
        self.assertIn('Reorder(',ai)
        self.assertIn('StartConnectedSession',ai)
        self.assertIn('RunHeartbeatLoopAsync',ai)
        self.assertIn('verification_source = measurement.Source',ai)
        self.assertIn('event_type = "heartbeat"',ai)
        self.assertIn('RecordFailure',ai)
        self.assertIn('GetAiRecommendationsAsync',api)
        self.assertIn('PostAiEventAsync',api)
        self.assertIn('_ai.StartConnectedSession',orchestrator)
        self.assertIn('_ai.RecordFailure',orchestrator)
        self.assertIn('public string AiStatus => _ai.Status',orchestrator)

    def test_connection_path_is_bounded_and_parallel(self):
        o=text('bluevpn-windows/Services/ConnectionOrchestrator.cs')
        selector=text('bluevpn-windows/Services/EndpointSelector.cs')
        xray=text('bluevpn-windows/Services/XrayProcessController.cs')
        verifier=text('bluevpn-windows/Services/SystemTunnelVerifier.cs')
        self.assertIn('var baselineTask = ConnectivityProbe.CaptureBaselineAsync',o)
        self.assertIn('var mobileTask = LoadMobilePolicySafeAsync',o)
        self.assertIn('_ai.Preselect(endpoints, 16)',o)
        self.assertIn('Take(4)',o)
        self.assertIn('ProbeOnceAsync(host, port, 900',selector)
        self.assertIn('TimeSpan.FromMilliseconds(timeoutMs)',selector)
        self.assertIn('Task.Delay(350, ct)',xray)
        self.assertIn('TimeSpan.FromSeconds(9)',verifier)

    def test_android_core_verification_uses_short_http_then_socks_budget(self):
        home=text('android-source/BlueVpnHomeActivity.kt')
        self.assertIn('val httpPort = SettingsManager.getHttpPort()',home)
        self.assertIn('val socksPort = SettingsManager.getSocksPort()',home)
        self.assertIn('3_200L else 2_200L',home)
        self.assertIn('round < 2',home)
        self.assertNotIn('6_500L',home)

    def test_tun_is_strict_and_ipv6_safe(self):
        tun=text('bluevpn-windows/Services/V2RayNTunConfigBuilder.cs')
        warp=text('bluevpn-windows/Services/SingBoxWarpConfigBuilder.cs')
        verifier=text('bluevpn-windows/Services/SystemTunnelVerifier.cs')
        self.assertIn('strict_route = true',tun)
        self.assertIn('strict_route = true',warp)
        self.assertIn('route.Ipv6Safe && ipChanged',verifier)
        self.assertIn('مسیر IPv6 فیزیکی هنوز خارج از BlueVPN فعال است',verifier)

    def test_system_proxy_restore_keeps_backup_until_success(self):
        proxy=text('bluevpn-windows/Services/WindowsSystemProxyController.cs')
        self.assertIn('if (restored && deleteAfterSuccess)',proxy)
        self.assertIn('Enumerable.Range(16, 16)',proxy)
        self.assertNotIn('172.2*',proxy)
        self.assertIn('File.Move(temp, _statePath, overwrite: true)',proxy)

    def test_auth_ui_uses_bluevpn_light_palette(self):
        xaml=text('bluevpn-windows/MainWindow.xaml')
        code=text('bluevpn-windows/MainWindow.xaml.cs')
        auth=xaml[xaml.index('<!-- Account drawer -->'):xaml.index('<!-- Settings/menu drawer -->')]
        self.assertIn('FontFamily="Segoe UI"',xaml)
        self.assertIn('Background="{DynamicResource BlueVpnSurface}" CornerRadius="22"',auth)
        self.assertIn('Background="{DynamicResource BlueVpnBlue}" Foreground="White"',auth)
        self.assertNotIn('#FF030405',auth)
        self.assertNotIn('#FFFF8A1F',auth)
        self.assertIn('BlueVpnSurfaceStrong',code)
        self.assertIn('BlueVpnBlue2',code)

    def test_system_proxy_fallback_is_not_labeled_full_tun(self):
        ui=text('bluevpn-windows/MainWindow.xaml.cs')
        self.assertIn('compatibilityProxy', ui)
        self.assertIn('Windows System Proxy تأیید شد', ui)
        self.assertIn('اتصال سازگار تأیید شد', ui)
        self.assertIn('VPN سراسری تأیید شد', ui)

    def test_smoke_generator_is_excluded_from_wpf_compile_items(self):
        project=text('bluevpn-windows/BlueVPN.Windows.csproj')
        self.assertIn('<Compile Remove="SmokeConfigGenerator\\**\\*.cs" />', project)

    def test_ci_checks_real_builder_generated_singbox_json(self):
        workflow=text('.github/workflows/build-windows.yml')
        generator=text('bluevpn-windows/SmokeConfigGenerator/Program.cs')
        self.assertIn('Validate generated sing-box configs from Windows builders',workflow)
        self.assertIn('BlueVPN.Windows.SmokeConfigGenerator.csproj',workflow)
        self.assertIn('singbox-v2rayn-generated.json',workflow)
        self.assertIn('V2RayNTunConfigBuilder.Build',generator)
        self.assertIn('SingBoxWarpConfigBuilder.Build',generator)

if __name__=='__main__': unittest.main()
