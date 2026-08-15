# BlueVPN 4.6.6 — Root Cause Report

## Scope

Baseline: `bluevpn-platform-v4.6.5-warp-free-entitlement-control-plane.zip`.
Target: Free WARP path only. Premium remains stock v2rayNG 2.2.6/Xray; no sing-box or dual-engine restoration.

## Root causes confirmed in 4.6.5

1. **Readiness was too shallow.** The old free path treated local SOCKS availability as the principal Aether-ready signal. A listening loopback port does not prove SOCKS negotiation, remote-DNS CONNECT, real data-plane traffic, or the final Android TUN -> Xray -> Aether -> WARP path.
2. **Fallback ownership ended too early.** Fallback to the legacy Free Pool was primarily tied to WARP preparation. A failure after the WARP bridge had been handed to Xray could terminate the attempt instead of continuing the same user generation into the Pool.
3. **Fixed startup assumptions.** The 3–20 second clamp and fixed fallback window were not aligned with cached reconnect versus cold MASQUE scan/startup behavior.
4. **Fixed port identity.** Treating `127.0.0.1:1819` as both port and identity could accept an unrelated listener or fail hard on collision.
5. **Non-interactive process contract was incomplete.** Aether can ask whether to reuse Last-Known-Good unless quick reconnect is explicitly resolved. A headless Android child must never depend on terminal input.
6. **No network-scoped route memory.** A successful transport on one network/operator was not persisted as a typed, privacy-minimized strategy keyed by network characteristics.
7. **Control-plane schema was too narrow.** WordPress could enable WARP and set a basic timeout, but could not express a bounded adaptive transport policy.

## 4.6.6 corrective design

- One WARP supervisor with generation token + coroutine `Mutex` and explicit states.
- Typed strategies: cached LKG, MASQUE H3, H2, H2+TLS ClientHello fragmentation, optional WireGuard, optional gool.
- Explicit `--quick-reconnect`/`--no-quick-reconnect`; child stdin is closed immediately.
- Explicit protocol, scan, noize, IP mode and MASQUE startup deadline; no shell command construction.
- Dynamic loopback selection in the bounded range 1819–1829; bridge profile is generated for the exact selected port and kept under the private bridge subscription ID.
- SOCKS5 greeting + domain-name CONNECT + real proxied HTTP validation. Cloudflare trace `warp=on|plus` is sufficient; otherwise at least two independent proxied endpoints must succeed.
- Post-bridge verification failure now stops WARP asynchronously and enters the Free Pool in the **same connection generation** when policy permits.
- Per-network Last-Known-Good + per-strategy backoff. Signature contains network class, IPv4/IPv6 presence and MCC/MNC only; no BSSID, full IP, phone number or WARP identity.
- Log rotation capped to current + one previous 512 KiB file.
- WordPress `warp.schema = 2` with typed/bounded transport and timeout settings; Android validates the same policy before translating it into local CLI arguments.
- Aether build script is pinned to commit `a26159b82a70048b459e0128213c71767abecb8a`, uses `cargo build --release --locked`, verifies resolved commit, records Cargo.lock SHA-256, executes host `--version`/`--help`, and records ABI hashes when the toolchain is available.

## Intentionally unchanged

Premium parser/config builder/CoreVpnService/TUN ownership remains v2rayNG/Xray. The WARP bridge remains internal and is not inserted into Premium/Free subscription pools. No arbitrary process arguments are accepted from WordPress.
