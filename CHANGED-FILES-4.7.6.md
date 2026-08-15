# BlueVPN 4.7.6 changed files

- `android-source/BlueVpnWarpEngine.kt` — replaces multi-process Aether endpoint racing with one-process native Aether v1.6 scanning/quick reconnect, serializes launches, persists per-device identity under no-backup storage, and adds per-network transport scoring.
- `scripts/build_aether_android.py` — verifies pinned Aether CLI exposes the v1.6 `--perf` and `--log-level` flags used at runtime.
- Version/release metadata updated to 4.7.6 / 40706.
- Regression coverage added for single-process WARP orchestration and persistent identity.

WARP+ note: the pinned Aether v1.6 CLI does not expose Cloudflare consumer `WARP+` license registration. BlueVPN therefore does not pretend to apply a WARP+ key. The key remains usable with the official Cloudflare WARP client; Aether v1.6 supports Zero Trust enrollment separately.
