from __future__ import annotations
import argparse, json
from pathlib import Path
import hashlib, plistlib

root=Path(__file__).resolve().parents[1]
p=argparse.ArgumentParser();p.add_argument('--require-embedded',action='store_true');p.add_argument('--framework',default='bluevpn-ios/Frameworks/BlueXrayCore.xcframework');args=p.parse_args()
framework=root/args.framework
lock=json.loads((root/'bluevpn-ios/runtime-lock.json').read_text())
assert len(lock['libxray_commit'])==40 and len(lock['xray_core_commit'])==40 and lock['api_version']==2
assert len(lock['bridge_commit'])==40 and len(lock['binary_checksum'])==64
project=(root/'bluevpn-ios/project.yml').read_text()
assert lock['bridge_commit'] in project and 'SwiftyXrayKit' in project
if args.require_embedded:
    raise SystemExit('--require-embedded is obsolete: SwiftPM resolves and checksum-verifies the pinned binary during xcodebuild')
print('BlueVPN iOS Xray runtime contract PASS (pinned SwiftPM socketpair bridge)')
