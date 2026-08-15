# Aether integration notice

BlueVPN 4.6.3 optionally packages **Aether** as the primary Free-tier Cloudflare WARP transport.

- Upstream source: https://github.com/CluvexStudio/Aether
- Pinned revision: `a26159b82a70048b459e0128213c71767abecb8a`
- Upstream license: GNU Affero General Public License v3.0 (AGPL-3.0)
- Integration model: Aether runs as a separate native process and exposes SOCKS5 on loopback (`127.0.0.1:1819`). BlueVPN creates a dedicated local SOCKS profile and uses the stock v2rayNG/Xray VPN service as the Android TUN owner.
- The Oblivion Android application source is **not copied** into BlueVPN.

The build workflow compiles the pinned Aether source revision rather than downloading an unpinned binary. Keep the corresponding source and license obligations available when distributing binaries containing Aether.
