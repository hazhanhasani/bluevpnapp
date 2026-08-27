from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]

class FreeSourceTransportResilienceTests(unittest.TestCase):
    def text(self,path):
        return (ROOT/path).read_text(encoding="utf-8")

    def test_transient_telegram_5xx_is_retried_and_not_alerted_immediately(self):
        src=self.text("bluevpn-manager/includes/class-bluevpn-free-sources.php")
        self.assertIn("private const RETRYABLE_HTTP=[408,425,429,500,502,503,504];",src)
        self.assertIn("private const TRANSPORT_ALERT_THRESHOLD=3;",src)
        self.assertIn("$failures>=self::TRANSPORT_ALERT_THRESHOLD",src)
        self.assertIn("consecutive_transport_failures",src)
        self.assertIn("cached_pool_preserved'=>true",src)

    def test_transport_failure_keeps_runtime_cache_and_uses_cooldown(self):
        src=self.text("bluevpn-manager/includes/class-bluevpn-free-sources.php")
        start=src.index("private static function mark_transport_failure")
        end=src.index("private static function mark_source_failure",start)
        body=src[start:end]
        self.assertIn("'last_status'=>'failed_transport'",body)
        self.assertIn("'last_error'=>''",body)
        self.assertIn("set_transient(self::cooldown_key($id)",body)
        self.assertNotIn("DELETE FROM",body)
        self.assertNotIn("active=0",body)

    def test_success_clears_failure_counter_and_existing_alert(self):
        src=self.text("bluevpn-manager/includes/class-bluevpn-free-sources.php")
        start=src.index("private static function mark_source_success")
        end=src.index("public static function refresh_source",start)
        body=src[start:end]
        self.assertIn("delete_transient(self::failure_count_key($id))",body)
        self.assertIn("delete_transient(self::alert_key($id))",body)
        self.assertIn("resolve_matching('runtime','free_sources'",body)

if __name__=="__main__":
    unittest.main()
