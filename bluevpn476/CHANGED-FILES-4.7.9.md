# BlueVPN 4.7.9 changed files

- `android-source/BlueVpnSubscriptionsActivity.kt`: replaces the unavailable platform `View.AUTOFILL_HINT_SMS_OTP` symbol with the AndroidX-compatible standardized SMS OTP autofill hint value `smsOTPCode`, avoiding compileSdk/API symbol coupling while preserving autofill behavior.
- Version metadata synchronized to 4.7.9 / 40709.
