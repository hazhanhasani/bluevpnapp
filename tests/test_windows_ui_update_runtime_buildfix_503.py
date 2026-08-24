from pathlib import Path
import unittest
ROOT=Path(__file__).resolve().parents[1]
def text(p): return (ROOT/p).read_text(encoding='utf-8')
class WindowsUiUpdateRuntimeBuildfix503(unittest.TestCase):
    def test_ui_is_scrollable_and_power_is_vector(self):
        x=text('bluevpn-windows/MainWindow.xaml')
        self.assertIn('x:Name="HomeFitViewbox"',x)
        self.assertIn('x:Name="PowerArc"',x)
        self.assertIn('x:Name="PowerStem"',x)
        self.assertNotIn('Content="⏻"',x)
    def test_timeout_is_never_shown_raw(self):
        ui=text('bluevpn-windows/MainWindow.xaml.cs')
        probe=text('bluevpn-windows/Services/ConnectivityProbe.cs')
        self.assertIn('HttpClient.Timeout',ui)
        self.assertIn('پاسخ مسیر اتصال دیر رسید',ui)
        self.assertIn('FallbackTraceUrls',probe)
        self.assertIn('FriendlyProbeError',probe)
    def test_update_manual_click_is_not_silently_dropped(self):
        ui=text('bluevpn-windows/MainWindow.xaml.cs')
        self.assertIn('await _updateGate.WaitAsync(_lifetimeCts.Token)',ui)
        self.assertIn('UpdateProgressBar',ui)
        self.assertIn('UpdateStatusText',ui)
        self.assertIn('LaunchInstaller(installer, candidate.Digest)',ui)
    def test_v2rayn_bundle_is_complete_without_shipping_duplicate_upstream_tree(self):
        r=text('bluevpn-windows/Services/RuntimeLocator.cs')
        wf=text('.github/workflows/build-windows.yml')
        for n in ('geoip.dat','geosite.dat','v2rayN.exe','xray.exe','sing-box.exe','wintun.dll'):
            self.assertIn(n,r if n in ('geoip.dat','geosite.dat') else r+wf)
        self.assertIn('v2rayn-upstream-$env:RID',wf)
        self.assertIn("layout='minimal-complete-core'",wf)
    def test_warp_has_oblivion_style_transport_fallback(self):
        w=text('bluevpn-windows/Services/WarpConnectionController.cs')
        self.assertIn('BuildTransportOrder',w)
        self.assertIn('WARP • اسکن و اتصال MASQUE',w)
        self.assertIn('WARP • مسیر WireGuard جایگزین',w)
        self.assertIn('BuildAetherArgs(policy, socksPort, useMasque)',w)
if __name__=='__main__': unittest.main()
