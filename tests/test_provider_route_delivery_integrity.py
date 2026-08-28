from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]

class ProviderRouteDeliveryIntegrityTests(unittest.TestCase):
    def text(self,path):
        return (ROOT/path).read_text(encoding="utf-8")

    def test_empty_provider_link_is_not_silently_dropped(self):
        src=self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        start=src.index("private static function customer_source_entries")
        end=src.index("public static function refresh_subscription_snapshot",start)
        body=src[start:end]
        self.assertIn("'type'=>'provider_link_missing'",body)
        self.assertIn("$usableLinkedProviders",body)
        self.assertIn("$routeKey",body)

    def test_snapshot_records_missing_route_as_error(self):
        src=self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        self.assertIn("$type==='provider_link_missing'",src)
        self.assertIn("'missing_subscription_url'=>true",src)
        self.assertIn("'route_key'=>(string)($source['route_key']",src)

    def test_snapshot_stats_are_per_route_and_provider(self):
        src=self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        start=src.index("public static function subscription_snapshot_stats")
        end=src.index("public static function request_background_snapshot",start)
        body=src[start:end]
        for token in ["route_counts","provider_counts","missing_routes","source_count"]:
            self.assertIn(token,body)

    def test_all_customer_links_are_enumerated(self):
        src=self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        self.assertIn("foreach(self::customer_provider_links($customerId) as $link)",src)
        self.assertIn("$provider.':'.$panelId",src)

if __name__=="__main__":
    unittest.main()
