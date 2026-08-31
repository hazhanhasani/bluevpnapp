from pathlib import Path
import json, unittest

ROOT=Path(__file__).resolve().parents[1]

class PaidSubscriptionReconcile60203Tests(unittest.TestCase):
    def text(self,path):
        return (ROOT/path).read_text(encoding="utf-8")

    def test_current_release_contract(self):
        release=json.loads(self.text("release.json"))
        self.assertEqual((release["version"],release["version_code"]),("6.2.3",60203))

    def test_repair_covers_every_paid_path(self):
        providers=self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        start=providers.index("public static function repair_customer_missing_providers")
        end=providers.index("public static function repairable_customer_count",start)
        body=providers[start:end]
        for token in [
            "$expectsPg","$expectsMz","$expectsSh","$expectsLegacySh",
            "$expectsGc","$expectsStatic","$expectsGateway",
            "BlueVPN_Shahrah::repair_panel_customer",
            "BlueVPN_Shahrah::repair_source_customer",
            "BlueVPN_Gateway::ensure_customer_sessions",
            "request_background_snapshot($customerId)",
        ]:
            self.assertIn(token,body)
        self.assertNotIn("$update['subscription_expire']",body)
        self.assertIn("هیچ Provider، Source پولی یا Gateway قابل ترمیم وجود ندارد",body)

    def test_shahrah_repair_never_renews(self):
        shahrah=self.text("bluevpn-manager/includes/class-bluevpn-shahrah.php")
        start=shahrah.index("private static function repair_without_renew")
        end=shahrah.index("public static function repair_panel_customer",start)
        body=shahrah[start:end]
        self.assertIn("create_service(",body)
        self.assertIn("locate_service_by_username",body)
        self.assertIn("self::service(",body)
        self.assertNotIn("renew_service(",body)

    def test_automatic_reconcile_is_bounded_and_cursor_based(self):
        providers=self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        cron=self.text("bluevpn-manager/includes/class-bluevpn-cron.php")
        self.assertIn("PAID_REPAIR_CURSOR_OPTION",providers)
        self.assertIn("reconcile_missing_paid_subscriptions_batch",providers)
        self.assertIn("repair_candidate_ids_after($cursor,$limit)",providers)
        self.assertIn("max(1,min(5,$limit))",providers)
        self.assertIn("reconcile_missing_paid_subscriptions_batch(2)",cron)
        self.assertIn("bluevpn_five_minutes",cron)

    def test_admin_bulk_repair_reports_all_paths(self):
        ui=self.text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        for token in ["Shahrah","Sourceهای ثابت","Gateway Metered","Sync مجدد","resynced"]:
            self.assertIn(token,ui)

if __name__=="__main__":
    unittest.main()
