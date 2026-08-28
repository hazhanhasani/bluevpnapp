from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]

class PaidRouteSnapshotCompletenessTests(unittest.TestCase):
    def text(self,path):
        return (ROOT/path).read_text(encoding="utf-8")

    def test_snapshot_reconciles_expected_plan_routes_before_delivery(self):
        src=self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        self.assertIn("expected_provider_route_keys",src)
        self.assertIn("reconcile_snapshot_routes_if_needed",src)
        start=src.index("public static function refresh_subscription_snapshot")
        end=src.index("public static function gateway_upstream_pool",start)
        body=src[start:end]
        self.assertIn("reconcile_snapshot_routes_if_needed($c)",body)
        self.assertIn("customer_source_entries($c)",body)
        self.assertIn("'route_reconcile'=>$routeReconcile",body)

    def test_missing_or_empty_provider_links_trigger_safe_repair(self):
        src=self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        start=src.index("private static function reconcile_snapshot_routes_if_needed")
        end=src.index("private static function customer_source_entries",start)
        body=src[start:end]
        self.assertIn("array_diff(array_keys($expected),array_keys($linked))",body)
        self.assertIn("subscription_url",body)
        self.assertIn("$provider!=='shahrah'",body)
        self.assertIn("repair_customer_missing_providers($customerId)",body)

    def test_route_audit_reports_every_expected_provider_route(self):
        src=self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        start=src.index("public static function subscription_route_audit")
        end=src.index("public static function subscription_snapshot_stats",start)
        body=src[start:end]
        self.assertIn("plan_provider_routes($plan)",body)
        self.assertIn("customer_provider_links($customerId)",body)
        self.assertIn("'config_count'=>$count",body)
        self.assertIn("'status'=>$healthy?'healthy'",body)

    def test_customer_detail_exposes_route_delivery_audit(self):
        ui=self.text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        self.assertIn("ممیزی مسیرهای اشتراک",ui)
        self.assertIn("subscription_route_audit($customerId)",ui)
        self.assertIn("اتصال گمشده",ui)
        self.assertIn("بدون کانفیگ",ui)

if __name__=="__main__":
    unittest.main()
