# BlueVPN 4.7.7 changed files

- `android-source/BlueVpnSmsOtpAutoFill.kt` — permissionless SMS User Consent receiver, six-digit OTP extraction, Persian digit normalization, one-message consent flow.
- `android-source/BlueVpnSubscriptionsActivity.kt` — starts SMS listener before OTP request, buffers early codes until challenge creation, auto-fills and auto-verifies received OTP, adds Android SMS OTP autofill hints.
- `scripts/prepare_android.py` — pins `play-services-auth-api-phone:18.3.1` and installs the new Android source into upstream v2rayNG during reproducible bootstrap.
- `tests/test_sms_otp_autofill_477.py` — regression coverage for dependency, no SMS permissions, listener ordering, early-code buffering, and automatic verification.
- `tests/release_test_manifest.json` — registers 4.7.7 OTP regression suite.
- `tests/test_warp_adaptive_469.py` — current release metadata expectation updated to 4.7.7.
- Version metadata updated to `4.7.7` / `40707`.
