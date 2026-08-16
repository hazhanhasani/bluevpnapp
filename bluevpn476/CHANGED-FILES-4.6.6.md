# BlueVPN 4.6.6 — Changed Files

Version: **4.6.6** (`versionCode 40606`)
Build ID: `20260815-v4.6.6-warp-adaptive-quality`

| File | Change |
|---|---|
| `android-source/BlueVpnWarpEngine.kt` | Replaced fixed WARP launcher with adaptive supervisor/state machine, dynamic port, non-interactive execution, SOCKS/data-plane verification, network-scoped LKG/backoff, bounded logs. |
| `android-source/BlueVpnHomeActivity.kt` | Same-generation post-bridge fallback, WARP verification states, asynchronous WARP cleanup before Pool fallback. |
| `android-source/BlueVpnAccountManager.kt` | Schema-2 WARP policy parsing, validation, persistence and bounded typed settings. |
| `bluevpn-manager/includes/class-bluevpn-db.php` | Backward-compatible WARP schema-2 defaults. |
| `bluevpn-manager/includes/class-bluevpn-ads.php` | Typed schema-2 control-plane payload, save-time validation and admin controls. |
| `scripts/build_aether_android.py` | `--locked` source build, exact commit check, host CLI gate, Cargo.lock/host/ABI SHA-256 provenance, 16 KiB linker flag retained. |
| `.github/workflows/build-apk.yml` | Aether provenance/CLI regression gate and new WARP adaptive tests. |
| `tests/test_warp_adaptive_466.py` | New 15-test adaptive WARP regression suite. |
| `tests/test_current_release.py` | Updated release invariants for dynamic WARP bridge/readiness. |
| `scripts/validate_release.py` | 4.6.6 architecture/release validation updates. |
| `release.json` | Version 4.6.6 / code 40606 / fixed build metadata. |
| `branding/app.json` | Version 4.6.6 and adaptive-quality version source. |
| `bluevpn-manager/bluevpn-manager.php` | Plugin version 4.6.6. |
| `bluevpn-manager/readme.txt` | Stable/release metadata 4.6.6. |
| `README.md` | Release documentation update. |

No build-time version autobump was added.
