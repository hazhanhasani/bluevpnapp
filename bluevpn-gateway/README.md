# BlueVPN Gateway Metering — 5.1.4

This directory contains the first-party Linux gateway agent for `gateway_metered` paid plans.

## Data path

`Windows / Android -> BlueVPN Gateway (VLESS/TLS) -> upstream provider/manual configs -> Internet`

The app receives only the BlueVPN gateway VLESS credential. Original Marzban/PasarGuard/GuardCore/manual subscription URLs remain in WordPress and on the gateway control plane.

## Metering

The agent enables Xray per-user stats (`statsUserUplink` and `statsUserDownlink`), calls `xray api statsquery ... -reset=true`, and posts byte deltas to the HMAC-protected Manager endpoint. Manager stores an idempotent event ledger and atomically increments `customers.used_traffic_bytes`. When quota is reached, the session is removed from subsequent gateway configs and the customer becomes `limited`.

## Install

1. Install the official Xray binary at `/usr/local/bin/xray` (or change `xray_path`).
2. Create a DNS name for the gateway and a valid TLS certificate/key.
3. In WordPress: **BlueVPN -> Gateway Metering**, create a node and copy its one-time `NODE_ID` / `NODE_SECRET`.
4. Run `sudo ./install.sh` on the gateway VPS.
5. Edit `/etc/bluevpn-gateway/agent.json`; set Manager URL, node credentials, certificate paths, and port.
6. `sudo systemctl enable --now bluevpn-gateway`
7. Check `systemctl status bluevpn-gateway` and the WordPress Gateway page for heartbeat.

## Supported upstreams in 5.1.4

The gateway parser supports VLESS, VMess, Trojan, and Shadowsocks URI sources. Hysteria2 and TUIC entries are kept in BlueVPN's unified subscription snapshot but intentionally skipped by this Xray gateway agent until a later transport extension adds them safely.

## Security

Node secrets are HMAC keys, encrypted at rest in WordPress and shown once on creation/rotation. Keep `agent.json` mode `0600`, use HTTPS for Manager, and do not expose Xray's local API (`127.0.0.1:10085`) publicly.
