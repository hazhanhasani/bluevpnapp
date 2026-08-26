import json
import unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def text(p): return (ROOT/p).read_text()

class SubscriptionExpiryRepair4175Tests(unittest.TestCase):
    def test_release(self):
        r=json.loads(text('release.json'))
        self.assertEqual(r['version'],'5.10.6')
        self.assertEqual(r['version_code'],51006)
        self.assertIn('entitlement-expiry-ledger',r['features'])
        self.assertIn('legacy-non-grant-expiry-repair',r['features'])
        self.assertIn('android-server-remaining-seconds-countdown',r['features'])

    def test_ledger_schema(self):
        s=text('bluevpn-manager/includes/class-bluevpn-db.php')
        self.assertIn("'entitlement_ledger'",s)
        self.assertIn("CREATE TABLE {$t('entitlement_ledger')}",s)
        self.assertIn('intentional_grant',s)
        self.assertIn('target_expire',s)
        plugin=text('bluevpn-manager/bluevpn-manager.php')
        self.assertIn("BLUEVPN_MANAGER_SCHEMA_VERSION', '1.31.0'",plugin)

    def test_non_grant_legacy_repair(self):
        s=text('bluevpn-manager/includes/class-bluevpn-providers.php')
        self.assertIn('repair_legacy_non_grant_expiry_inflation',s)
        self.assertIn("'native_cutover_reconcile','admin_retry'",s)
        self.assertIn("expiry_source']??'')==='wordpress_mysql_entitlement'",s)
        self.assertIn("SUBSCRIPTION_EXPIRY_LEGACY_REPAIR_4175",s)
        prod=text('bluevpn-manager/includes/class-bluevpn-production.php')
        self.assertIn('repair_legacy_non_grant_expiry_inflation()',prod)

    def test_intentional_grants_are_ledgered(self):
        pay=text('bluevpn-manager/includes/class-bluevpn-payments.php')
        ctl=text('bluevpn-manager/includes/class-bluevpn-control-center.php')
        self.assertIn("record_entitlement_ledger",pay)
        self.assertIn("'payment'",pay)
        self.assertIn("'intentional_grant'",pay)
        self.assertIn("record_entitlement_ledger",ctl)
        self.assertIn("'admin'",ctl)

    def test_android_uses_server_remaining_seconds(self):
        acct=text('android-source/BlueVpnAccountManager.kt')
        home=text('android-source/BlueVpnHomeActivity.kt')
        self.assertIn('val remainingSeconds: Long?',acct)
        self.assertIn('"remaining_seconds"',acct)
        self.assertIn('formatCanonicalRemainingTime',home)
        self.assertIn('managed.remainingSeconds',home)

if __name__=='__main__': unittest.main()
