import json
import pathlib
import unittest

ROOT=pathlib.Path(__file__).resolve().parents[1]

class GlobalSentinel4164(unittest.TestCase):
    def test_release_version_and_schema(self):
        release=json.loads((ROOT/'release.json').read_text())
        self.assertEqual(release['version'],'5.10.6')
        self.assertEqual(release['version_code'],51006)
        plugin=(ROOT/'bluevpn-manager/bluevpn-manager.php').read_text()
        self.assertIn("BLUEVPN_MANAGER_SCHEMA_VERSION', '1.31.0",plugin)
        self.assertIn("class-bluevpn-error-monitor.php",plugin)
        self.assertIn('BlueVPN_Error_Monitor::bootstrap()',plugin)

    def test_runtime_monitor_covers_major_surfaces(self):
        s=(ROOT/'bluevpn-manager/includes/class-bluevpn-error-monitor.php').read_text()
        for token in [
            'set_error_handler','register_shutdown_function','http_api_debug','rest_request_after_callbacks',
            'wp_mail_failed','automatic_updates_complete','upgrader_process_complete','WPDB_QUERY_ERROR',
            'PROVISIONING_FAILED','SMS_DELIVERY_FAILED','PANEL_HEALTH_FAILED','ORDER_ACTIVATION_ERROR',
            'CRON_EVENT_OVERDUE','error_events','support_notify','sanitize_context','dedup_seconds'
        ]:
            self.assertIn(token,s)

    def test_database_has_persistent_error_event_store(self):
        s=(ROOT/'bluevpn-manager/includes/class-bluevpn-db.php').read_text()
        self.assertIn("'error_events'",s)
        self.assertIn("CREATE TABLE {$t('error_events')}",s)
        for col in ['fingerprint char(64)','occurrences bigint unsigned','last_notified_at datetime','resolved_at datetime']:
            self.assertIn(col,s)

    def test_all_github_workflows_are_watched(self):
        sentinel=(ROOT/'.github/workflows/bluevpn-sentinel.yml').read_text()
        for workflow in [
            'Build Signed BlueVPN APK','Build BlueVPN Windows','Release BlueVPN Manager',
            'Release BlueVPN Site Theme','BlueVPN Project Health','BlueVPN External Health'
        ]:
            self.assertIn(workflow,sentinel)
        for token in ['workflow_run','annotation_level','--log-failed','TELEGRAM_BOT_TOKEN','TELEGRAM_CHAT_ID']:
            self.assertIn(token,sentinel)

    def test_deploy_bot_build_failure_is_not_double_reported(self):
        monitor=(ROOT/'bluevpn-manager/includes/class-bluevpn-error-monitor.php').read_text()
        self.assertIn("$logical === 'bot_jobs'", monitor)
        self.assertIn("(string)($row['kind'] ?? '') === 'deploy_zip'", monitor)
        self.assertIn("/^Build:\\s*(?:failure|cancelled|timed_out|action_required|startup_failure|stale)\\b/i", monitor)

    def test_project_health_is_full_project_gate(self):
        s=(ROOT/'.github/workflows/project-health.yml').read_text()
        for token in ['Reject merge-conflict markers','PHP syntax','Python syntax','JavaScript syntax','JSON YAML XML and XAML syntax','validate_release.py','validate_windows.py','unittest discover']:
            self.assertIn(token,s)

    def test_external_health_covers_wordpress_and_database_backed_health(self):
        s=(ROOT/'.github/workflows/external-health.yml').read_text()
        self.assertIn("cron: '*/5 * * * *'",s)
        self.assertIn("/health",s)
        self.assertIn("/wp-json/bluevpn-system/v1/health",s)
        self.assertIn("data.get('status')!='ok'",s)

    def test_legacy_plugin_and_theme_errors_bridge_to_sentinel(self):
        api=(ROOT/'bluevpn-manager/includes/class-bluevpn-api.php').read_text()
        elementor=(ROOT/'bluevpn-site/inc/class-bluevpn-elementor.php').read_text()
        functions=(ROOT/'bluevpn-site/functions.php').read_text()
        self.assertIn('BlueVPN_Error_Monitor::legacy_error_log',api)
        self.assertIn('bluevpn_site_error_log',elementor)
        self.assertIn("BlueVPN_Error_Monitor::report('theme'",functions)


    def test_browser_side_panel_and_theme_errors_are_reported(self):
        monitor=(ROOT/'bluevpn-manager/includes/class-bluevpn-error-monitor.php').read_text()
        admin=(ROOT/'bluevpn-manager/assets/admin-unified.js').read_text()
        site=(ROOT/'bluevpn-site/assets/js/site.js').read_text()
        ui=(ROOT/'bluevpn-manager/includes/class-bluevpn-unified-ui.php').read_text()
        for token in ['monitor/client-error','client_token','JS_RUNTIME_ERROR','JS_UNHANDLED_REJECTION','set_transient']:
            self.assertIn(token,monitor)
        for js in (admin,site):
            self.assertIn("addEventListener('error'",js)
            self.assertIn("addEventListener('unhandledrejection'",js)
            self.assertIn('monitorEndpoint',js)
        self.assertIn('bluevpn-error-monitor',ui)

    def test_sentinel_telegram_keeps_html_well_formed_and_falls_back_plain_text(self):
        sentinel=(ROOT/'.github/workflows/bluevpn-sentinel.yml').read_text()
        self.assertIn('max_chunk=2800', sentinel)
        self.assertIn("chunks=[report[i:i+max_chunk]", sentinel)
        self.assertIn("'<pre>'+html.escape(chunk)+'</pre>'", sentinel)
        self.assertIn('if e.code==400', sentinel)
        self.assertIn("'disable_web_page_preview':'true'", sentinel)
        self.assertNotIn("</pre>{link}')[:3900]", sentinel)

if __name__=='__main__': unittest.main()
