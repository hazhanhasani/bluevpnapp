# BlueVPN 4.6.9 changed files

- `android-source/BlueVpnWarpEngine.kt` — bounded Cloudflare endpoint/port matrix, per-network last-good peer, cooldown/rotation, direct `--peer` fast path, native turbo scan fallback.
- `android-source/BlueVpnAccountManager.kt` — endpoint racing policy fields; turbo and WireGuard defaults.
- `bluevpn-manager/includes/class-bluevpn-ads.php` — endpoint racing controls/API payload.
- `bluevpn-manager/includes/class-bluevpn-db.php` — new defaults.
- `scripts/build_aether_android.py`, `.github/workflows/build-apk.yml` — `--peer` capability gate and 4.6.9 provenance.
- `branding/app.json`, `release.json`, plugin metadata — version 4.6.9 / code 40609.
