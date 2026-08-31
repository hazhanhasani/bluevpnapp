import json, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def text(rel): return (ROOT/rel).read_text(encoding='utf-8')

class NativeCutoverRuntimeFixes4166(unittest.TestCase):
    def test_release_version(self):
        r=json.loads(text('release.json'))
        self.assertEqual(r['version'],'6.2.4')
        self.assertEqual(r['version_code'],60204)
        self.assertEqual(json.loads(text('branding/app.json'))['control_plane'],'wordpress_mysql_native')

    def test_ads_payload_has_no_foreign_free_pool_locals(self):
        s=text('bluevpn-manager/includes/class-bluevpn-ads.php')
        start=s.index('public static function advertising_payload')
        end=s.index('/** Backward-compatible aliases',start)
        body=s[start:end]
        self.assertNotIn("$base.'/api/v1/free/curated'",body)
        self.assertNotIn('$mode !==',body)
        self.assertNotIn('$warpEnabled',body)
        self.assertNotIn('$public[]',body)

    def test_native_cutover_is_permanent(self):
        prod=text('bluevpn-manager/includes/class-bluevpn-production.php')
        db=text('bluevpn-manager/includes/class-bluevpn-db.php')
        self.assertIn("NATIVE_CONTROL_PLANE = 'wordpress_mysql_native'",prod)
        self.assertIn('ensure_native_control_plane()',prod)
        self.assertIn("$cfg['source_url'] = '';",prod)
        self.assertIn("$cfg['auto_migrate'] = false;",prod)
        self.assertIn("$cfg['auto_sync'] = false;",prod)
        self.assertIn("update_option('bluevpn_manager_cutover_ready', '1'",db)
        self.assertNotIn("update_option('bluevpn_manager_cutover_ready', '0'",db[:1800])

    def test_pasarguard_expected_fallback_is_not_false_alert(self):
        m=text('bluevpn-manager/includes/class-bluevpn-error-monitor.php')
        p=text('bluevpn-manager/includes/class-bluevpn-providers.php')
        self.assertIn('expect_http_status_once',m)
        self.assertIn('consume_expected_http_status',m)
        self.assertGreaterEqual(p.count("expect_http_status_once($url,[403,404])"),2)

    def test_old_paid_orders_get_one_time_reconcile(self):
        prod=text('bluevpn-manager/includes/class-bluevpn-production.php')
        self.assertIn('reconcile_legacy_paid_orders_once',prod)
        self.assertIn("NATIVE_RECONCILE_HOOK = 'bluevpn_native_cutover_reconcile'",prod)
        self.assertIn('wp_schedule_single_event',prod)
        self.assertIn("trigger_source'=>'native_cutover_reconcile'",prod)
        self.assertIn("status IN ('paid_needs_sync','partial_needs_sync')",prod)

    def test_legacy_migration_runtime_is_retired(self):
        plugin=text('bluevpn-manager/bluevpn-manager.php')
        admin=text('bluevpn-manager/includes/class-bluevpn-admin.php')
        self.assertNotIn('BlueVPN_Migration::init();',plugin)
        self.assertNotIn("BlueVPN_Migration::sync_cron_schedule(!empty",plugin)
        self.assertNotIn("admin_post_bluevpn_migration_save",admin.split('public static function migration_page')[0])
        page=admin[admin.index('public static function migration_page'):admin.index('private static function migration_redirect')]
        self.assertNotIn('Railway',page)
        self.assertIn('وردپرس و پایگاه داده تنها مرکز کنترل فعال',page)

if __name__=='__main__': unittest.main()
