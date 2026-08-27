import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def text(rel):
    return (ROOT / rel).read_text(encoding='utf-8')

class DeployManagerSourceFallback4170Tests(unittest.TestCase):
    def test_release_version(self):
        release = json.loads(text('release.json'))
        branding = json.loads(text('branding/app.json'))
        self.assertEqual(release['version'], '6.0.4')
        self.assertEqual(release['version_code'], 60004)
        self.assertEqual(branding['version_name'], '6.0.4')
        self.assertEqual(branding['version_code'], 60004)

    def test_manager_installs_from_validated_project_tree_before_release_dependency(self):
        bot = text('bluevpn-manager/includes/class-bluevpn-telegram-bot.php')
        for token in (
            'install_manager_from_project_tree',
            'copy_tree_atomic_source',
            'manager_local_install',
            'validated_project_zip',
            'MANAGER_ATOMIC_SWAP_FAILED',
            'MANAGER_ATOMIC_VERIFY_FAILED',
        ):
            self.assertIn(token, bot)
        self.assertIn("if ($localManagerOk)", bot)
        self.assertIn('Release مستقل GitHub فعلاً منتشر نشد؛ Deploy متوقف نشد.', bot)

    def test_manager_release_exact_tag_is_eventual_consistency_fallback(self):
        updater = text('bluevpn-manager/includes/class-bluevpn-github-updater.php')
        self.assertIn('release_by_version(string $version', updater)
        self.assertIn("install_latest_now(string $targetVersion = '')", updater)
        self.assertIn('releases/tags/', updater)
        self.assertIn('expect_http_status_once($url, [404])', updater)
        bot = text('bluevpn-manager/includes/class-bluevpn-telegram-bot.php')
        self.assertIn('manager_version_at_commit', bot)
        self.assertIn('install_latest_now($targetManagerVersion)', bot)

    def test_project_root_accepts_wrappers_and_normalizes_versioned_manager_folder(self):
        bot = text('bluevpn-manager/includes/class-bluevpn-telegram-bot.php')
        self.assertIn('$looseManagerDirs', bot)
        self.assertIn('__bluevpn_normalized_manager_root', bot)
        self.assertIn('Plugin Name: BlueVPN Manager', bot)
        self.assertIn('top-level=[', bot)

    def test_unchanged_failed_bot_job_is_not_recounted_each_health_scan(self):
        monitor = text('bluevpn-manager/includes/class-bluevpn-error-monitor.php')
        bot = text('bluevpn-manager/includes/class-bluevpn-telegram-bot.php')
        self.assertIn('operational_row_should_report', monitor)
        self.assertIn('bluevpn_opseen_', monitor)
        self.assertIn('report_bot_job_failure', monitor)
        self.assertIn("BlueVPN_Error_Monitor::report_bot_job_failure", bot)

if __name__ == '__main__':
    unittest.main()
