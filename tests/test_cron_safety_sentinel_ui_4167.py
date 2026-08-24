from pathlib import Path
import json
import unittest

ROOT = Path(__file__).resolve().parents[1]

def text(rel: str) -> str:
    return (ROOT / rel).read_text(encoding='utf-8')

class CronSafetySentinelUi4167Tests(unittest.TestCase):
    def test_release_is_4167(self):
        release = json.loads(text('release.json'))
        self.assertEqual(release['version'], '5.5.1')
        self.assertEqual(release['version_code'], 50501)

    def test_bluevpn_never_calls_wordpress_core_spawn_cron_directly(self):
        for rel in [
            'bluevpn-manager/includes/class-bluevpn-production.php',
            'bluevpn-manager/includes/class-bluevpn-migration.php',
            'bluevpn-manager/includes/class-bluevpn-providers.php',
        ]:
            src = text(rel)
            self.assertNotIn("function_exists('spawn_cron')", src, rel)
            self.assertNotIn('spawn_cron(time())', src, rel)
        utils = text('bluevpn-manager/includes/class-bluevpn-utils.php')
        self.assertIn('public static function kick_wp_cron(): bool', utils)
        self.assertIn("'X-BlueVPN-Internal-Cron' => '1'", utils)

    def test_native_cutover_is_revision_idempotent_and_resolves_old_failure(self):
        src = text('bluevpn-manager/includes/class-bluevpn-production.php')
        self.assertIn('NATIVE_CUTOVER_REVISION = 41607', src)
        self.assertIn('$needsRetirementPass', src)
        self.assertIn("resolve_matching('migration', 'native_cutover', 'NATIVE_CUTOVER_FINALIZE_FAILED')", src)

    def test_sentinel_admin_is_mobile_card_ready(self):
        monitor = text('bluevpn-manager/includes/class-bluevpn-error-monitor.php')
        css = text('bluevpn-manager/assets/admin-unified.css')
        js = text('bluevpn-manager/assets/admin-unified.js')
        self.assertIn('bvem-toggle-grid', monitor)
        self.assertIn('bvc-table bvem-events-table', monitor)
        self.assertIn('.bvem-events-table.bvc-responsive-table', css)
        self.assertIn("window.addEventListener('pageshow'", js)
        self.assertIn("setOpen(false)", js)
        self.assertIn('5.2.2 — compact responsive control center', css)
        self.assertNotIn('min-width:960px', css)
        self.assertNotIn('Math.max(980', js)
        self.assertIn("table.classList.toggle('bvc-table-wide',labels.length>=7)", js)
        self.assertIn('min-height:36px!important', css)
        self.assertIn('min-height:32px!important', css)

if __name__ == '__main__':
    unittest.main()
