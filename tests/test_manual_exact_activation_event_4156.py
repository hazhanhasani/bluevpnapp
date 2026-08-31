import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]

def text(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")

class ManualExactActivationEvent4156Tests(unittest.TestCase):
    def test_existing_manual_activation_and_crm_use_same_event(self):
        cc = text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        crm = text("bluevpn-manager/includes/class-bluevpn-manual-customers.php")
        manual = cc.split("public static function manual_activate(): void", 1)[1].split(
            "public static function guardcore_refresh_catalog", 1
        )[0]
        self.assertIn("BlueVPN_SMS_Notifications::queue_and_dispatch(", manual)
        self.assertIn("'admin_subscription_activated'", manual)
        save = crm.split("public static function save(): void", 1)[1].split(
            "public static function renew(): void", 1
        )[0]
        self.assertIn("'admin_subscription_activated'", save)

    def test_existing_customer_can_resend_activation(self):
        crm = text("bluevpn-manager/includes/class-bluevpn-manual-customers.php")
        self.assertIn("bluevpn_manual_customer_send_activation_sms", crm)
        self.assertIn("public static function send_activation_sms(): void", crm)
        self.assertIn("ارسال فعال‌سازی", crm)

if __name__ == "__main__":
    unittest.main()
