import pathlib, unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]

class ProviderEntitlement474Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.cc=(ROOT/'bluevpn-manager/includes/class-bluevpn-control-center.php').read_text()
        cls.providers=(ROOT/'bluevpn-manager/includes/class-bluevpn-providers.php').read_text()

    def test_provider_delete_is_registered_and_transactional(self):
        self.assertIn("'bluevpn_cc_delete_provider'=>'delete_provider'", self.cc)
        self.assertIn('public static function delete_provider()', self.cc)
        self.assertIn("$wpdb->query('START TRANSACTION')", self.cc)
        self.assertIn("UPDATE {$plans} SET panel_id=NULL", self.cc)
        self.assertIn("UPDATE {$plans} SET marzban_panel_id=NULL", self.cc)
        self.assertIn("$wpdb->delete($t,['id'=>$id]", self.cc)

    def test_legacy_plan_resolves_active_pasarguard_and_marzban(self):
        self.assertIn("BlueVPN_AI_Ops::recommend_panel_id('pasarguard')", self.providers)
        self.assertIn("pasarguard_panels').\" WHERE active=1", self.providers)
        self.assertIn("BlueVPN_AI_Ops::recommend_panel_id('marzban')", self.providers)
        self.assertIn("marzban_panels').\" WHERE active=1", self.providers)
        self.assertIn("$routes['pasarguard'][]=['panel_id'=>$pg", self.providers)
        self.assertIn("$routes['marzban'][]=['panel_id'=>$mz", self.providers)
        self.assertIn("plan_provider_routes($plan)", self.providers)

    def test_global_subscription_is_a_paid_fallback(self):
        needle="auth_mode='manual' AND global_subscription_url IS NOT NULL AND TRIM(global_subscription_url)<>''"
        self.assertGreaterEqual(self.providers.count(needle),2) # provision + repair
        self.assertIn("$update['guardcore_subscription_url']=esc_url_raw($global)", self.providers)
        self.assertIn("$update['guardcore_status']='active'", self.providers)

    def test_repair_scanner_no_longer_requires_plan_provider_ids(self):
        start=self.providers.index('public static function repairable_customer_count')
        end=self.providers.index('public static function provision_customer', start)
        block=self.providers[start:end]
        self.assertNotIn('p.panel_id IS NOT NULL OR p.marzban_panel_id IS NOT NULL', block)
        self.assertIn("c.subscription_status='active'", block)

if __name__=='__main__': unittest.main()
