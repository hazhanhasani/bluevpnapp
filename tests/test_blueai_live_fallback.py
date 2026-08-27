from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]

class BlueAiLiveFallbackTests(unittest.TestCase):
    def text(self,path):
        return (ROOT/path).read_text(encoding="utf-8")

    def test_verified_recent_heartbeats_are_live_fallback(self):
        src=self.text("bluevpn-manager/includes/class-bluevpn-ai.php")
        self.assertIn("recent_verified_heartbeat_rows",src)
        self.assertIn("h.event_type='heartbeat' AND h.success=1",src)
        self.assertIn("time()-75",src)
        self.assertIn("newer.event_type<>'heartbeat'",src)
        self.assertIn("'fallback_source'=>'ai_connection_events'",src)

    def test_live_snapshot_merges_without_double_counting(self):
        src=self.text("bluevpn-manager/includes/class-bluevpn-ai.php")
        start=src.index("public static function live_snapshot")
        end=src.index("private static function fmt_bytes",start)
        body=src[start:end]
        self.assertIn("$liveKeys",body)
        self.assertIn("recent_verified_heartbeat_rows($limit)",body)
        self.assertIn("if(isset($liveKeys[$key]))continue",body)

    def test_admin_summary_uses_same_live_snapshot(self):
        src=self.text("bluevpn-manager/includes/class-bluevpn-ai.php")
        start=src.index("public static function stats(): array")
        end=src.index("public static function save_settings",start)
        body=src[start:end]
        self.assertIn("$snapshot=self::live_snapshot(250)",body)
        self.assertIn("$liveCounts=(array)($snapshot['counts']??[])",body)

    def test_live_db_write_failure_is_not_silent(self):
        src=self.text("bluevpn-manager/includes/class-bluevpn-ai.php")
        self.assertIn("LIVE_CONNECTION_WRITE_FAILED",src)
        self.assertIn("BlueVPN_Error_Monitor::report",src)
        self.assertIn("if ($writeOk === false)",src)

if __name__=="__main__":
    unittest.main()
