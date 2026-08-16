# BlueVPN 4.6.9 Build and Test

This release adds a bounded Cloudflare endpoint/port fast path in front of Aether's native scanner. The matrix uses documented WARP ingress ranges/ports, remembers a working peer per Android network signature, temporarily cools down failed peers, rotates through the matrix over repeated failures, and falls back to Aether turbo scanning.

Safety/quality gates keep SOCKS5 data-plane validation and Cloudflare exit-country validation enabled. Iranian egress remains rejected by policy. WireGuard is now enabled as a default fallback transport.
