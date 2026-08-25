import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

class SentinelHealthActionability4165(unittest.TestCase):
    def test_release_version(self):
        release = json.loads((ROOT/'release.json').read_text())
        self.assertEqual(release['version'], '5.6.4')
        self.assertEqual(release['version_code'], 50604)

    def test_telegram_uses_iran_time_and_health_classification(self):
        monitor = (ROOT/'bluevpn-manager/includes/class-bluevpn-error-monitor.php').read_text()
        self.assertIn("BlueVPN_Utils::tehran_datetime_fa()", monitor)
        self.assertIn("زمان ایران:", monitor)
        self.assertIn("هشدار سلامت", monitor)
        self.assertIn("خطای اجرایی", monitor)
        self.assertIn("health_context_lines", monitor)

    def test_payments_are_actionable(self):
        production = (ROOT/'bluevpn-manager/includes/class-bluevpn-production.php').read_text()
        self.assertIn("PAYMENT_STUCK_ORDERS", production)
        self.assertIn("order_code", production)
        self.assertIn("age_minutes", production)
        self.assertIn("created_at_fa", production)
        self.assertIn("paid_needs_sync", production)
        self.assertIn("partial_needs_sync", production)
        self.assertIn("پرداخت / بلوپال", production)

    def test_cutover_is_native_and_not_a_pending_migration(self):
        production = (ROOT/'bluevpn-manager/includes/class-bluevpn-production.php').read_text()
        self.assertIn("WORDPRESS_NATIVE_CONTROL_PLANE", production)
        self.assertIn("wordpress_mysql_native", production)
        self.assertIn("legacy_bridge_disabled", production)
        self.assertIn("migration_cutover_ready", production)
        self.assertIn("app_cutover_enabled", production)
        self.assertNotIn("CUTOVER_NOT_CONFIRMED", production)

    def test_health_recovery_auto_resolves(self):
        monitor = (ROOT/'bluevpn-manager/includes/class-bluevpn-error-monitor.php').read_text()
        self.assertIn("resolve_health_component", monitor)
        self.assertIn("source='health'", monitor)
        self.assertIn("status='resolved'", monitor)

if __name__ == '__main__':
    unittest.main()
