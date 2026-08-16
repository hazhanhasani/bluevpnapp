from pathlib import Path
import json
import unittest

ROOT = Path(__file__).resolve().parents[1]

class SmsOtpAutoFill477Tests(unittest.TestCase):
    def test_permissionless_user_consent_dependency_is_pinned(self):
        prep=(ROOT/'scripts/prepare_android.py').read_text(encoding='utf-8')
        self.assertIn('play-services-auth-api-phone:18.3.1', prep)
        manifest_patch=prep
        self.assertNotIn('android.permission.READ_SMS', manifest_patch)
        self.assertNotIn('android.permission.RECEIVE_SMS', manifest_patch)

    def test_listener_starts_before_otp_network_request_and_early_code_is_buffered(self):
        src=(ROOT/'android-source/BlueVpnSubscriptionsActivity.kt').read_text(encoding='utf-8')
        method=src[src.index('private fun requestOtp('):src.index('private fun verifyOtp(')]
        self.assertLess(method.index('smsOtpAutoFill?.start()'), method.index('BlueVpnAccountManager.requestOtp'))
        self.assertIn('pendingAutoOtpCode', src)
        self.assertIn('handleAutoOtp(pending)', method)

    def test_received_code_is_auto_verified(self):
        src=(ROOT/'android-source/BlueVpnSubscriptionsActivity.kt').read_text(encoding='utf-8')
        block=src[src.index('private fun handleAutoOtp('):src.index('private fun requestOtp(')]
        self.assertIn('verifyOtp(phone,code,otpBinding)', block)
        self.assertIn('draftOtpCode=code', block)
        helper=(ROOT/'android-source/BlueVpnSmsOtpAutoFill.kt').read_text(encoding='utf-8')
        self.assertIn('startSmsUserConsent(null)', helper)
        self.assertIn('SmsRetriever.SEND_PERMISSION', helper)
        self.assertIn("'۰' -> '0'", helper)

    def test_autofill_hint_is_compile_sdk_compatible(self):
        activity=(ROOT/'android-source/BlueVpnSubscriptionsActivity.kt').read_text(encoding='utf-8')
        self.assertNotIn('View.AUTOFILL_HINT_SMS_OTP', activity)
        self.assertIn('AUTOFILL_HINT_SMS_OTP_COMPAT="smsOTPCode"', activity)

    def test_release_version(self):
        brand=json.loads((ROOT/'branding/app.json').read_text(encoding='utf-8'))
        self.assertEqual(brand['version_name'],'4.11.1')
        self.assertEqual(brand['version_code'],41101)

if __name__ == '__main__': unittest.main()
