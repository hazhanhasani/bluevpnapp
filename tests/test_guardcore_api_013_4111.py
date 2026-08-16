import pathlib
import unittest

ROOT=pathlib.Path(__file__).resolve().parents[1]

class GuardCoreApi0134111(unittest.TestCase):
    def text(self,p):
        return (ROOT/p).read_text()

    def test_guardcore_schema_caches_api013_catalog(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-db.php")
        for col in [
            "nodes_json longtext",
            "capabilities_json longtext",
            "stats_json longtext",
            "api_version varchar(32)",
            "last_sync_at datetime",
        ]:
            self.assertIn(col,s)

    def test_guardcore_auth_supports_api_key_password_and_totp_bootstrap(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        self.assertIn("'X-API-Key'=>$key",s)
        self.assertIn("/api/admins/token'.$query",s)
        self.assertIn("totp_code=",s)
        self.assertIn("guardcore_bootstrap_api_key",s)
        self.assertIn("/api/admins/current",s)
        self.assertIn("'auth_mode'=>'api_key'",s)

    def test_guardcore_catalog_uses_official_013_endpoints(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        for endpoint in [
            "/openapi.json",
            "/api/services",
            "/api/nodes",
            "/api/nodes/stats",
            "/api/subscriptions/stats",
            "/api/stats/subscriptions/status",
            "/api/stats/agents",
            "/api/stats/usage",
            "/api/stats/subscriptions/most_usage",
            "/api/admins/current",
        ]:
            self.assertIn(endpoint,s)

    def test_guardcore_subscription_normalization_uses_new_response_fields(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        block=s[s.index("private static function gc_normalize"):
                s.index("private static function gc_user")]
        for field in [
            "is_active","is_online","online_at","last_request_at",
            "last_client_agent","service_ids","auto_renewals",
            "current_usage","total_usage","reset_usage"
        ]:
            self.assertIn(field,block)

    def test_guardcore_lifecycle_actions_use_official_bulk_endpoints(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        for endpoint in [
            "/api/subscriptions/enable",
            "/api/subscriptions/disable",
            "/api/subscriptions/revoke",
            "/api/subscriptions/reset",
        ]:
            self.assertIn(endpoint,s)
        self.assertIn("guardcore_subscription_action",s)

    def test_expired_bluevpn_entitlement_disables_guardcore(self):
        providers=self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        cron=self.text("bluevpn-manager/includes/class-bluevpn-cron.php")
        self.assertIn("reconcile_guardcore_expiries",providers)
        self.assertIn("'disable'",providers)
        self.assertIn("subscription_expire<%s",providers)
        self.assertIn("reconcile_guardcore_expiries(100)",cron)

    def test_guardcore_plan_service_picker_uses_cached_official_services(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        self.assertIn("guardcore_service_picker",s)
        self.assertIn("guardcore_service_ids_selected[]",s)
        self.assertIn("Serviceهای GuardCore",s)
        self.assertIn("users_count",s)
        self.assertNotIn("GuardCore Service IDs<input",s)

    def test_guardcore_dashboard_has_nodes_stats_reached_and_user_details(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        for marker in [
            "GuardCore API 0.13 — وضعیت زنده",
            "Subscription کل",
            "مصرف ۷ روز",
            "آخرین Subscriptionهای Limit/Expire شده",
            "guardcore_subscription_detail",
            "Usage Log",
        ]:
            self.assertIn(marker,s)

    def test_guardcore_node_and_subscription_controls_are_nonce_protected(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        self.assertIn("bluevpn_cc_guardcore_node_action_",s)
        self.assertIn("bluevpn_cc_guardcore_subscription_action_",s)
        self.assertIn("check_admin_referer('bluevpn_cc_guardcore_node_action_",s)
        self.assertIn("check_admin_referer('bluevpn_cc_guardcore_subscription_action_",s)

    def test_provider_save_does_not_overwrite_live_service_cache_with_manual_json(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        save=s[s.index("public static function save_provider"):
               s.index("private static function sanitize_json")]
        guard=save[save.index("else{"):]
        self.assertNotIn("'services_json'=>self::sanitize_json",guard)

if __name__=="__main__":
    unittest.main()
