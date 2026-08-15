# BlueVPN 4.6.10 changed files

- `android-source/BlueVpnWarpEngine.kt` — parallel race, cancellation, LKG/history, adaptive cooldown, direct peer, process/port lifecycle, stricter validation and error classes.
- `android-source/BlueVpnWarpPolicy.kt` — Android-free scoring/LKG/backoff/IP policy with executable JVM behavior tests.
- `android-source/BlueVpnAccountManager.kt` — authoritative `false` fix for Free enable state.
- `scripts/prepare_android.py` — includes the new policy source in the Android overlay.
- `.github/workflows/build-apk.yml` — complete test discovery and explicit Kotlin compile before release assemble.
- `bluevpn-manager/includes/class-bluevpn-api.php` — minimal public health + admin-only details.
- `bluevpn-manager/includes/class-bluevpn-sms-otp.php` — device-scoped OTP rate limit.
- `bluevpn-manager/includes/class-bluevpn-payments.php` — duplicate delivery short-circuit before activation.
- `tests/*warp*`, `tests/test_security_4610.py`, `tests/WarpPolicyBehaviorTest.kt` — stronger regression coverage.
- version/release/plugin/readme metadata — synchronized to `4.6.10` / `40610`.
