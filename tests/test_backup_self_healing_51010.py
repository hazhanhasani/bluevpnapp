from pathlib import Path
import json
import unittest

ROOT = Path(__file__).resolve().parents[1]

class BackupSelfHealing60109Tests(unittest.TestCase):
    def test_release_version(self):
        release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
        self.assertEqual(release["version"], "6.1.9")
        self.assertEqual(release["version_code"], 60109)

    def test_backup_has_state_atomic_write_and_bounded_recovery(self):
        src = (ROOT / "bluevpn-manager/includes/class-bluevpn-production.php").read_text(encoding="utf-8")
        for token in [
            "BACKUP_STATE_OPTION",
            "BACKUP_RECOVERY_LOCK",
            "BACKUP_RECOVERY_RETRY_SECONDS",
            "recover_stale_backup_if_needed",
            "last_attempt_at",
            "last_success_at",
            "health-recovery",
            "$tmp=$path.'.tmp'",
            "@rename($tmp,$path)",
            "stream_payload_to_file",
            "assemble_backup_wrapper",
            "MANUAL_BACKUP_HOOK",
            "queue_manual_backup",
            "['queued','running']",
            "BACKUP_STALE",
            "BACKUP_RECOVERY_RUNNING",
            "BACKUP_RECOVERY_FAILED",
            "BACKUP_CRON_OVERDUE",
            "schedule_repaired_at",
        ]:
            self.assertIn(token, src)
        self.assertNotIn("update_option(self::BACKUP_OPTION, ['ok'=>false", src)

    def test_sentinel_attempts_recovery_before_reporting_backup_health(self):
        src = (ROOT / "bluevpn-manager/includes/class-bluevpn-error-monitor.php").read_text(encoding="utf-8")
        recovery = src.index("BlueVPN_Production::recover_stale_backup_if_needed();")
        health = src.index("BlueVPN_Production::health_summary();")
        self.assertLess(recovery, health)

if __name__ == "__main__":
    unittest.main()
