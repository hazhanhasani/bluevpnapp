from __future__ import annotations
import argparse, json
from pathlib import Path
import hashlib, plistlib

root=Path(__file__).resolve().parents[1]
p=argparse.ArgumentParser();p.add_argument('--require-embedded',action='store_true');p.add_argument('--framework',default='bluevpn-ios/Frameworks/BlueXrayCore.xcframework');args=p.parse_args()
framework=root/args.framework
contract=(root/'bluevpn-ios/PacketTunnel/BlueRuntimeContract.swift').read_text()
lock=json.loads((root/'bluevpn-ios/runtime-lock.json').read_text())
assert 'frameworkName = "BlueXrayCore.xcframework"' in contract and 'requiredABI = 1' in contract
assert len(lock['libxray_commit'])==40 and len(lock['xray_core_commit'])==40 and lock['api_version']==2
if args.require_embedded:
    if not framework.is_dir(): raise SystemExit(f'signed Xray XCFramework missing: {framework}')
    manifest=framework/'BlueVPNRuntime.json'
    if not manifest.is_file(): raise SystemExit('BlueVPNRuntime.json missing from XCFramework')
    data=json.loads(manifest.read_text())
    if data.get('engine')!='xray' or data.get('abi')!=1 or data.get('api_version')!=2: raise SystemExit(f'invalid runtime manifest: {manifest}')
    if data.get('libxray_commit')!=lock['libxray_commit'] or data.get('xray_core_commit')!=lock['xray_core_commit']: raise SystemExit('runtime source lock mismatch')
    info=plistlib.loads((framework/'Info.plist').read_bytes())
    libraries=info.get('AvailableLibraries') or []
    device=any(x.get('SupportedPlatform')=='ios' and x.get('SupportedPlatformVariant') is None and 'arm64' in x.get('SupportedArchitectures',[]) for x in libraries)
    simulator=any(x.get('SupportedPlatform')=='ios' and x.get('SupportedPlatformVariant')=='simulator' and {'arm64','x86_64'}.issubset(set(x.get('SupportedArchitectures',[]))) for x in libraries)
    if not device or not simulator: raise SystemExit('XCFramework must contain iOS arm64 and simulator arm64/x86_64 slices')
print('BlueVPN iOS Xray runtime contract PASS' + (' (embedded)' if args.require_embedded else ' (fail-closed development mode)'))
