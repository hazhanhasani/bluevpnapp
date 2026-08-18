# BlueVPN Windows — Phase 1

Windows client for the BlueVPN control plane.

## Implemented

- WPF UI on .NET 10 LTS.
- Email/password login and register using the existing BlueVPN WordPress API.
- SMS OTP login using the existing BlueVPN OTP API.
- Existing account / plan / expiry / traffic status.
- Premium uses the account's existing `subscription.url`.
- Free uses the existing `/free/curated` pool.
- Parses VLESS, VMess, Trojan and Shadowsocks URIs.
- Bounded TCP endpoint race before connection.
- Xray TUN inbound on Windows via Wintun.
- IPv4 + IPv6 default routing through TUN with `autoSystemRoutingTable` and `autoOutboundsInterface`.
- Post-connect internet verification before the UI reports Connected.
- GitHub Actions builds self-contained `win-x64` and `win-arm64` portable ZIPs and bundles the pinned official Xray Windows runtime.

## Phase-1 boundary

The Android Free engine remains Aether/WARP-first. Windows Phase 1 uses the curated Xray Free Pool so a usable Windows client can ship before the WARP/Aether Windows port. Premium uses the same entitlement/subscription source as Android.

## Runtime

The GitHub workflow downloads official Xray-core `v26.7.28` Windows assets. `xray.exe`, `wintun.dll`, `geoip.dat` and `geosite.dat` are bundled under `runtime/` in the published Windows ZIP.

The application requests Administrator privileges because Windows TUN/Wintun and system route changes require elevation.
