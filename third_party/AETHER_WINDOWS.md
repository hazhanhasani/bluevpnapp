# Aether WARP runtime notice (Windows)

BlueVPN Free on Windows x64 can use Aether as a separate local WARP/MASQUE process. Aether exposes a SOCKS5 listener on loopback and BlueVPN's v2rayN-bundled sing-box TUN carries system traffic through it.

- Project: `CluvexStudio/Aether`
- Pinned Windows baseline for BlueVPN 4.16.2: `v1.1.1`
- License: AGPL-3.0
- Windows x64 artifact: `aether-windows-x86_64.zip`
- Aether v1.1.1 does not publish a Windows ARM64 build. BlueVPN therefore uses its curated Xray free-pool fallback on ARM64 instead of pretending WARP is available.

The Aether process is excluded/direct-routed by the sing-box routing policy to prevent a TUN loop. BlueVPN does not report CONNECTED until the system route, public IP, Cloudflare WARP state and non-IR exit policy are verified.
