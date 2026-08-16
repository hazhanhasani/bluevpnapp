import pathlib, unittest

ROOT=pathlib.Path(__file__).resolve().parents[1]

class PremiumInstantUiSupportPhase24104(unittest.TestCase):
    def text(self,p): return (ROOT/p).read_text()

    def test_premium_ui_is_instant_but_does_not_mark_verified(self):
        s=self.text("android-source/BlueVpnHomeActivity.kt")
        self.assertIn("private fun renderPremiumInstantConnectedUi",s)
        block=s[
            s.index("private fun renderPremiumInstantConnectedUi"):
            s.index("private fun renderVerifyingState")
        ]
        self.assertIn('statusText.text = "متصل"',block)
        self.assertIn('updateConnectLabel("قطع اتصال")',block)
        self.assertNotIn("connectionVerified = true",block)
        self.assertNotIn("BlueVpnPreferences.markConnected",block)
        self.assertNotIn("BlueVpnRuntimeGate.markConnectionActive",block)

    def test_premium_verification_remains_real_and_free_ui_unchanged(self):
        s=self.text("android-source/BlueVpnHomeActivity.kt")
        self.assertIn("scheduleConnectionVerification()",s)
        self.assertIn("probeInternetThroughCore()",s)
        self.assertIn("completeFailover(latency)",s)
        self.assertIn("if (premiumInstantUiEnabled())",s)
        self.assertIn('title = "در حال اتصال"',s)  # still used by Free/WARP
        self.assertIn("BlueVpnWarpEngine.isBridgeGuid",s)

    def test_premium_failover_is_visually_silent(self):
        s=self.text("android-source/BlueVpnHomeActivity.kt")
        fail=s[
            s.index("private fun failCurrentAndTryNext"):
            s.index("private fun finishFailoverWithError")
        ]
        self.assertIn("renderPremiumInstantConnectedUi",fail)
        self.assertIn("failoverIndex += 1",fail)
        self.assertIn("startCurrentCandidate()",fail)

    def test_support_phase2_schema(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-support.php")
        self.assertIn("private const SCHEMA = '1.2.0'",s)
        self.assertIn("self::table('attachments')",s)
        self.assertIn("self::table('notes')",s)
        self.assertIn("self::table('canned_replies')",s)
        self.assertIn("first_response_due_at",s)
        self.assertIn("resolution_due_at",s)
        self.assertIn("last_seen_at",s)

    def test_support_phase2_security_and_sla(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-support.php")
        self.assertIn("conversation_for_customer",s)
        self.assertIn("SUPPORT_ATTACHMENT_TOO_LARGE",s)
        self.assertIn("FILEINFO_MIME_TYPE",s)
        self.assertIn("base64_decode($encoded,true)",s)
        self.assertIn("sla_state",s)
        self.assertIn("first_response_overdue",s)
        self.assertIn("resolution_overdue",s)

    def test_support_internal_notes_canned_presence_and_blueai(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-support.php")
        self.assertIn("admin_note",s)
        self.assertIn("یادداشت داخلی",s)
        self.assertIn("admin_canned_save",s)
        self.assertIn("پاسخ آماده",s)
        self.assertIn("admin_operator_presence",s)
        self.assertIn("پیشنهاد BlueAI",s)
        self.assertIn("blueai_suggestion",s)

    def test_android_support_attachment_and_background_notification(self):
        activity=self.text("android-source/BlueVpnSupportActivity.kt")
        worker=self.text("android-source/BlueVpnSupportNotifications.kt")
        prepare=self.text("scripts/prepare_android.py")
        self.assertIn("ActivityResultContracts.GetContent",activity)
        self.assertIn("/attachments",activity)
        self.assertIn("Base64.encodeToString",activity)
        self.assertIn("4 * 1024 * 1024",activity)
        self.assertIn("PeriodicWorkRequest.Builder",worker)
        self.assertIn("/api/v1/support/unread",worker)
        self.assertIn("BlueVpnSupportActivity::class.java",worker)
        self.assertIn('implementation("androidx.work:work-runtime:2.10.0")',prepare)
        self.assertIn("BlueVpnSupportNotifications.kt",prepare)

    def test_background_notification_is_battery_bounded(self):
        s=self.text("android-source/BlueVpnSupportNotifications.kt")
        self.assertIn("15,",s)
        self.assertIn("TimeUnit.MINUTES",s)
        self.assertIn("NetworkType.CONNECTED",s)
        self.assertIn("ExistingPeriodicWorkPolicy.UPDATE",s)

if __name__=="__main__":
    unittest.main()
