#!/usr/bin/env bash
set -euo pipefail
if [[ ${EUID:-$(id -u)} -ne 0 ]]; then echo "Run as root" >&2; exit 1; fi
install -d -m 0755 /opt/bluevpn-gateway /etc/bluevpn-gateway /var/lib/bluevpn-gateway
install -m 0755 "$(dirname "$0")/agent.py" /opt/bluevpn-gateway/agent.py
install -m 0644 "$(dirname "$0")/bluevpn-gateway.service" /etc/systemd/system/bluevpn-gateway.service
if [[ ! -f /etc/bluevpn-gateway/agent.json ]]; then install -m 0600 "$(dirname "$0")/agent.example.json" /etc/bluevpn-gateway/agent.json; fi
systemctl daemon-reload
echo "Edit /etc/bluevpn-gateway/agent.json, install official Xray + sing-box, configure TLS files, then: systemctl enable --now bluevpn-gateway"
