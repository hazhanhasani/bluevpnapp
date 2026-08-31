from pathlib import Path
import json, unittest

ROOT=Path(__file__).resolve().parents[1]

class ShahrahDedicatedProvider60209Tests(unittest.TestCase):
    def text(self,path):
        return (ROOT/path).read_text(encoding="utf-8")

    def test_release_and_schema_contract(self):
        release=json.loads(self.text("release.json"))
        self.assertEqual((release["version"],release["version_code"]),("6.2.9",60209))
        plugin=self.text("bluevpn-manager/bluevpn-manager.php")
        self.assertIn("BLUEVPN_MANAGER_SCHEMA_VERSION', '1.36.0'",plugin)

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

    def test_stale_service_mapping_is_verified_before_repair_or_renew(self):
        shahrah=self.text("bluevpn-manager/includes/class-bluevpn-shahrah.php")
        self.assertIn("service_username_state",shahrah)
        self.assertIn("resolve_owned_service",shahrah)
        self.assertIn("مالکیت سرویس شاهراه قابل تأیید نیست",shahrah)
        repair=shahrah[shahrah.index("private static function repair_without_renew"):shahrah.index("public static function repair_panel_customer")]
        self.assertIn("resolve_owned_service",repair)
        self.assertIn("'action'=>(string)$owned['action']",repair)
        provision=shahrah[shahrah.index("public static function provision("):shahrah.index("private static function locate_service_by_username")]
        panel=shahrah[shahrah.index("public static function provision_panel"):shahrah.index("public static function configs_for_panel_customer")]
        self.assertLess(provision.index("resolve_owned_service"),provision.index("renew_service"))
        self.assertLess(panel.index("resolve_owned_service"),panel.index("renew_service"))

    def test_shahrah_ambiguous_create_is_reconciled_without_blind_post_retry(self):
        shahrah=self.text("bluevpn-manager/includes/class-bluevpn-shahrah.php")
        start=shahrah.index("private static function create_service_with_recovery")
        end=shahrah.index("private static function resolve_owned_service",start)
        helper=shahrah[start:end]
        self.assertIn("transient_create_failure",shahrah)
        self.assertIn("locate_service_by_username($apiKey,$username,1)",helper)
        self.assertIn("RecoveredAfterAmbiguousCreate",helper)
        self.assertIn("POST دوباره ارسال نشد",helper)
        self.assertEqual(helper.count("self::create_service($apiKey,$planSlug,$username)"),1)
        self.assertGreaterEqual(shahrah.count("self::create_service_with_recovery($apiKey,$planSlug,$username)"),3)

    def test_provider_repair_ajax_converts_uncaught_failures_to_json_progress(self):
        control=self.text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        worker=control[control.index("public static function repair_missing_provider_subscriptions"):control.index("public static function retry_order_provision")]
        self.assertIn("PROVIDER_REPAIR_UNCAUGHT",worker)
        self.assertIn("catch(Throwable $e)",worker)
        self.assertIn("const raw=await r.text()",control)
        self.assertIn("پاسخ غیر JSON از سرور",control)

    def test_services_supports_documented_q_filter_and_lookup_uses_it(self):
        shahrah=self.text("bluevpn-manager/includes/class-bluevpn-shahrah.php")
        services=shahrah[shahrah.index("public static function services"):shahrah.index("public static function create_service")]
        locate=shahrah[shahrah.index("private static function locate_service_by_username"):shahrah.index("private static function transient_create_failure")]
        self.assertIn("['limit','page','q','status']",services)
        self.assertIn("'q'=>$username",locate)
        self.assertIn("'limit'=>20",locate)

    def test_non_json_upstream_response_is_actionable_not_invalid_json(self):
        shahrah=self.text("bluevpn-manager/includes/class-bluevpn-shahrah.php")
        request=shahrah[shahrah.index("public static function request"):shahrah.index("public static function me")]
        self.assertIn("NON_JSON_RESPONSE",request)
        self.assertIn("wp_remote_retrieve_header($res, 'content-type')",request)
        self.assertIn("پاسخ معتبر JSON دریافت نشد",shahrah)
        self.assertNotIn("'message' => 'INVALID_JSON'",request)

    def test_transient_q_failure_does_not_fan_out_into_paged_scan(self):
        shahrah=self.text("bluevpn-manager/includes/class-bluevpn-shahrah.php")
        locate=shahrah[shahrah.index("private static function locate_service_by_username"):shahrah.index("private static function transient_create_failure")]
        self.assertIn("if(self::transient_create_failure($filteredError))throw $filteredError",locate)
        request=shahrah[shahrah.index("public static function request"):shahrah.index("public static function me")]
        self.assertIn("usleep(600000*$attempt)",request)

    def test_bulk_repair_paces_shahrah_5xx_and_clears_stale_errors(self):
        control=self.text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        providers=self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        self.assertIn("?2500:650",control)
        self.assertIn("$existing>0||$errors||$update",providers)
        self.assertIn("$update['last_sync_error']=implode(' | ',$errors)",providers)

    def test_shahrah_read_requests_retry_transient_server_failures(self):
        shahrah=self.text("bluevpn-manager/includes/class-bluevpn-shahrah.php")
        request=shahrah[shahrah.index("public static function request"):shahrah.index("public static function me")]
        self.assertIn("$safeRetry=strtoupper($method)==='GET'",request)
        self.assertIn("$retryCode>=500",request)
        self.assertIn("$attempt<3",request)
        self.assertIn("mb_substr($remote, 0, 300)",shahrah)

if __name__=="__main__":
    unittest.main()
