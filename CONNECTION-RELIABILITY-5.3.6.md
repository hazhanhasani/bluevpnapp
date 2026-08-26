# BlueVPN 5.3.6 — Connection reliability

- Removed Android's pre-verification Premium "connected" UI state.
- Made measured route latency/reliability authoritative over stale AI history.
- Expanded Windows live probing and bounded failover coverage.
- Fixed false Windows TUN failures caused by leftover physical IPv6 routes.
- Increased first-run Xray/Wintun readiness windows.
- WARP now requires verified Cloudflare egress before TUN startup.
- Rebases the Android build from v2rayNG 2.2.6 to official v2rayNG 2.3.5
  with AndroidLibXrayLite/Xray-core v26.7.28 compatibility metadata.
