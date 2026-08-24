from __future__ import annotations
import argparse, json
from pathlib import Path

root=Path(__file__).resolve().parents[1]
p=argparse.ArgumentParser();p.add_argument('--require-embedded',action='store_true');p.add_argument('--framework',default='bluevpn-ios/Frameworks/BlueXrayCore.xcframework');args=p.parse_args()
framework=root/args.framework
contract=(root/'bluevpn-ios/PacketTunnel/BlueRuntimeContract.swift').read_text()
assert 'frameworkName = "BlueXrayCore.framework"' in contract and 'requiredABI = 1' in contract
if args.require_embedded:
    if not framework.is_dir(): raise SystemExit(f'signed Xray XCFramework missing: {framework}')
    manifests=list(framework.rglob('BlueVPNRuntime.json'))
    if not manifests: raise SystemExit('BlueVPNRuntime.json missing from XCFramework')
    for manifest in manifests:
        data=json.loads(manifest.read_text())
        if data.get('engine')!='xray' or data.get('abi')!=1: raise SystemExit(f'invalid runtime manifest: {manifest}')
print('BlueVPN iOS Xray runtime contract PASS' + (' (embedded)' if args.require_embedded else ' (fail-closed development mode)'))
