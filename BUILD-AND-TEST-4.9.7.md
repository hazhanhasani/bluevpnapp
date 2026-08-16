# BlueVPN 4.9.8 — Stock Xray + Mahsa-Core Canary

Implemented:
- Production stock Xray remains default and unchanged.
- Manual GitHub builds can select `core_mode=mahsa-canary`.
- Full production releases fail closed if Mahsa canary is selected.
- Mahsa AndroidLibXrayLite is pinned to commit
  `8a5c4d4549338e13fa00ac1fe1e431074823f339`.
- The canary build verifies that its go.mod declares
  `GFW-knocker/Xray-core v1.26.5-mahsa-r1`.
- gomobile module is pinned to
  `v0.0.0-20260217195705-b56b3793a9c4`.
- Separate libv2ray.aar is built and installed only for canary builds.
- Android reports `core_family` and immutable source pin to BlueAI.
- WordPress/MySQL stores core-separated event/live telemetry.
- `ai_core_aggregates` compares Stock vs Mahsa by operator/network/tier.
- BlueAI Operations Center detects canary outperform/underperform cohorts.
- Promotion is recommendation-only; no automatic core replacement.

A single APK does not bundle both libv2ray AARs because they export duplicate
libv2ray classes. This release uses separate build flavors/canary artifacts,
which provides safe rollback and valid A/B data without destabilizing production.
