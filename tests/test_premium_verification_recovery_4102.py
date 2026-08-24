import pathlib, unittest

ROOT=pathlib.Path(__file__).resolve().parents[1]

class PremiumVerificationRecovery4102(unittest.TestCase):
    def text(self,p):
        return (ROOT/p).read_text()

    def test_running_service_is_not_promoted_without_real_proof(self):
        s=self.text("android-source/BlueVpnHomeActivity.kt")
        self.assertIn("RUNNING alone is never enough to claim CONNECTED",s)
        self.assertNotIn(
            "(preserveServiceOnFailure || isThemeConnectionGraceActive()) &&\n"
            "                    BlueVpnPreferences.connectedAt",
            s,
        )
        self.assertIn("recoverUnverifiedExistingSession(",s)

    def test_existing_session_can_be_verified_by_upstream_real_ping(self):
        s=self.text("android-source/BlueVpnHomeActivity.kt")
        self.assertIn(
            "if (existingSessionCheckInProgress && !connectionVerified)",
            s,
        )
        self.assertIn("completeExistingSessionVerification(upstreamDelay)",s)
        helper=s[s.index("private fun completeExistingSessionVerification"):
                 s.index("private fun recoverUnverifiedExistingSession")]
        self.assertIn("connectionVerified = true",helper)
        self.assertIn("BlueVpnPreferences.markConnected",helper)

    def test_candidate_verification_has_absolute_deadline(self):
        s=self.text("android-source/BlueVpnHomeActivity.kt")
        self.assertIn("private val verificationTimeout = Runnable",s)
        self.assertIn("handler.postDelayed(verificationTimeout, 28_000L)",s)
        self.assertIn("verificationDeadlineGuid != attemptedGuid",s)
        self.assertIn(
            "تأیید اینترنت این مسیر در زمان مجاز کامل نشد؛ سرور بعدی بررسی می‌شود",
            s,
        )

    def test_deadline_is_cancelled_on_every_terminal_candidate_transition(self):
        s=self.text("android-source/BlueVpnHomeActivity.kt")
        self.assertGreaterEqual(
            s.count("handler.removeCallbacks(verificationTimeout)"),
            5,
        )
        self.assertGreaterEqual(
            s.count('verificationDeadlineGuid = ""'),
            5,
        )

    def test_crash_recovery_cleans_old_core_before_new_connection(self):
        s=self.text("android-source/BlueVpnHomeActivity.kt")
        self.assertIn("recoveryCleanupRequired = true",s)
        begin_start=s.index("private fun beginSmartConnection()")
        begin_end=s.index("private fun startSmartConnectionWithCandidates", begin_start)
        begin=s[begin_start:begin_end]
        self.assertIn("LauncherManager.stopService(this)",begin)
        self.assertIn("pendingConnectionRequest = true",begin)
        self.assertIn("BlueVpnPreferences.clearConnected(this)",begin)

    def test_unverified_recovery_reenters_entitlement_pipeline_not_free_engine(self):
        s=self.text("android-source/BlueVpnHomeActivity.kt")
        helper=s[s.index("private fun recoverUnverifiedExistingSession"):
                 s.index("private fun verifyTunnelThroughCore")]
        self.assertIn("pendingConnectionRequest = true",helper)
        self.assertIn("LauncherManager.stopService(this)",helper)
        self.assertNotIn("BlueVpnWarpEngine.prepare",helper)
        self.assertNotIn("prepareFreeAccess",helper)

    def test_premium_candidate_queue_remains_entitlement_isolated(self):
        s=self.text("android-source/BlueVpnHomeActivity.kt")
        self.assertIn("connectionEntitlementGuids = prepared.first",s)
        self.assertIn("candidateAllowed(",s)
        self.assertIn("guid !in connectionEntitlementGuids",s)

if __name__=="__main__":
    unittest.main()
