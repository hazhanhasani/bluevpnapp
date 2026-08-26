#!/usr/bin/env python3
from pathlib import Path

path = Path('android-source/BlueVpnHomeActivity.kt')
text = path.read_text(encoding='utf-8')
needle = 'import com.v2ray.ang.bluevpn.BlueVpnRouteIntelligence\nimport com.v2ray.ang.bluevpn.BlueVpnRuntimeGate\n'
replacement = 'import com.v2ray.ang.bluevpn.BlueVpnRouteIntelligence\nimport com.v2ray.ang.bluevpn.BlueVpnRuntimeAudit\nimport com.v2ray.ang.bluevpn.BlueVpnRuntimeGate\n'
if text.count(needle) != 1:
    raise SystemExit(f'expected one import anchor, found {text.count(needle)}')
if 'import com.v2ray.ang.bluevpn.BlueVpnRuntimeAudit\n' in text:
    raise SystemExit('BlueVpnRuntimeAudit import already present')
path.write_text(text.replace(needle, replacement, 1), encoding='utf-8')
print('Applied BlueVpnRuntimeAudit import repair')
