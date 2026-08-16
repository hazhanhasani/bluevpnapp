# BlueVPN 4.11.4 — Native Network Adaptation

Correction:
- Mahsa-Core / MahsaNG is NOT integrated into the BlueVPN runtime.
- All Mahsa-specific CI, AAR build, core flavor, provenance, WordPress comparison
  tables and canary logic were removed.
- Production and manual builds use the stock pinned v2rayNG/Xray runtime only.

Ideas implemented natively in BlueVPN:
- per-network learned route behavior using BlueVPN's privacy-safe network fingerprint;
- transport success/failure history;
- UDP-blocked awareness;
- bounded preference for already-existing Fragment-capable routes only after the
  current network shows repeated TLS/TCP trouble;
- DNS-failure awareness based on previously verified successful routes;
- network-aware route rotation/scoring and existing circuit-breaker history.

The native adaptation never rewrites credentials, server addresses, UUIDs,
passwords, DNS or imported subscription bodies. It only changes bounded ranking
weights using verified BlueVPN outcomes.
