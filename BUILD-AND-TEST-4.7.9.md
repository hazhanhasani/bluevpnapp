# BlueVPN 4.7.9 Build & Test

Hotfix for GitHub Build #266 `gradle-compile`: the project referenced `View.AUTOFILL_HINT_SMS_OTP`, which is not a platform `View` constant in the pinned Android compile environment. The OTP fields now use the standardized SMS OTP autofill hint value `smsOTPCode` directly, which Android's autofill framework accepts and AndroidX `HintConstants.AUTOFILL_HINT_SMS_OTP` defines.


Verification performed locally:
- 197/197 Python regression tests PASS.
- Release validator PASS.
- PHP syntax lint PASS.
- 3 GitHub workflow YAML files parse successfully.
- Python compileall PASS.
- Full Android Gradle compile remains delegated to GitHub Actions because the source bundle bootstraps the pinned upstream v2rayNG checkout.
