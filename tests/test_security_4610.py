import unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
API=(ROOT/'bluevpn-manager/includes/class-bluevpn-api.php').read_text()
PAY=(ROOT/'bluevpn-manager/includes/class-bluevpn-payments.php').read_text()
OTP=(ROOT/'bluevpn-manager/includes/class-bluevpn-sms-otp.php').read_text()
class Security4610(unittest.TestCase):
    def test_public_health_is_minimal_and_details_admin_only(self):
        public=API[API.index('public static function health():'):API.index('public static function health_details():')]
        self.assertNotIn("'database' =>",public); self.assertNotIn("'counts' =>",public)
        self.assertIn("'/health/details'",API); self.assertIn("current_user_can('manage_options')",API)
    def test_payment_duplicate_returns_before_activation(self):
        self.assertIn("if($duplicate)return new WP_REST_Response",PAY)
    def test_otp_has_phone_ip_and_device_limits(self):
        self.assertIn('bluevpn_otp_phone_',OTP); self.assertIn('bluevpn_otp_ip_',OTP); self.assertIn('bluevpn_otp_device_',OTP)
