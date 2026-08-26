import json, unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
class IOSAndroidParity560Tests(unittest.TestCase):
    def text(self,path): return (ROOT/path).read_text(encoding='utf-8')
    def test_ios_targets_version_and_tunnel_contract(self):
        version=json.loads(self.text('version.json')); project=self.text('bluevpn-ios/project.yml')
        self.assertEqual(version['components']['ios'],'5.10.1')
        self.assertIn('MARKETING_VERSION: 5.10.1',project); self.assertIn('CURRENT_PROJECT_VERSION: 51001',project)
        self.assertIn('packet-tunnel-provider',project); self.assertIn('BlueVPNTunnel',project)
        self.assertIn('PRODUCT_BUNDLE_IDENTIFIER: ir.blluepanel.bluevpn\n',project)
        self.assertIn('PRODUCT_BUNDLE_IDENTIFIER: ir.blluepanel.bluevpn.tunnel',project)
    def test_android_home_and_location_information_architecture_is_present(self):
        home=self.text('bluevpn-ios/BlueVPNApp/HomeView.swift'); locations=self.text('bluevpn-ios/BlueVPNApp/LocationsView.swift')
        for marker in ('BlueVPN','آماده اتصال','انتخاب خودکار','دانلود','مدت اتصال','آپلود','حجم باقی‌مانده','زمان باقی‌مانده'): self.assertIn(marker,home)
        for marker in ('مکان‌ها','تازه‌سازی','علاقه‌مندی','جستجوی کشور','آماده اتصال'): self.assertIn(marker,locations)
    def test_tunnel_is_ipv4_first_and_uses_public_packet_bridge(self):
        tunnel=self.text('bluevpn-ios/PacketTunnel/PacketTunnelProvider.swift')
        self.assertIn('settings.ipv6Settings=nil',tunnel); self.assertIn('settings.mtu=1361',tunnel)
        self.assertIn('import SwiftyXrayKit',tunnel); self.assertIn('XrayBridge(packetFlow:packetFlow)',tunnel)
        self.assertNotIn('value(forKey:',tunnel)

if __name__=='__main__': unittest.main()
