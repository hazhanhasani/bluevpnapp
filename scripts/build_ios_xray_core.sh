#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LOCK="$ROOT/bluevpn-ios/runtime-lock.json"
OUT="$ROOT/bluevpn-ios/Frameworks/BlueXrayCore.xcframework"
WORK="${RUNNER_TEMP:-/tmp}/bluevpn-libxray"

read_lock() {
  python3 - "$LOCK" "$1" <<'PY'
import json,sys
print(json.load(open(sys.argv[1],encoding='utf-8'))[sys.argv[2]])
PY
}

REPOSITORY="$(read_lock libxray_repository)"
LIBXRAY_COMMIT="$(read_lock libxray_commit)"
LICENSE_SHA="$(read_lock libxray_license_sha256)"
XRAY_COMMIT="$(read_lock xray_core_commit)"

rm -rf "$WORK"
git clone --filter=blob:none --no-checkout "$REPOSITORY" "$WORK"
git -C "$WORK" checkout --detach "$LIBXRAY_COMMIT"
test "$(git -C "$WORK" rev-parse HEAD)" = "$LIBXRAY_COMMIT"
test "$(shasum -a 256 "$WORK/LICENSE" | awk '{print $1}')" = "$LICENSE_SHA"
grep -q "$XRAY_COMMIT" "$WORK/go.mod"

(cd "$WORK" && python3 build/main.py apple go)
test -d "$WORK/LibXray.xcframework"
rm -rf "$OUT"
mkdir -p "$(dirname "$OUT")"
mv "$WORK/LibXray.xcframework" "$OUT"

python3 - "$LOCK" "$OUT/BlueVPNRuntime.json" "$OUT" <<'PY'
import hashlib,json,pathlib,sys
lock=json.load(open(sys.argv[1],encoding='utf-8'))
root=pathlib.Path(sys.argv[3])
h=hashlib.sha256()
for path in sorted(p for p in root.rglob('*') if p.is_file()):
    h.update(str(path.relative_to(root)).encode());h.update(b'\0');h.update(path.read_bytes())
manifest={
    'engine':'xray','abi':lock['bluevpn_abi'],'api_version':lock['api_version'],
    'module':lock['module'],'libxray_commit':lock['libxray_commit'],
    'xray_core_commit':lock['xray_core_commit'],'tree_sha256':h.hexdigest(),
}
pathlib.Path(sys.argv[2]).write_text(json.dumps(manifest,indent=2)+'\n',encoding='utf-8')
PY
cp "$OUT/BlueVPNRuntime.json" "$ROOT/bluevpn-ios/PacketTunnelResources/BlueVPNRuntime.json"

echo "BlueVPN audited iOS Xray core ready: $OUT"
