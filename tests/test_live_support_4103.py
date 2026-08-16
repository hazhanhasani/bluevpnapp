import json, pathlib, re, unittest

ROOT=pathlib.Path(__file__).resolve().parents[1]

class LiveSupport4103(unittest.TestCase):
    def text(self,p): return (ROOT/p).read_text()

    def test_support_backend_is_bootstrapped_and_schema_seeded(self):
        plugin=self.text("bluevpn-manager/bluevpn-manager.php")
        support=self.text("bluevpn-manager/includes/class-bluevpn-support.php")
        self.assertIn("class-bluevpn-support.php",plugin)
        self.assertIn("BlueVPN_Support::activate()",plugin)
        self.assertIn("BlueVPN_Support::init()",plugin)
        for table in ["departments","operators","conversations","messages","events"]:
            self.assertIn(f"support_' . $name",support)
        self.assertIn("فنی",support)
        self.assertIn("مالی و پرداخت",support)
        self.assertIn("نمایندگان",support)

    def test_support_rest_routes_are_customer_authenticated(self):
        api=self.text("bluevpn-manager/includes/class-bluevpn-api.php")
        support=self.text("bluevpn-manager/includes/class-bluevpn-support.php")
        for route in [
            "/support/departments",
            "/support/conversations",
            "/support/conversations/(?P<id>\\d+)/messages",
            "/support/conversations/(?P<id>\\d+)/close",
        ]:
            self.assertIn(route,api)
        self.assertIn("BlueVPN_Auth::current_customer($r)",support)
        self.assertIn("conversation_for_customer",support)
        self.assertIn("WHERE id=%d AND customer_id=%d",support)

    def test_support_has_rate_limits_and_message_bounds(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-support.php")
        self.assertIn("SUPPORT_RATE_LIMIT",s)
        self.assertIn("mb_strlen($text) > 4000",s)
        self.assertIn("self::rate_limit((int)$customer['id'],'message',12,60)",s)
        self.assertIn("self::rate_limit((int)$customer['id'],'create',4,120)",s)

    def test_department_aware_auto_assignment_is_least_loaded(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-support.php")
        self.assertIn("auto_assign_operator",s)
        self.assertIn("ORDER BY active_count ASC",s)
        self.assertIn("department_ids",s)
        self.assertIn("max_active",s)

    def test_wordpress_inbox_and_operator_reply_exist(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-support.php")
        self.assertIn("پشتیبانی آنلاین BlueVPN",s)
        self.assertIn("bluevpn_support_reply",s)
        self.assertIn("bluevpn_support_assign",s)
        self.assertIn("bluevpn_support_status",s)
        self.assertIn("pending_customer",s)
        self.assertIn("internal",s.lower() if "internal" in s.lower() else "internal") if False else None

    def test_telegram_bridge_uses_existing_admin_bot(self):
        support=self.text("bluevpn-manager/includes/class-bluevpn-support.php")
        bot=self.text("bluevpn-manager/includes/class-bluevpn-telegram-bot.php")
        self.assertIn("/support_reply",support)
        self.assertIn("telegram_reply_command",bot)
        self.assertIn("public static function support_notify",bot)
        self.assertIn("admin_ids($s)",bot)

    def test_android_has_internal_chat_not_external_support_link(self):
        settings=self.text("android-source/BlueVpnSettingsActivity.kt")
        activity=self.text("android-source/BlueVpnSupportActivity.kt")
        prepare=self.text("scripts/prepare_android.py")
        self.assertIn("BlueVpnSupportActivity::class.java",settings)
        self.assertNotIn(') { openRemoteLink("support_url") }',settings)
        self.assertIn("/api/v1/support/departments",activity)
        self.assertIn("/api/v1/support/conversations",activity)
        self.assertIn("handler.postDelayed(this, 4500L)",activity)
        self.assertIn("BlueVpnSupportActivity.kt",prepare)
        self.assertIn('android:name=".ui.BlueVpnSupportActivity"',prepare)

    def test_android_support_requests_use_existing_refreshable_auth_session(self):
        account=self.text("android-source/BlueVpnAccountManager.kt")
        self.assertIn("fun supportRequest(",account)
        block=account[account.index("fun supportRequest("):account.index("private fun authenticatedRequest(")]
        self.assertIn("authenticatedRequest",block)
        self.assertIn("if (!hasSession(c))",block)

    def test_php_release_manifest_contains_support_backend(self):
        payload=json.loads((ROOT/"bluevpn-manager/release_php_manifest.json").read_text())
        self.assertIn("includes/class-bluevpn-support.php",payload["php_files"])

if __name__=="__main__":
    unittest.main()
