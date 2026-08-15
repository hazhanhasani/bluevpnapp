# BlueVPN 4.6.7 changed files

- `android-source/BlueVpnWarpEngine.kt` — fixed nullable `serverPort` access in `isBridgeGuid()` using a safe call before `toIntOrNull()`.
- `branding/app.json` — version 4.6.7 / code 40607.
- `release.json` — release metadata updated to 4.6.7.
- `bluevpn-manager/bluevpn-manager.php` and `readme.txt` — plugin version metadata updated to 4.6.7.
- `.github/workflows/build-apk.yml` and `scripts/build_aether_android.py` — Aether provenance filename advanced to 4.6.7.

The fix avoids both the Kotlin compiler error and an unsafe runtime `!!` assertion.
