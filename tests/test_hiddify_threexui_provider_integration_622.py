from pathlib import Path
import json
import unittest

ROOT = Path(__file__).resolve().parents[1]


class HiddifyThreeXuiProviderIntegration622Tests(unittest.TestCase):
    def text(self, path):
        return (ROOT / path).read_text(encoding="utf-8")

    def test_schema_and_loaders_are_first_class(self):
        db = self.text("bluevpn-manager/includes/class-bluevpn-db.php")
        plugin = self.text("bluevpn-manager/bluevpn-manager.php")
        self.assertIn("'hiddify_panels'", db)
        self.assertIn("'threexui_panels'", db)
        self.assertIn("CREATE TABLE {$t('hiddify_panels')}", db)
        self.assertIn("CREATE TABLE {$t('threexui_panels')}", db)
        self.assertIn("api_key_enc longtext", db)
        self.assertIn("api_token_enc longtext", db)
        self.assertNotIn("session_cookie", db)
        self.assertIn("BLUEVPN_MANAGER_SCHEMA_VERSION', '1.36.0'", plugin)
        self.assertIn("class-bluevpn-hiddify.php", plugin)
        self.assertIn("class-bluevpn-threexui.php", plugin)

    def test_hiddify_v2_adapter_contract(self):
        src = self.text("bluevpn-manager/includes/class-bluevpn-hiddify.php")
        self.assertIn("'Hiddify-API-Key'=>self::api_key($panel)", src)
        self.assertIn("'/api/v2/admin'", src)
        self.assertIn("function provision(", src)
        self.assertIn("function enforce_expiry(", src)
        self.assertIn("function catalog(", src)
        self.assertIn("HIDDIFY_APPLY_USERS_EVENTUAL_CONSISTENCY", src)
        self.assertNotIn("/api/v1/user/", src)

    def test_threexui_clients_api_contract(self):
        src = self.text("bluevpn-manager/includes/class-bluevpn-threexui.php")
        self.assertIn("'Authorization'=>'Bearer '.$token", src)
        self.assertIn("'/login'", src)
        self.assertIn("'/panel/api/inbounds/list'", src)
        self.assertIn("'/panel/api/clients/add'", src)
        self.assertIn("'/panel/api/clients/update/'", src)
        self.assertIn("'/panel/api/clients/get/'", src)
        self.assertIn("'/panel/api/clients/links/'", src)
        self.assertIn("'/panel/api/clients/'.rawurlencode($email).'/attach'", src)
        self.assertIn("'/panel/api/clients/'.rawurlencode($email).'/detach'", src)
        self.assertIn("['inboundIds'=>$attach]", src)
        self.assertIn("['inboundIds'=>$detach]", src)
        self.assertIn("function provision(", src)
        self.assertIn("function enforce_expiry(", src)

    def test_provider_pipeline_provisions_syncs_repairs_and_repairs_expiry(self):
        src = self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        self.assertIn("'hiddify'=>[]", src)
        self.assertIn("'threexui'=>[]", src)
        self.assertIn("BlueVPN_Hiddify::provision", src)
        self.assertIn("BlueVPN_ThreeXUI::provision", src)
        self.assertIn("BlueVPN_Hiddify::user($panelId,$uuid)", src)
        self.assertIn("BlueVPN_ThreeXUI::inspect($panelId,$username)", src)
        self.assertIn("BlueVPN_Hiddify::enforce_expiry", src)
        self.assertIn("BlueVPN_ThreeXUI::enforce_expiry", src)
        self.assertGreaterEqual(src.count("foreach($routes['hiddify']"), 2)
        self.assertGreaterEqual(src.count("foreach($routes['threexui']"), 2)
        self.assertIn("count($routes['hiddify'])+count($routes['threexui'])", src)

    def test_admin_and_plan_routing_are_exposed_cleanly(self):
        cc = self.text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        admin = self.text("bluevpn-manager/includes/class-bluevpn-admin.php")
        ui = self.text("bluevpn-manager/includes/class-bluevpn-unified-ui.php")
        self.assertIn("'hiddify'=>'سرور هیدیفای'", cc)
        self.assertIn("'threexui'=>'سرور 3x-ui'", cc)
        self.assertIn("'hiddify'=>'hiddify_panels'", cc)
        self.assertIn("'threexui'=>'threexui_panels'", cc)
        self.assertIn("hiddify_panel_ids", cc)
        self.assertIn("threexui_panel_ids", cc)
        self.assertIn("threexui_inbound_ids_selected[", cc)
        self.assertIn("سرورهای هیدیفای", cc)
        self.assertIn("سرورهای 3x-ui", cc)
        self.assertIn("bluevpn-hiddify", admin)
        self.assertIn("bluevpn-threexui", admin)
        self.assertIn("bluevpn-hiddify", ui)
        self.assertIn("bluevpn-threexui", ui)

    def test_secrets_are_encrypted_or_runtime_only(self):
        cc = self.text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        xui = self.text("bluevpn-manager/includes/class-bluevpn-threexui.php")
        self.assertIn("BlueVPN_Utils::encrypt_secret", cc)
        self.assertIn("'api_key_enc'=>$secret('api_key','api_key_enc')", cc)
        self.assertIn("'api_token_enc'=>$secret('api_token','api_token_enc')", cc)
        self.assertIn("private static array $sessionHeaders=[]", xui)
        self.assertNotIn("session_cookie_enc", cc)


if __name__ == "__main__":
    unittest.main()
