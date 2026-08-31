from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class PasarGuardV53Integration624Tests(unittest.TestCase):
    def text(self, path):
        return (ROOT / path).read_text(encoding="utf-8")

    def test_pasarguard_v53_contract_is_declared_and_persisted(self):
        providers = self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        db = self.text("bluevpn-manager/includes/class-bluevpn-db.php")
        plugin = self.text("bluevpn-manager/bluevpn-manager.php")
        self.assertIn("'target_release'=>'5.3.0'", providers)
        self.assertIn("'api_version'=>$contract==='v5-id-rbac'?'5.x':'legacy'", providers)
        self.assertIn("'api_contract'=>$contract", providers)
        self.assertIn("api_contract varchar(40)", db)
        self.assertIn("capabilities_json longtext", db)
        self.assertIn("BLUEVPN_MANAGER_SCHEMA_VERSION', '1.37.0'", plugin)

    def test_v5_api_key_and_rbac_safe_group_catalog(self):
        providers = self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        self.assertIn("return ['X-Api-Key'=>$key];", providers)
        self.assertIn("'/api/groups/simple'", providers)
        self.assertLess(
            providers.index("foreach(['/api/groups/simple','/api/groups']"),
            providers.index("private static function pg_active_group_ids"),
        )
        active = providers[providers.index("private static function pg_active_group_ids"):]
        self.assertIn("foreach(['/api/groups/simple','/api/groups'] as $path)", active)
        self.assertIn("groups.read_simple", providers)
        self.assertIn("expect_http_status_once($simpleUrl,[403,404,405])", providers)

    def test_user_operations_are_id_first_with_legacy_fallback(self):
        providers = self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        user = providers[providers.index("private static function pg_user("):providers.index("private static function mz_user(")]
        self.assertIn("'/api/user/by-id/'.$remoteId", user)
        self.assertIn("'/api/user/by-username/'.rawurlencode($username)", user)
        self.assertIn("private static function pg_update_user(", user)
        self.assertLess(user.index("'/api/user/by-id/'.$remoteId"), user.index("'/api/user/by-username/'.rawurlencode($username)"))
        self.assertIn("max(0,(int)($link['remote_id']??0))", providers)
        self.assertIn("max(0,(int)($c['pg_user_id']??0))", providers)

    def test_device_limit_maps_directly_to_hwid_limit(self):
        providers = self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        self.assertIn("private static function pg_hwid_limit(int $deviceLimit): int", providers)
        self.assertIn("return max(1,$deviceLimit);", providers)
        self.assertIn("'hwid_limit'=>self::pg_hwid_limit($deviceLimit)", providers)
        self.assertNotIn("$deviceLimit<=1?1:2", providers)

    def test_deprecated_vless_user_flow_is_never_forwarded(self):
        providers = self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        block = providers[providers.index("private static function pg_proxy_settings"):providers.index("private static function mz_normalize_proxies")]
        self.assertIn("if(strtolower($proto)==='vless')unset($settings['flow']);", block)
        self.assertIn("PasarGuard v5 removed per-user vless.flow", block)

    def test_admin_ui_explains_v53_permissions_and_detected_contract(self):
        cc = self.text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        self.assertIn("PasarGuard v5.3.0", cc)
        self.assertIn("users.read", cc)
        self.assertIn("users.create", cc)
        self.assertIn("users.update", cc)
        self.assertIn("groups.read_simple", cc)
        self.assertIn("api_contract", cc)


if __name__ == "__main__":
    unittest.main()
