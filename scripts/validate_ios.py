from pathlib import Path
import json, re

root=Path(__file__).resolve().parents[1]; ios=root/'bluevpn-ios'; version=json.loads((root/'version.json').read_text())
required=['project.yml','BlueVPNApp/BlueVPNApp.swift','BlueVPNApp/HomeView.swift','BlueVPNApp/LocationsView.swift','BlueVPNApp/VPNManager.swift','BlueVPNApp/SecureSession.swift','PacketTunnel/PacketTunnelProvider.swift','PacketTunnel/BlueRuntimeContract.swift']
for item in required:
    assert (ios/item).is_file(), f'iOS file missing: {item}'
project=(ios/'project.yml').read_text(); tunnel=(ios/'PacketTunnel/PacketTunnelProvider.swift').read_text(); home=(ios/'BlueVPNApp/HomeView.swift').read_text()
assert f'MARKETING_VERSION: {version["version"]}' in project
assert f'CURRENT_PROJECT_VERSION: {version["version_code"]}' in project
assert 'packet-tunnel-provider' in project and 'NETunnelProviderManager' in (ios/'BlueVPNApp/VPNManager.swift').read_text()
bundle_ids=re.findall(r'PRODUCT_BUNDLE_IDENTIFIER:\s*([^\s#]+)',project)
assert bundle_ids == ['ir.blluepanel.bluevpn','ir.blluepanel.bluevpn.tunnel'], f'iOS bundle IDs drifted: {bundle_ids}'
assert bundle_ids[1].startswith(bundle_ids[0]+'.'), 'Packet Tunnel bundle ID must be prefixed by parent app bundle ID'
assert 'settings.ipv6Settings=nil' in tunnel and 'settings.mtu=1361' in tunnel
for text in ['BlueVPN','آماده اتصال','انتخاب خودکار','دانلود','مدت اتصال','آپلود']:
    assert text in home, f'Android parity marker missing: {text}'
assert 'runtimeNotEmbedded' in tunnel and 'Fail closed' in tunnel
api=(ios/'BlueVPNApp/APIClient.swift').read_text();store=(ios/'BlueVPNApp/BlueVPNStore.swift').read_text();secure=(ios/'BlueVPNApp/SecureSession.swift').read_text()
assert '/api/v1/mobile/config' in store and 'ios/bootstrap' not in store
for route in ['/api/v1/auth/login','/api/v1/account','/api/v1/plans']:
    assert route in store, f'iOS control-plane route missing: {route}'
assert 'kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly' in secure and 'Authorization' in api
assert 'BlueRuntimeContract.embeddedRuntimeAvailable' in tunnel
print(f'BlueVPN iOS validation PASS — {version["version"]} / SwiftUI + PacketTunnel')
