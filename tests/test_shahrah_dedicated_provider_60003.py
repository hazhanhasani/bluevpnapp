from pathlib import Path
import json, unittest

ROOT=Path(__file__).resolve().parents[1]

class ShahrahDedicatedProvider60101Tests(unittest.TestCase):
    def text(self,path):
        return (ROOT/path).read_text(encoding="utf-8")

    def test_release_and_schema_contract(self):
        release=json.loads(self.text("release.json"))
        self.assertEqual((release["version"],release["version_code"]),("6.1.1",60101))
        plugin=self.text("bluevpn-manager/bluevpn-manager.php")
        self.assertIn("BLUEVPN_MANAGER_SCHEMA_VERSION', '1.34.0'",plugin)

    def test_shahrah_has_dedicated_schema_and_admin_route(self):
        db=self.text("bluevpn-manager/includes/class-bluevpn-db.php")
        admin=self.text("bluevpn-manager/includes/class-bluevpn-admin.php")
        ui=self.text("bluevpn-manager/includes/class-bluevpn-unified-ui.php")
        control=self.text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        for token in ["shahrah_panels","shahrah_panel_id","shahrah_plan_slug","ix_plan_shahrah"]:
            self.assertIn(token,db)
        self.assertIn("bluevpn-shahrah",admin)
        self.assertIn("bluevpn-shahrah",ui)
        self.assertIn("tab_shahrah",control)
        self.assertIn("BlueVPN_Shahrah::render_admin_tab()",control)

    def test_generic_sources_no_longer_offer_shahrah(self):
        sources=self.text("bluevpn-manager/includes/class-bluevpn-subscription-sources.php")
        render=sources[sources.index("public static function render_admin_tab"):]
        self.assertNotIn('<option value="shahrah">',render)
        self.assertIn("Shahrah از صفحه اختصاصی Provider مدیریت می‌شود",sources)
        init=sources[sources.index("public static function init"):sources.index("private static function table")]
        self.assertNotIn("ensure_shahrah_source()",init)

    def test_catalog_sync_and_plan_mapping_are_automatic(self):
        shahrah=self.text("bluevpn-manager/includes/class-bluevpn-shahrah.php")
        control=self.text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        for token in ["sync_panel","plan_catalog","plan_exists","GET', '/plans","GET', '/services"]:
            self.assertIn(token,shahrah)
        self.assertIn("shahrah_plan_keys",control)
        self.assertIn("BlueVPN_Shahrah::plan_exists",control)
        self.assertIn("provider_routes_json",control)
        self.assertIn("ابتدا شاهراه را همگام کن",control)

    def test_runtime_provisions_and_reads_remote_customer_service(self):
        providers=self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        shahrah=self.text("bluevpn-manager/includes/class-bluevpn-shahrah.php")
        for token in ["provision_panel","configs_for_panel_customer","inspect_panel_customer","panel_mapping"]:
            self.assertIn(token,shahrah)
        self.assertIn("BlueVPN_Shahrah::provision_panel",providers)
        self.assertIn("BlueVPN_Shahrah::configs_for_panel_customer",providers)
        self.assertIn("BlueVPN_Shahrah::inspect_panel_customer",providers)
        self.assertIn("foreach($routes['shahrah'] as $route)",providers)
        self.assertIn("provider_link_upsert",providers)

if __name__=="__main__":
    unittest.main()
