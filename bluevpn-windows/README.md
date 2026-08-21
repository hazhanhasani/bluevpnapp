# BlueVPN for Windows — 5.0.5

## 5.0.3 hotfix

- اتصال دیگر به‌خاطر تکمیل بروزرسانی پس‌زمینه برنامه را ناگهانی نمی‌بندد.
- کارت لوکیشن باز می‌شود و انتخاب کشور در یک لیست اسکرولی جمع‌وجور انجام می‌شود.
- بنر تبلیغاتی با نسبت تصویر کامل و layout واکنش‌گرا نمایش داده می‌شود.
- لغو اتصال WARP/Xray پردازش نیمه‌فعال باقی نمی‌گذارد و policy پنل authoritative است.

## 5.0.2 stability pass

- Android home-layout parity adapted to desktop without changing the BlueVPN visual hierarchy.
- Non-blocking metrics/ad/runtime work to reduce UI stalls.
- Premium: v2rayN-sourced Xray local proxy + sing-box system TUN with endpoint loop guards.
- WARP: panel-managed policy + SOCKS data-plane validation before system TUN.
- Connected is fail-closed on TUN adapter, IPv4 route, IPv6 safety and a real public-IP change.
- App update respects Stable/Beta auto-update policy and installs pending updates after disconnect.


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

### 5.0.2 Windows auth/ads hotfix

- Unauthenticated account UI now follows the Android BlueVPN auth flow: SMS/Email mode tabs, two-step OTP, Email login/register mode, dark auth card and orange accent.
- Account/auth failures are rendered inside BlueVPN instead of surfacing raw transport exception dialogs.
- First-party campaign media uses direct HTTPS, Windows system-proxy fallback and a persistent last-known-good cache. If an image cannot be decoded, the campaign title/subtitle/CTA remain visible instead of a blank dark rectangle.
- The `/mobile/config` Tapsell payload is parsed only as a capability signal. The configured Tapsell Mediation App ID/Zone IDs are Android mobile-SDK credentials and are not impersonated as a native WPF ad implementation.

## 5.0.5 Windows UI / update / runtime release

- Responsive WPF home with vertical scrolling for 768p-class displays and wider content on large screens.
- Vector power icon instead of font/emoji glyphs, so Windows font fallback cannot render a square.
- High-contrast account and location surfaces; location buttons use stable text labels instead of depending on flag emoji rendering.
- Connection verification retries Cloudflare trace endpoints and converts transport timeouts to concise Persian UI errors.
- Manual update actions show progress/status and are not silently dropped when a background update check owns the update lock.
- Windows update installers are always SHA-256 verified against the control-plane release metadata; Authenticode is additionally enforced when a signature is present.
- v2rayN remains the pinned Windows runtime source. Packaging keeps one coherent minimal runtime set: v2rayN.exe, Xray, sing-box, Wintun, geoip.dat and geosite.dat, avoiding the previous duplicate full upstream tree.
- Free WARP uses Aether with endpoint scanning and MASQUE first, then WireGuard fallback when policy allows, with sing-box owning the system TUN.
