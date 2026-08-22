import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

class FreeSourceResilience516Tests(unittest.TestCase):
    def text(self,rel):
        return (ROOT/rel).read_text(encoding="utf-8")

    def test_bounded_retry_and_cooldown(self):
        src=self.text("bluevpn-manager/includes/class-bluevpn-free-sources.php")
        for token in (
            "RETRYABLE_HTTP",
            "$timeouts=[6,10,15]",
            "CRON_FAILURE_COOLDOWN_SECONDS",
            "get_transient(self::cooldown_key($id))",
            "set_transient(self::cooldown_key($id)",
        ):
            self.assertIn(token,src)

    def test_sentinel_is_single_owner_for_transport_incident(self):
        src=self.text("bluevpn-manager/includes/class-bluevpn-free-sources.php")
        self.assertIn("'X-BlueVPN-Sentinel-Ignore'=>'1'",src)
        self.assertIn("'last_status'=>'failed_transport'",src)
        self.assertIn("'last_error'=>''",src)
        self.assertIn("FREE_SOURCE_TRANSPORT_FAILED_",src)
        self.assertIn("BlueVPN_Error_Monitor::report(",src)
        self.assertIn("BlueVPN_Error_Monitor::resolve_matching(",src)

    def test_hard_content_failures_still_reach_operational_scan(self):
        src=self.text("bluevpn-manager/includes/class-bluevpn-free-sources.php")
        self.assertIn("private static function mark_source_failure",src)
        self.assertIn("'last_status'=>'failed'",src)
        self.assertIn("mb_substr($message,0,1000)",src)

if __name__=="__main__":
    unittest.main()
