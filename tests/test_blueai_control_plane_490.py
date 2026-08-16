import pathlib, unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]

class BlueAiControlPlane490(unittest.TestCase):
    def text(self,p): return (ROOT/p).read_text()

    def test_android_has_privacy_safe_network_fingerprint(self):
        s=self.text("android-source/BlueVpnIntelligenceCore.kt")
        self.assertIn("NetworkFingerprint",s)
        self.assertIn("operatorHash",s)
        self.assertIn('sha("op:$rawOperator").take(12)',s)
        self.assertNotIn("BSSID",s)
        self.assertNotIn("getMacAddress",s)

    def test_failure_classifier_covers_runtime_and_business_failures(self):
        s=self.text("android-source/BlueVpnIntelligenceCore.kt")
        for code in ["EXIT_IRAN","DNS","UDP_BLOCKED","AETHER","XRAY","TUN","PROVISIONING","PAYMENT","PROCESS_KILLED"]:
            self.assertIn(code,s)

    def test_route_scoring_and_circuit_breaker_are_network_aware(self):
        s=self.text("android-source/BlueVpnIntelligenceCore.kt")
        self.assertIn("routeEvidence",s)
        self.assertIn("quarantineMs",s)
        self.assertIn("networkFingerprint(context).id",s)
        self.assertIn("success",s)
        self.assertIn("jitter",s)
        self.assertIn("loss",s)

    def test_smart_selector_consumes_new_intelligence(self):
        s=self.text("android-source/BlueVpnSmartSelector.kt")
        self.assertIn("BlueVpnIntelligenceCore.routeEvidence",s)
        self.assertIn("score += intelligence.scoreAdjustment",s)
        self.assertIn("recordShadowComparison",s)

    def test_shadow_mode_is_remote_policy_driven(self):
        api=self.text("bluevpn-manager/includes/class-bluevpn-api.php")
        ai=self.text("android-source/BlueVpnAi.kt")
        core=self.text("android-source/BlueVpnIntelligenceCore.kt")
        self.assertIn("'shadow_mode'",api)
        self.assertIn("KEY_SHADOW_MODE",ai)
        self.assertIn("shadowModeEnabled",core)

    def test_predictive_failover_can_switch_premium_route(self):
        live=self.text("android-source/BlueVpnLiveReporter.kt")
        ctrl=self.text("android-source/BlueVpnSystemController.kt")
        self.assertIn("predictiveFailover",live)
        self.assertIn("claimPredictiveFailover",ctrl)
        self.assertIn("MmkvManager.setSelectServer(next.guid)",ctrl)
        self.assertIn("connectionOrderTrusted",ctrl)

    def test_blueai_ops_has_anomaly_and_reconciliation_engines(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-ai-ops.php")
        for fn in ["detect_route_anomalies","detect_payment_provisioning_anomalies","detect_sms_anomalies","detect_stale_live_sessions","reconcile_customers"]:
            self.assertIn(fn,s)
        self.assertIn("repair_customer_missing_providers",s)
        self.assertIn("run_exists",s)

    def test_ai_provider_balancing_is_used_for_unbound_plans(self):
        ops=self.text("bluevpn-manager/includes/class-bluevpn-ai-ops.php")
        providers=self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        self.assertIn("recommend_panel_id",ops)
        self.assertIn("BlueVPN_AI_Ops::recommend_panel_id('pasarguard')",providers)
        self.assertIn("BlueVPN_AI_Ops::recommend_panel_id('marzban')",providers)

    def test_incident_and_reconciliation_tables_are_indexed(self):
        db=self.text("bluevpn-manager/includes/class-bluevpn-db.php")
        self.assertIn("ai_incidents",db)
        self.assertIn("uq_ai_incident_key",db)
        self.assertIn("ai_reconciliation_runs",db)
        self.assertIn("uq_ai_reconcile_run_key",db)

    def test_ops_dashboard_is_integrated(self):
        plugin=self.text("bluevpn-manager/bluevpn-manager.php")
        ai=self.text("bluevpn-manager/includes/class-bluevpn-ai.php")
        self.assertIn("class-bluevpn-ai-ops.php",plugin)
        self.assertIn("BlueVPN_AI_Ops::init()",plugin)
        self.assertIn("BlueVPN_AI_Ops::render_admin()",ai)

    def test_engine_and_schema_versions_advanced(self):
        ai=self.text("bluevpn-manager/includes/class-bluevpn-ai.php")
        plugin=self.text("bluevpn-manager/bluevpn-manager.php")
        self.assertIn("ENGINE_VERSION = '3.0.0'",ai)
        self.assertIn("SCHEMA_VERSION = 5",ai)
        self.assertIn("BLUEVPN_MANAGER_SCHEMA_VERSION', '1.13.0'",plugin)

if __name__=="__main__": unittest.main()
