import json, pathlib, re, unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]

class BluPalFreePoolPanel4114(unittest.TestCase):
    def text(self,p): return (ROOT/p).read_text()

    def test_bluepay_removed_from_visible_panel_and_api(self):
        admin=self.text('bluevpn-manager/includes/class-bluevpn-admin.php')
        nav=self.text('bluevpn-manager/includes/class-bluevpn-unified-ui.php')
        cc=self.text('bluevpn-manager/includes/class-bluevpn-control-center.php')
        api=self.text('bluevpn-manager/includes/class-bluevpn-api.php')
        self.assertNotIn('BluePay', admin+nav+cc)
        self.assertIn('پرداخت / بلوپال',admin)
        self.assertIn('bluevpn-payments',nav)
        self.assertIn('/webhooks/blupal',api)
        self.assertNotIn('/webhooks/bluepay',api)

    def test_blupal_contract_uses_official_documented_endpoints_and_rial(self):
        s=self.text('bluevpn-manager/includes/class-bluevpn-payments.php')
        self.assertIn("https://blupal.net/api",s)
        self.assertIn("/v1/invoices/create",s)
        self.assertIn("/v1/invoices/",s)
        self.assertIn("'X-API-Key'=>$apiKey",s)
        self.assertIn('$amountRial=$amountToman*10',s)
        self.assertIn("$payload=['amount'=>$amountRial]",s)
        self.assertIn("payment_link",s)
        self.assertIn("invoice_id",s)

    def test_blupal_webhook_is_verified_server_to_server_not_trusted_blindly(self):
        s=self.text('bluevpn-manager/includes/class-bluevpn-payments.php')
        block=s[s.index('public static function webhook'):]
        self.assertIn("$event!=='payment.completed'",block)
        self.assertIn("self::request('GET',$base.'/v1/invoices/'",block)
        self.assertIn("verification_failed",block)
        self.assertIn("amount_mismatch",block)
        self.assertIn("log_payment_event",block)

    def test_payment_database_has_events_and_provision_attempts(self):
        s=self.text('bluevpn-manager/includes/class-bluevpn-db.php')
        self.assertIn("CREATE TABLE {$t('payment_events')}",s)
        self.assertIn("CREATE TABLE {$t('provisioning_attempts')}",s)
        for col in ['payment_provider','payment_mode','amount_rial','final_amount_rial','transaction_id','payer_name','payer_card','payer_bank_name']:
            self.assertIn(col,s)

    def test_admin_can_retry_paid_but_incomplete_provision_without_new_invoice(self):
        s=self.text('bluevpn-manager/includes/class-bluevpn-control-center.php')
        self.assertIn('retry_order_provision',s)
        block=s[s.index('public static function retry_order_provision'):s.index('public static function manual_activate')]
        self.assertIn('BlueVPN_Providers::provision_customer',block)
        self.assertNotIn('BlueVPN_Payments::create',block)
        self.assertIn('provisioning_attempts',block)

    def test_public_telegram_source_collector_is_seeded(self):
        s=self.text('bluevpn-manager/includes/class-bluevpn-free-sources.php')
        self.assertIn("https://t.me/s/persianvpnhub",s)
        self.assertIn('telegram_public',s)
        self.assertIn('wp_remote_get',s)
        self.assertIn("vless",s)
        self.assertIn("vmess",s)
        self.assertIn("trojan",s)

    def test_free_pool_database_has_configs_and_anonymous_probe_reports(self):
        s=self.text('bluevpn-manager/includes/class-bluevpn-db.php')
        self.assertIn("CREATE TABLE {$t('free_config_sources')}",s)
        self.assertIn("CREATE TABLE {$t('free_configs')}",s)
        self.assertIn("CREATE TABLE {$t('free_config_reports')}",s)
        source=self.text('bluevpn-manager/includes/class-bluevpn-free-sources.php')
        self.assertIn("hash('sha256','bluevpn-free:'.$deviceId)",source)
        self.assertIn('network_hash',source)
        self.assertIn('score',source)

    def test_curated_free_pool_is_ranked_by_real_user_reports(self):
        s=self.text('bluevpn-manager/includes/class-bluevpn-free-sources.php')
        self.assertIn('reports_count>=2',s)
        self.assertIn('score DESC',s)
        self.assertIn('successes DESC',s)
        self.assertIn('avg_latency_ms',s)
        self.assertIn('avg_jitter_ms',s)
        self.assertIn('avg_loss_x100',s)

    def test_android_background_optimizer_reports_results_without_stopping_vpn(self):
        opt=self.text('android-source/BlueVpnBackgroundOptimizer.kt')
        account=self.text('android-source/BlueVpnAccountManager.kt')
        self.assertIn('reportFreeConfigProbe',account)
        self.assertIn('/api/v1/free/probes',account)
        self.assertIn('config_id',opt)
        self.assertIn('network_id',opt)
        self.assertIn('loss_x100',opt)
        self.assertNotIn('stopVService',opt)

    def test_plugin_loads_free_source_manager_and_schema_is_118(self):
        s=self.text('bluevpn-manager/bluevpn-manager.php')
        self.assertIn('class-bluevpn-free-sources.php',s)
        self.assertIn('BlueVPN_Free_Sources::init()',s)
        self.assertIn("BLUEVPN_MANAGER_SCHEMA_VERSION', '1.25.0'",s)

if __name__=='__main__': unittest.main()
