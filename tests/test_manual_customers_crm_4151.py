import json
import pathlib
import re
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]

def text(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")

class ManualCustomersCRM4151Tests(unittest.TestCase):
    def test_independent_table_has_no_provider_or_entitlement_columns(self):
        db = text("bluevpn-manager/includes/class-bluevpn-db.php")
        block = db.split("CREATE TABLE {$t('manual_customers')}", 1)[1].split(") $cc;", 1)[0]
        self.assertIn("phone varchar(20)", block)
        self.assertIn("service_name varchar(180)", block)
        self.assertIn("expire_at datetime", block)
        self.assertIn("sms_enabled tinyint(1)", block)
        self.assertIn("catalog_plan_id", block)
        self.assertNotIn("\n            plan_id bigint", block)
        self.assertNotIn("panel_id", block)
        self.assertNotIn("subscription_url", block)
        self.assertNotIn("entitlement", block)

    def test_admin_has_manual_customer_page(self):
        cc = text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        admin = text("bluevpn-manager/includes/class-bluevpn-admin.php")
        ui = text("bluevpn-manager/includes/class-bluevpn-unified-ui.php")
        self.assertIn("'manual-customers'=>'مشتریان دستی'", cc)
        self.assertIn("'manual-customers'=>'bluevpn-manual-customers'", cc)
        self.assertIn("BlueVPN_Manual_Customers::render()", cc)
        self.assertIn("bluevpn-manual-customers", admin)
        self.assertIn("bluevpn-manual-customers", ui)

    def test_crm_supports_crud_renew_csv_and_sms(self):
        crm = text("bluevpn-manager/includes/class-bluevpn-manual-customers.php")
        for token in (
            "public static function save()",
            "public static function renew()",
            "public static function toggle()",
            "public static function delete()",
            "public static function send_sms()",
            "public static function import_csv()",
            "public static function scan_notifications()",
        ):
            self.assertIn(token, crm)
        self.assertIn("fgetcsv", crm)
        self.assertIn("sms_customer_id", crm)
        self.assertIn("'today'=>'تا ۲۴ ساعت آینده'", crm)
        self.assertIn("subscription_renewed", crm)
        self.assertIn("subscription_reminder", crm)
        self.assertIn("subscription_expired", crm)

    def test_sms_events_and_cron_are_wired(self):
        sms = text("bluevpn-manager/includes/class-bluevpn-sms-notifications.php")
        for event in (
            "admin_subscription_activated",
            "subscription_renewed",
            "subscription_reminder",
            "subscription_expired",
        ):
            self.assertIn("'" + event + "'", sms)
        self.assertNotIn("'manual_subscription_activated'=>", sms)
        self.assertNotIn("'manual_subscription_renewed'=>", sms)
        self.assertNotIn("'manual_subscription_reminder'=>", sms)
        self.assertNotIn("'manual_subscription_expired'=>", sms)
        self.assertIn("BlueVPN_Manual_Customers::scan_notifications()", sms)
        self.assertIn("2026-08-18-4.15.6-manual-exact-admin-activation-event", sms)

    def test_dates_are_jalali_in_admin_and_utc_in_db(self):
        utils = text("bluevpn-manager/includes/class-bluevpn-utils.php")
        crm = text("bluevpn-manager/includes/class-bluevpn-manual-customers.php")
        self.assertIn("jalali_to_gregorian", utils)
        self.assertIn("mysql_from_tehran_date", utils)
        self.assertIn("BlueVPN_Utils::tehran_date_fa", crm)
        self.assertIn("تاریخ انقضا شمسی", crm)

    def test_plugin_version_and_schema(self):
        plugin = text("bluevpn-manager/bluevpn-manager.php")
        release = json.loads(text("release.json"))
        branding = json.loads(text("branding/app.json"))
        self.assertIn("Version: 6.2.7", plugin)
        self.assertIn("BLUEVPN_MANAGER_SCHEMA_VERSION', '1.36.0'", plugin)
        self.assertEqual(release["version"], "6.2.7")
        self.assertEqual(branding["version_name"], "6.2.7")
        self.assertEqual(branding["version_code"], 60207)

if __name__ == "__main__":
    unittest.main()
