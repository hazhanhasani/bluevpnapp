import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]

def text(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")

class ManualActivationSmsImmediate4155Tests(unittest.TestCase):
    def test_first_plan_assignment_uses_exact_manual_activation_event(self):
        crm = text("bluevpn-manager/includes/class-bluevpn-manual-customers.php")
        save = crm.split("public static function save(): void", 1)[1].split(
            "public static function renew(): void", 1
        )[0]
        self.assertIn("'admin_subscription_activated'", save)

    def test_activation_is_attempted_immediately_not_only_by_cron(self):
        crm = text("bluevpn-manager/includes/class-bluevpn-manual-customers.php")
        sms = text("bluevpn-manager/includes/class-bluevpn-sms-notifications.php")
        self.assertIn("queue_and_dispatch_customer_event", crm)
        self.assertIn("BlueVPN_SMS_Notifications::dispatch_now", crm)
        self.assertIn("public static function dispatch_now(", sms)
        self.assertIn("send_pattern(", sms)
        self.assertIn("wake_queue()", sms)

    def test_renewal_and_plan_change_are_also_foreground(self):
        crm = text("bluevpn-manager/includes/class-bluevpn-manual-customers.php")
        self.assertIn("'subscription_renewed'", crm)
        self.assertIn("'subscription_plan_changed'", crm)
        self.assertIn("پیام «تمدید اشتراک» همان لحظه ارسال شد", crm)

    def test_existing_sms_manager_controls_are_respected(self):
        sms = text("bluevpn-manager/includes/class-bluevpn-sms-notifications.php")
        self.assertIn("empty($template['enabled'])", sms)
        self.assertIn("pattern_code", sms)
        self.assertIn("notification_active", sms)

if __name__ == "__main__":
    unittest.main()
