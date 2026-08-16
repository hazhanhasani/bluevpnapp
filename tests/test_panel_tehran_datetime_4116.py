import pathlib, unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]
class PanelTehranDatetime4116(unittest.TestCase):
    def text(self,p): return (ROOT/p).read_text()
    def test_storage_stays_utc_but_panel_formatter_is_tehran(self):
        s=self.text('bluevpn-manager/includes/class-bluevpn-utils.php')
        self.assertIn("return gmdate('Y-m-d H:i:s');",s)
        self.assertIn("new DateTimeZone('Asia/Tehran')",s)
        self.assertIn('gregorian_to_jalali',s)
        self.assertIn('tehran_date_fa',s)
    def test_formatter_accepts_mysql_iso_and_unix_timestamp(self):
        s=self.text('bluevpn-manager/includes/class-bluevpn-utils.php')
        self.assertIn("new DateTimeImmutable('@'",s)
        self.assertIn("new DateTimeZone('UTC')",s)
        self.assertIn("preg_match('/(?:T|Z$|",s)
    def test_control_center_has_single_local_datetime_helper(self):
        s=self.text('bluevpn-manager/includes/class-bluevpn-control-center.php')
        self.assertIn('private static function dt(',s)
        for marker in ["self::dt($x['created_at'])","self::dt($x['subscription_expire'],false)","self::dt($d['last_seen_at'])","self::dt($ss['expires_at'])","self::dt($o['activated_at'])","self::dt($r['release_published_at'])"]:
            self.assertIn(marker,s)
    def test_sms_and_github_status_do_not_label_display_as_utc(self):
        s=self.text('bluevpn-manager/includes/class-bluevpn-control-center.php')
        self.assertNotIn("self::esc((string)$s['last_test_at']).' UTC'",s)
        self.assertNotIn("self::esc($patternCache['fetched_at']).' UTC'",s)
        self.assertNotIn("self::esc($smartReport['generated_at']).' UTC'",s)
    def test_support_and_ai_admin_dates_use_tehran(self):
        support=self.text('bluevpn-manager/includes/class-bluevpn-support.php')
        ai=self.text('bluevpn-manager/includes/class-bluevpn-ai.php')
        ops=self.text('bluevpn-manager/includes/class-bluevpn-ai-ops.php')
        self.assertIn("tehran_datetime_fa($c['last_message_at'])",support)
        self.assertIn("tehran_datetime_fa($m['created_at'])",support)
        self.assertIn("tehran_datetime_fa($r['updated_at'])",ai)
        self.assertIn("tehran_datetime_fa($r['last_seen_at'])",ops)
    def test_deploy_bot_and_legacy_admin_dates_use_tehran(self):
        bot=self.text('bluevpn-manager/includes/class-bluevpn-telegram-bot.php')
        admin=self.text('bluevpn-manager/includes/class-bluevpn-admin.php')
        self.assertIn("tehran_datetime_fa($j['created_at'])",bot)
        self.assertIn('tehran_datetime_fa((int)$last_bg)',admin)
    def test_live_clock_is_tehran_persian_and_ticks_each_second(self):
        s=self.text('bluevpn-manager/assets/admin-unified.js')
        self.assertIn("calendar:'persian'",s)
        self.assertIn("timeZone:'Asia/Tehran'",s)
        self.assertIn("second:'2-digit'",s)
        self.assertIn('setInterval(tick,1000)',s)
if __name__=='__main__': unittest.main()
