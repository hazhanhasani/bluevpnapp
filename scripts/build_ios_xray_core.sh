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
XRAY_MODULE_VERSION="$(read_lock xray_core_module_version)"

rm -rf "$WORK"
git clone --filter=blob:none --no-checkout "$REPOSITORY" "$WORK"
git -C "$WORK" checkout --detach "$LIBXRAY_COMMIT"
ACTUAL_LIBXRAY_COMMIT="$(git -C "$WORK" rev-parse HEAD)"
ACTUAL_LICENSE_SHA="$(shasum -a 256 "$WORK/LICENSE" | awk '{print $1}')"
ACTUAL_XRAY_MODULE="$(cd "$WORK" && go list -m -f '{{.Version}}' github.com/xtls/xray-core)"
if [[ "$ACTUAL_LIBXRAY_COMMIT" != "$LIBXRAY_COMMIT" ]]; then
  echo "::error title=BlueXrayCore audit failed::libXray commit mismatch; expected=$LIBXRAY_COMMIT actual=$ACTUAL_LIBXRAY_COMMIT"
  exit 41
fi
if [[ "$ACTUAL_LICENSE_SHA" != "$LICENSE_SHA" ]]; then
  echo "::error title=BlueXrayCore audit failed::license SHA-256 mismatch; expected=$LICENSE_SHA actual=$ACTUAL_LICENSE_SHA"
  exit 42
fi
if [[ "$ACTUAL_XRAY_MODULE" != "$XRAY_MODULE_VERSION" || "$ACTUAL_XRAY_MODULE" != *"${XRAY_COMMIT:0:12}" ]]; then
  echo "::error title=BlueXrayCore audit failed::Xray module mismatch; expected=$XRAY_MODULE_VERSION commit=$XRAY_COMMIT actual=$ACTUAL_XRAY_MODULE"
  exit 43
fi

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
