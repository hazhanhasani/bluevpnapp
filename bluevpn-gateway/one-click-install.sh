#!/usr/bin/env bash
set -euo pipefail

ENROLL_URL="${1:-}"
NODE_ID="${2:-}"
ENROLLMENT_TOKEN="${3:-}"
AGENT_VERSION="6.1.4"

if [[ ${EUID:-$(id -u)} -ne 0 ]]; then
  echo "BlueVPN Gateway installer must run as root." >&2
  exit 1
fi
if [[ -z "$ENROLL_URL" || ! "$NODE_ID" =~ ^[0-9]+$ || -z "$ENROLLMENT_TOKEN" ]]; then
  echo "Usage: one-click-install.sh <enroll_url> <node_id> <one_time_token>" >&2
  exit 1
fi
for cmd in curl python3 systemctl install; do
  command -v "$cmd" >/dev/null 2>&1 || { echo "Required command missing: $cmd" >&2; exit 1; }
done

umask 077
TMP="$(mktemp -d -t bluevpn-gateway-enroll.XXXXXX)"
cleanup(){ rm -rf "$TMP"; }
trap cleanup EXIT
MACHINE_ID="$(cat /etc/machine-id 2>/dev/null || hostname 2>/dev/null || echo unknown)"
PAYLOAD="$(python3 - "$NODE_ID" "$ENROLLMENT_TOKEN" "$AGENT_VERSION" "$MACHINE_ID" <<'PY'
import json,sys,hashlib
node=int(sys.argv[1]); token=sys.argv[2]; version=sys.argv[3]; machine=sys.argv[4]
print(json.dumps({"node_id":node,"enrollment_token":token,"agent_version":version,"machine_id_hash":hashlib.sha256(machine.encode()).hexdigest()}))
PY
)"
HTTP_CODE="$(curl --silent --show-error --location --connect-timeout 20 --max-time 90 \
  -H 'Content-Type: application/json' -H "User-Agent: BlueVPN-Gateway-Installer/${AGENT_VERSION}" \
  --data "$PAYLOAD" --output "$TMP/enroll.json" --write-out '%{http_code}' "$ENROLL_URL" || true)"
if [[ "$HTTP_CODE" != "200" ]]; then
  echo "Gateway enrollment failed (HTTP $HTTP_CODE)." >&2
  python3 - "$TMP/enroll.json" <<'PY' >&2 || true
import json,sys
try:
 d=json.load(open(sys.argv[1])); print((d.get('detail') or {}).get('message') or d)
except Exception: pass
PY
  exit 1
fi

python3 - "$TMP/enroll.json" "$TMP/agent.json" "$TMP/meta" <<'PY'
import json,sys
src,cfg_path,meta_path=sys.argv[1:]
d=json.load(open(src))
if not d.get('ok') or not isinstance(d.get('config'),dict):
    raise SystemExit('Manager returned invalid enrollment payload')
with open(cfg_path,'w',encoding='utf-8') as f:
    json.dump(d['config'],f,ensure_ascii=False,indent=2); f.write('\n')
assets=d.get('assets') or {}
vals=[str(assets.get('agent') or ''),str(assets.get('service') or ''),str(d.get('public_host') or '')]
if not vals[0].startswith('http') or not vals[1].startswith('http'):
    raise SystemExit('Manager asset URLs are missing')
open(meta_path,'w',encoding='utf-8').write('\n'.join(vals))
PY
mapfile -t META < "$TMP/meta"
AGENT_URL="${META[0]}"; SERVICE_URL="${META[1]}"; PUBLIC_HOST="${META[2]}"

curl -fsSL --connect-timeout 20 --max-time 120 "$AGENT_URL" -o "$TMP/agent.py"
curl -fsSL --connect-timeout 20 --max-time 60 "$SERVICE_URL" -o "$TMP/bluevpn-gateway.service"
python3 -m py_compile "$TMP/agent.py"

install -d -m 0755 /opt/bluevpn-gateway /etc/bluevpn-gateway /var/lib/bluevpn-gateway
install -m 0755 "$TMP/agent.py" /opt/bluevpn-gateway/agent.py
install -m 0644 "$TMP/bluevpn-gateway.service" /etc/systemd/system/bluevpn-gateway.service
install -m 0600 "$TMP/agent.json" /etc/bluevpn-gateway/agent.json
systemctl daemon-reload
systemctl enable bluevpn-gateway >/dev/null

CERT="$(python3 -c 'import json;print(json.load(open("/etc/bluevpn-gateway/agent.json"))["cert_file"])')"
KEY="$(python3 -c 'import json;print(json.load(open("/etc/bluevpn-gateway/agent.json"))["key_file"])')"

if [[ (! -s "$CERT" || ! -s "$KEY") && -n "$PUBLIC_HOST" ]] && command -v certbot >/dev/null 2>&1; then
  echo "TLS certificate not found; attempting Certbot standalone issuance for $PUBLIC_HOST ..."
  systemctl stop bluevpn-gateway >/dev/null 2>&1 || true
  certbot certonly --standalone --non-interactive --agree-tos --register-unsafely-without-email -d "$PUBLIC_HOST" || true
fi

MISSING=()
[[ -x /usr/local/bin/xray ]] || MISSING+=("/usr/local/bin/xray")
[[ -s "$CERT" ]] || MISSING+=("TLS certificate: $CERT")
[[ -s "$KEY" ]] || MISSING+=("TLS private key: $KEY")
if ((${#MISSING[@]})); then
  systemctl stop bluevpn-gateway >/dev/null 2>&1 || true
  echo "Enrollment completed safely, but the Gateway was NOT started because prerequisites are missing:" >&2
  printf ' - %s\n' "${MISSING[@]}" >&2
  echo "Install the missing runtime/certificate, then run: sudo systemctl start bluevpn-gateway" >&2
  exit 2
fi

systemctl restart bluevpn-gateway
sleep 2
if systemctl is-active --quiet bluevpn-gateway; then
  echo "BlueVPN Gateway enrollment complete. Node $NODE_ID is running under Autopilot."
else
  echo "Enrollment completed but the service did not remain active. Run: journalctl -u bluevpn-gateway -n 100" >&2
  exit 3
fi
