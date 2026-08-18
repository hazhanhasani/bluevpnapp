# BlueVPN for Windows — 4.16.2

BlueVPN Windows is now an installed desktop VPN client with the BlueVPN account/UI/control plane and a runtime derived from the official v2rayN Windows distribution.

## Connection architecture

### Premium

`BlueVPN UI -> account subscription -> endpoint ranking -> v2rayN-bundled Xray -> Windows TUN -> verified system route`

CONNECTED is not based on a running process. BlueVPN captures the public IP before connection and only reports success after Windows TUN/default-route evidence exists and the public IP observed through the system networking stack has changed.

### Free x64

`Aether WARP/MASQUE -> SOCKS5 127.0.0.1:1819 -> v2rayN-bundled sing-box -> Windows TUN`

The Aether process is direct-routed to avoid a TUN loop. BlueVPN waits for Aether's SOCKS listener, then verifies system routing, public IP change, Cloudflare `warp=on/plus`, and rejects an IR WARP exit when that policy is enabled. If WARP cannot be validated, the existing curated Xray free pool is used as fallback.

### Free ARM64

Aether v1.1.1 does not ship a Windows ARM64 binary. BlueVPN therefore uses the curated Xray free pool on ARM64. Premium remains available through the v2rayN/Xray runtime.

## Runtime

- Baseline: official v2rayN `7.24.4` stable Windows package.
- x64 package: `v2rayN-windows-64.zip`.
- ARM64 package: `v2rayN-windows-arm64.zip`.
- `xray.exe`, `sing-box.exe`, `wintun.dll`, geo assets and the upstream v2rayN application are retained in the build.
- GitHub release SHA-256 digest is checked before the runtime is packaged.
- BlueVPN can download newer stable v2rayN runtime packages to `%LOCALAPPDATA%\BlueVPN\runtime\v2rayn` and activates only a validated runtime.

## Updates

At startup and every four hours while the app is running, BlueVPN checks the dedicated `bluevpn-windows-vX.Y.Z` releases. When a newer matching Setup executable is available it is downloaded and SHA-256 verified from GitHub release metadata before being launched as an in-place installer update.

The v2rayN runtime is checked independently at startup and during periodic maintenance; it is never replaced while a VPN session is active. Update downloads fail closed if GitHub does not provide a valid SHA-256 digest.

## Installation

GitHub Actions publishes real Inno Setup installers:

- `BlueVPN-Setup-<version>-win-x64.exe`
- `BlueVPN-Setup-<version>-win-arm64.exe`

The installer places BlueVPN under Program Files, creates Start Menu entries and an optional desktop shortcut, and registers an uninstaller. Portable ZIPs remain only as diagnostic/fallback artifacts.

## UI and advertising

The WPF home screen follows the current Android BlueVPN visual model: compact account area, central power control, active route/engine card, IP/ping/time/speed metrics and first-party advertising. Banner and free story ads come from the existing BlueVPN `/mobile/config` payload. Ad loading is fail-open and never owns the VPN lifecycle.
