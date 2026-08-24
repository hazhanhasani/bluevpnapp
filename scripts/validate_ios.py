from pathlib import Path
import json, re

root=Path(__file__).resolve().parents[1]; ios=root/'bluevpn-ios'; version=json.loads((root/'version.json').read_text())
required=['project.yml','BlueVPNApp/BlueVPNApp.swift','BlueVPNApp/HomeView.swift','BlueVPNApp/LocationsView.swift','BlueVPNApp/VPNManager.swift','PacketTunnel/PacketTunnelProvider.swift']
for item in required:
    assert (ios/item).is_file(), f'iOS file missing: {item}'
project=(ios/'project.yml').read_text(); tunnel=(ios/'PacketTunnel/PacketTunnelProvider.swift').read_text(); home=(ios/'BlueVPNApp/HomeView.swift').read_text()
assert f'MARKETING_VERSION: {version["version"]}' in project
assert f'CURRENT_PROJECT_VERSION: {version["version_code"]}' in project
assert 'packet-tunnel-provider' in project and 'NETunnelProviderManager' in (ios/'BlueVPNApp/VPNManager.swift').read_text()
assert 'settings.ipv6Settings=nil' in tunnel and 'settings.mtu=1361' in tunnel
for text in ['BlueVPN','آماده اتصال','انتخاب خودکار','دانلود','مدت اتصال','آپلود']:
    assert text in home, f'Android parity marker missing: {text}'
assert 'runtimeNotEmbedded' in tunnel and 'Fail closed' in tunnel
print(f'BlueVPN iOS validation PASS — {version["version"]} / SwiftUI + PacketTunnel')

