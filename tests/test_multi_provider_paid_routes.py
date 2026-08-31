from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]

class MultiProviderPaidRoutesTests(unittest.TestCase):
    def text(self,path):
        return (ROOT/path).read_text(encoding="utf-8")

    def test_schema_supports_unlimited_provider_routes(self):
        db=self.text("bluevpn-manager/includes/class-bluevpn-db.php")
        plugin=self.text("bluevpn-manager/bluevpn-manager.php")
        self.assertIn("BLUEVPN_MANAGER_SCHEMA_VERSION', '1.37.0'",plugin)
        self.assertIn("provider_routes_json longtext",db)
        self.assertIn("customer_provider_links",db)
        self.assertIn("uq_customer_provider_route",db)
        for token in ["provider_type varchar(24)","panel_id bigint unsigned","route_key varchar(190)","subscription_url longtext"]:
            self.assertIn(token,db)

    def test_plan_ui_is_multi_select_for_every_paid_provider(self):
        cc=self.text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        for token in [
            "pasarguard_panel_ids",
            "marzban_panel_ids",
            "shahrah_plan_keys",
            "guardcore_panel_ids",
            "multiple size=",
            "چند مورد را می‌توانی همزمان انتخاب کنی",
        ]:
            self.assertIn(token,cc)
        self.assertIn("posted_provider_routes",cc)
        self.assertIn("provider_route_storage",cc)
        self.assertIn("provider_routes_json",cc)

    def test_legacy_single_columns_are_only_compatibility_mirrors(self):
        cc=self.text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        storage=cc[cc.index("public static function provider_route_storage"):cc.index("public static function provider_access_catalog")]
        for token in ["$firstPg","$firstMz","$firstSh","$firstGc","'panel_id'","'marzban_panel_id'","'shahrah_panel_id'","'guardcore_panel_id'"]:
            self.assertIn(token,storage)
        self.assertIn("'provider_routes_json'=>BlueVPN_Utils::json_encode($routes)",storage)

    def test_runtime_provisions_every_selected_route(self):
        providers=self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        start=providers.index("public static function provision_customer")
        end=providers.index("public static function request_background_sync",start)
        body=providers[start:end]
        for token in [
            "foreach($routes['pasarguard'] as $route)",
            "foreach($routes['marzban'] as $route)",
            "foreach($routes['shahrah'] as $route)",
            "foreach($routes['guardcore'] as $route)",
            "provider_link_upsert",
            "prune_customer_provider_links",
        ]:
            self.assertIn(token,body)
        self.assertIn("count($routes['pasarguard'])+count($routes['marzban'])+count($routes['guardcore'])",body)

    def test_snapshot_aggregates_all_customer_provider_links(self):
        providers=self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        start=providers.index("private static function customer_source_entries")
        end=providers.index("public static function refresh_subscription_snapshot",start)
        body=providers[start:end]
        self.assertIn("customer_provider_links($customerId)",body)
        self.assertIn("$provider.':'.$panelId",body)
        self.assertIn("'type'=>'shahrah_panel'",body)

    def test_sync_and_repair_use_multi_route_paths(self):
        providers=self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        self.assertIn("sync_customer_multi_links",providers)
        self.assertIn("repair_customer_multi_routes",providers)
        self.assertIn("if(self::customer_provider_links($customerId))return self::sync_customer_multi_links",providers)
        self.assertIn("if(trim((string)($plan['provider_routes_json']??''))!=='')return self::repair_customer_multi_routes",providers)

    def test_shahrah_allows_multiple_connections_not_two_plans_on_same_connection(self):
        cc=self.text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        self.assertIn("shahrah_plan_keys",cc)
        self.assertIn("if(isset($seenSh[$id]))",cc)
        self.assertIn("برای هر اتصال شاهراه فقط یک پلن انتخاب کن",cc)

if __name__=="__main__":
    unittest.main()
