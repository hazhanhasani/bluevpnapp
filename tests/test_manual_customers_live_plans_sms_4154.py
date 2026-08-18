import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]

def text(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")

class ManualCustomersLivePlansSms4154Tests(unittest.TestCase):
    def test_manual_crm_uses_existing_plan_catalog(self):
        crm = text("bluevpn-manager/includes/class-bluevpn-manual-customers.php")
        db = text("bluevpn-manager/includes/class-bluevpn-db.php")
        self.assertIn("catalog_plan_id", db)
        self.assertIn("BlueVPN_DB::table('plans')", crm)
        self.assertIn("پلن فعلی BlueVPN", crm)
        self.assertIn("duration_days", crm)
        self.assertNotIn("name=\"days\"", crm)
        self.assertNotIn("name=\"service_name\"", crm)

    def test_manual_crm_reuses_canonical_subscription_messages(self):
        crm = text("bluevpn-manager/includes/class-bluevpn-manual-customers.php")
        sms = text("bluevpn-manager/includes/class-bluevpn-sms-notifications.php")
        for event in (
            "admin_subscription_activated",
            "subscription_renewed",
            "subscription_plan_changed",
            "subscription_reminder",
            "subscription_expired",
        ):
            self.assertIn("'" + event + "'", crm)
        for legacy in (
            "manual_subscription_activated",
            "manual_subscription_renewed",
            "manual_subscription_reminder",
            "manual_subscription_expired",
        ):
            self.assertNotIn("'" + legacy + "'=>", sms)
        self.assertIn("DELETE FROM {$table}", sms)

    def test_notifications_are_automatic_and_use_current_settings(self):
        crm = text("bluevpn-manager/includes/class-bluevpn-manual-customers.php")
        self.assertIn("BlueVPN_SMS_Notifications::settings()", crm)
        self.assertIn("reminder_days_json", crm)
        self.assertIn("scan_notifications()", crm)
        self.assertIn("public static function send_activation_sms(): void", crm)
        self.assertIn("admin_subscription_activated", crm)

    def test_renewal_uses_selected_plan_duration_not_manual_days(self):
        crm = text("bluevpn-manager/includes/class-bluevpn-manual-customers.php")
        renew = crm.split("public static function renew(): void", 1)[1].split(
            "public static function toggle(): void", 1
        )[0]
        self.assertIn("$days = (int)($plan['duration_days']", renew)
        self.assertNotIn("$_POST['days']", renew)
        self.assertIn("subscription_renewed", renew)

    def test_crm_still_does_not_provision_vpn(self):
        crm = text("bluevpn-manager/includes/class-bluevpn-manual-customers.php")
        db = text("bluevpn-manager/includes/class-bluevpn-db.php")
        block = db.split("CREATE TABLE {$t('manual_customers')}", 1)[1].split(") $cc;", 1)[0]
        self.assertNotIn("panel_id", block)
        self.assertNotIn("subscription_url", block)
        self.assertNotIn("entitlement", block)
        self.assertNotIn("BlueVPN_Providers::", crm)

if __name__ == "__main__":
    unittest.main()
