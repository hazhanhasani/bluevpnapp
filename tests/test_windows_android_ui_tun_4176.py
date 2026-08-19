import json
import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
def read(path): return (ROOT/path).read_text(encoding='utf-8')

class WindowsAndroidUiTun4176Tests(unittest.TestCase):
    def test_release(self):
        r=json.loads(read('release.json'))
        self.assertEqual(r['version'],'4.18.0')
        self.assertEqual(r['version_code'],41800)

    def test_windows_home_matches_android_surface_order(self):
        x=read('bluevpn-windows/MainWindow.xaml')
        tokens=['SubscriptionSummaryText','StatusText','OrbHalo','ConnectButton','DownloadSpeedValue','DurationValue','UploadSpeedValue','AdCard','EndpointText','RemainingVolumeValue','RemainingTimeValue','AccountDrawer','MenuDrawer']
        last=-1
        for token in tokens[:11]:
            pos=x.find(token)
            self.assertGreater(pos,last,token)
            last=pos
        self.assertIn('#FFF6F8FC',read('bluevpn-windows/App.xaml'))
        self.assertIn('#FF356DF1',read('bluevpn-windows/App.xaml'))

    def test_v2rayn_split_core_tun_is_real_system_path(self):
        xray=read('bluevpn-windows/Services/XrayConfigBuilder.cs')
        tun=read('bluevpn-windows/Services/V2RayNTunConfigBuilder.cs')
        ctrl=read('bluevpn-windows/Services/XrayProcessController.cs')
        self.assertIn('protocol"] = "socks"',xray)
        self.assertIn('LocalSocksPort = 20808',xray)
        self.assertIn('type = "tun"',tun)
        self.assertIn('strict_route = true',tun)
        self.assertIn('process_name = new[] { "xray.exe" }',tun)
        self.assertIn('final = "xray-local"',tun)
        self.assertIn('V2RayNTunConfigBuilder.Build',ctrl)
        self.assertIn('ResolveV2RayNBundle',ctrl)
        self.assertIn('bundle.SingBoxPath',ctrl)

    def test_connected_is_fail_closed_on_route_and_ip(self):
        v=read('bluevpn-windows/Services/SystemTunnelVerifier.cs')
        self.assertIn('route.Ipv4ThroughTunnel && route.Ipv6Safe',v)
        self.assertIn('consecutive >= 2',v)
        self.assertIn('IP سیستم تغییر نکرد',v)
        self.assertIn('Get-NetRoute',v)
        self.assertIn('Get-NetIPInterface',v)

    def test_ci_smoke_checks_both_core_halves(self):
        wf=read('.github/workflows/build-windows.yml')
        self.assertIn('xray-local-proxy-smoke.json',wf)
        self.assertIn('singbox-v2rayn-tun-smoke.json',wf)
        self.assertIn('singbox-warp-smoke.json',wf)

if __name__=='__main__': unittest.main()
