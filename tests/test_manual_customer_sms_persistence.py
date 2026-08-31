from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]

class ManualCustomerSmsPersistenceTests(unittest.TestCase):
    def text(self,path):
        return (ROOT/path).read_text(encoding="utf-8")

    def test_schema_has_manual_customer_sms_owner(self):
        db=self.text("bluevpn-manager/includes/class-bluevpn-db.php")
        plugin=self.text("bluevpn-manager/bluevpn-manager.php")
        self.assertIn("BLUEVPN_MANAGER_SCHEMA_VERSION', '1.36.0'",plugin)
        self.assertIn("manual_customer_id bigint unsigned NULL",db)
        self.assertIn("KEY ix_sms_manual_customer (manual_customer_id, created_at)",db)

    def test_manual_customer_queue_persists_owner_id(self):
        manual=self.text("bluevpn-manager/includes/class-bluevpn-manual-customers.php")
        start=manual.index("private static function queue_customer_event")
        end=manual.index("private static function queue_and_dispatch_customer_event",start)
        body=manual[start:end]
        self.assertIn("(int)$row['id']",body)
        self.assertIn("$force,",body)
        self.assertIn("true,",body)

    def test_sms_queue_writes_manual_customer_id(self):
        sms=self.text("bluevpn-manager/includes/class-bluevpn-sms-notifications.php")
        self.assertIn("?int $manualCustomerId = null",sms)
        self.assertIn("'manual_customer_id'=>$manualCustomerId ?: null",sms)

    def test_manual_history_uses_real_owner_relation_and_safe_backfill(self):
        manual=self.text("bluevpn-manager/includes/class-bluevpn-manual-customers.php")
        self.assertIn("private static function backfill_legacy_sms_owners()",manual)
        self.assertIn("HAVING COUNT(*)=1",manual)
        self.assertIn("d.manual_customer_id IS NULL",manual)
        self.assertIn("d.customer_id IS NULL",manual)
        self.assertIn("WHERE manual_customer_id=%d",manual)
        self.assertIn("manual_customer_id IS NOT NULL",manual)
        self.assertNotIn("dedupe_key LIKE 'manual-customer:%'",manual)
        self.assertNotIn("dedupe_key LIKE %s",manual)

    def test_sent_provider_result_cannot_fail_database_silently(self):
        sms=self.text("bluevpn-manager/includes/class-bluevpn-sms-notifications.php")
        self.assertIn("SMS_SENT_DB_PERSIST_FAILED",sms)
        self.assertIn("sent_unpersisted",sms)
        self.assertIn("if($persisted===false)",sms)

if __name__=="__main__":
    unittest.main()
