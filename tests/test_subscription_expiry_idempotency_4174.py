import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def text(rel):
    return (ROOT / rel).read_text(encoding='utf-8')

class SubscriptionExpiryIdempotency4174Tests(unittest.TestCase):
    def test_release_version(self):
        r=json.loads(text('release.json'))
        self.assertEqual(r['version'],'5.6.9')
        self.assertEqual(r['version_code'],50609)
        self.assertIn('wordpress-canonical-entitlement-expiry',r['features'])
        self.assertIn('historical-duplicate-provision-expiry-repair',r['features'])

    def test_provider_sync_never_overwrites_canonical_expiry(self):
        s=text('bluevpn-manager/includes/class-bluevpn-providers.php')
        self.assertNotIn("$u['subscription_expire']=max($valid)",s)
        self.assertIn('WordPress/MySQL is the only entitlement-expiry authority',s)
        self.assertIn("'SUBSCRIPTION_EXPIRY_DRIFT'",s)
        self.assertIn('enforce_provider_expiry',s)

    def test_provisioning_is_idempotent_by_default(self):
        s=text('bluevpn-manager/includes/class-bluevpn-providers.php')
        self.assertIn('provision_customer(int $customerId,int $planId,?string $canonicalExpire=null)',s)
        self.assertIn('canonical_expiry($c,$plan,$canonicalExpire)',s)
        self.assertIn('if(!$extend&&$current)return $current;',s)
        self.assertIn("'expiry_source'=>'wordpress_mysql_entitlement'",s)

    def test_paid_order_snapshots_one_target_expiry(self):
        s=text('bluevpn-manager/includes/class-bluevpn-payments.php')
        self.assertIn("_bluevpn_entitlement_target_expire",s)
        self.assertIn('BlueVPN_Providers::next_entitlement_expiry',s)
        self.assertIn('provision_customer((int)$order[\'customer_id\'], (int)$order[\'plan_id\'], $targetExpiry',s)

    def test_retry_paths_do_not_extend_entitlement(self):
        production=text('bluevpn-manager/includes/class-bluevpn-production.php')
        control=text('bluevpn-manager/includes/class-bluevpn-control-center.php')
        self.assertIn('BlueVPN_Providers::provision_customer($customerId,$planId);',production)
        self.assertIn("BlueVPN_Providers::provision_customer((int)$order['customer_id'],(int)$order['plan_id'])",control)
        self.assertIn('next_entitlement_expiry($customerId,$planId)',control)

    def test_historical_inflation_repair_is_conservative_and_one_shot(self):
        s=text('bluevpn-manager/includes/class-bluevpn-providers.php')
        self.assertIn("bluevpn_expiry_inflation_repair_4174",s)
        self.assertIn('repair_duplicate_provision_expiry_inflation',s)
        self.assertIn("$candidate<=time()",s)
        self.assertIn("'SUBSCRIPTION_EXPIRY_INFLATION_REPAIRED'",s)
        prod=text('bluevpn-manager/includes/class-bluevpn-production.php')
        self.assertIn('repair_duplicate_provision_expiry_inflation()',prod)

    def test_account_api_marks_expiry_source_canonical(self):
        s=text('bluevpn-manager/includes/class-bluevpn-auth.php')
        self.assertIn("'expire_source' => 'wordpress_mysql_entitlement'",s)
        self.assertIn("'provider_expiry_authoritative' => false",s)

if __name__ == '__main__':
    unittest.main()
