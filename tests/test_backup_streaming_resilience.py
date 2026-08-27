from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]

class BackupStreamingResilienceTests(unittest.TestCase):
    def text(self,path):
        return (ROOT/path).read_text(encoding="utf-8")

    def test_large_backup_streams_tables_in_chunks(self):
        src=self.text("bluevpn-manager/includes/class-bluevpn-production.php")
        self.assertIn("stream_payload_to_file",src)
        self.assertIn("LIMIT %d OFFSET %d",src)
        self.assertIn("$chunk=250",src)
        self.assertIn("assemble_backup_wrapper",src)

    def test_create_backup_does_not_build_full_json_in_memory(self):
        src=self.text("bluevpn-manager/includes/class-bluevpn-production.php")
        start=src.index("public static function create_backup")
        end=src.index("public static function cron_backup",start)
        body=src[start:end]
        self.assertNotIn("canonical_payload()",body)
        self.assertNotIn("encode_backup(",body)
        self.assertNotIn("file_put_contents($tmp, $json",body)
        self.assertIn("stream_payload_to_file($coreTmp)",body)

    def test_manual_admin_backup_is_queued(self):
        cc=self.text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        start=cc.index("public static function create_private_backup")
        end=cc.index("public static function restore_backup",start)
        body=cc[start:end]
        self.assertIn("queue_manual_backup()",body)
        self.assertNotIn("create_backup('manual-admin')",body)

    def test_manual_worker_and_health_understand_queued_state(self):
        src=self.text("bluevpn-manager/includes/class-bluevpn-production.php")
        self.assertIn("MANUAL_BACKUP_HOOK",src)
        self.assertIn("manual_backup_worker",src)
        self.assertIn("['queued','running']",src)
        self.assertIn("'last_attempt_state'=>'queued'",src)

if __name__=="__main__":
    unittest.main()
