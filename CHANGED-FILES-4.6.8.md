# BlueVPN 4.6.8 changed files

- `android-source/BlueVpnWarpEngine.kt` — strict Cloudflare trace validation; rejects blocked WARP exit countries (IR by default) before the bridge is handed to Xray.
- `android-source/BlueVpnAccountManager.kt` — receives/persists `require_exit_trace` and `blocked_exit_countries` from WordPress mobile config.
- `android-source/BlueVpnHomeActivity.kt` — second exit-country proof through the final local Xray/TUN path; a blocked WARP exit cannot reach CONNECTED and falls back to the Free pool when policy allows.
- `bluevpn-manager/includes/class-bluevpn-ads.php` — WordPress API + admin controls for strict exit verification and blocked country list.
- `bluevpn-manager/includes/class-bluevpn-db.php` — defaults: strict trace enabled, `IR` blocked.
- `branding/app.json`, `release.json`, plugin metadata — version 4.6.8 / code 40608.
- `tests/test_warp_adaptive_468.py`, `tests/test_warp_exit_guard_468.py` — release and exit-guard regressions.
