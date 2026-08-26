import json
import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]

def text(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")

class StabilityAutoRecovery517Tests(unittest.TestCase):
    def test_connection_engine_v2_exposes_explicit_phases_and_recovery(self):
        gate = (ROOT / "android-source/BlueVpnRuntimeGate.kt").read_text(encoding="utf-8")
        recovery = (ROOT / "android-source/BlueVpnNetworkRecoveryManager.kt").read_text(encoding="utf-8")
        audit = (ROOT / "android-source/BlueVpnRuntimeAudit.kt").read_text(encoding="utf-8")
        for phase in ("IDLE", "PREPARING", "CONNECTING", "VERIFYING", "CONNECTED", "RECOVERING", "FAILED"):
            self.assertIn(phase, gate)
        self.assertIn("markRecovering", recovery)
        self.assertIn("physical_network_lost", recovery)
        self.assertIn("CONNECTION_PHASE", audit)
        self.assertIn("CONTROL_PLANE_FAILOVER", audit)

    def test_release_is_517(self):
        r = json.loads(text("release.json"))
        self.assertEqual(r["version"], "5.10.1")
        self.assertEqual(r["version_code"], 51001)
        self.assertIn("sentinel-self-option-false-positive-fix", r["features"])
        self.assertIn("bot-stale-job-watchdog-auto-unlock", r["features"])
        self.assertIn("manager-first-boot-mu-rollback-guard", r["features"])

    def test_sentinel_does_not_report_its_settings_as_error(self):
        monitor = text("bluevpn-manager/includes/class-bluevpn-error-monitor.php")
        self.assertIn("if ($name === self::OPTION) return false;", monitor)
        self.assertIn("option_has_explicit_error_signal", monitor)
        self.assertIn("BLUEVPN_ERROR_MONITOR_SETTINGS", monitor)
        self.assertIn("resolve_matching('wordpress_option', 'control_plane'", monitor)
        self.assertIn("['error','last_error','error_message','exception','fatal']", monitor)

    def test_bot_has_bounded_stale_job_recovery(self):
        bot = text("bluevpn-manager/includes/class-bluevpn-telegram-bot.php")
        self.assertIn("LOCAL_JOB_STALE_SECONDS = 20 * MINUTE_IN_SECONDS", bot)
        self.assertIn("REMOTE_JOB_STALE_SECONDS = 3 * HOUR_IN_SECONDS", bot)
        self.assertIn("QUEUED_JOB_RETRY_SECONDS = 5 * MINUTE_IN_SECONDS", bot)
        self.assertIn("recover_stale_jobs($s)", bot)
        self.assertIn("BOT_JOB_WATCHDOG_TIMEOUT", bot)
        self.assertIn("self::schedule_process((string)$job['id'])", bot)
        self.assertIn("'queued','retry','downloading'", bot)

    def test_webhook_is_self_healed_without_dropping_updates(self):
        bot = text("bluevpn-manager/includes/class-bluevpn-telegram-bot.php")
        self.assertIn("repair_webhook_if_needed($s)", bot)
        self.assertIn("getWebhookInfo", bot)
        self.assertIn("self::set_webhook();", bot)
        self.assertIn("'drop_pending_updates' => 'false'", bot)

    def test_manager_direct_install_is_fail_closed_and_first_boot_rollbackable(self):
        bot = text("bluevpn-manager/includes/class-bluevpn-telegram-bot.php")
        self.assertIn("preflight_manager_stage($stage)", bot)
        self.assertIn("MANAGER_PREFLIGHT_PHP_LINT_FAILED", bot)
        self.assertIn("release_php_manifest.json", bot)
        self.assertIn("MANAGER_PREFLIGHT_DEPENDENCY_MISSING", bot)
        self.assertIn("arm_manager_boot_recovery_guard", bot)
        self.assertIn("bluevpn-manager-recovery.php", bot)
        self.assertIn("register_shutdown_function", bot)
        self.assertIn("plugins_loaded", bot)
        self.assertIn("BlueVPN Manager boot recovery restored", bot)
        self.assertIn("disarm_manager_boot_recovery_guard", bot)

if __name__ == "__main__":
    unittest.main()
