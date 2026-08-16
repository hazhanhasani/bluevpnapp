import pathlib, unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]

class BlueAiClosedLoop491(unittest.TestCase):
    def text(self,p): return (ROOT/p).read_text()

    def test_decision_is_started_and_resolved_from_real_outcome(self):
        core=self.text("android-source/BlueVpnIntelligenceCore.kt")
        smart=self.text("android-source/BlueVpnSmartSelector.kt")
        home=self.text("android-source/BlueVpnHomeActivity.kt")
        self.assertIn("beginDecision(",core)
        self.assertIn("resolveDecision(",core)
        self.assertIn("BlueVpnIntelligenceCore.beginDecision(",smart)
        self.assertIn("BlueVpnIntelligenceCore.resolveDecision(",home)
        self.assertIn("success = true",home)
        self.assertIn("success = false",home)

    def test_confidence_is_calibrated_by_previous_prediction_error(self):
        core=self.text("android-source/BlueVpnIntelligenceCore.kt")
        smart=self.text("android-source/BlueVpnSmartSelector.kt")
        self.assertIn("calibrationError",core)
        self.assertIn("calibratedConfidence",core)
        self.assertIn("BlueVpnIntelligenceCore.calibratedConfidence",smart)

    def test_pending_decisions_are_bounded_and_hashed(self):
        core=self.text("android-source/BlueVpnIntelligenceCore.kt")
        self.assertIn('sha("pending:$guid").take(24)',core)
        self.assertIn("10 * 60_000L",core)
        self.assertNotIn('.put("guid", guid)',core)

    def test_successful_cloud_outcome_resolves_route_incident(self):
        ops=self.text("bluevpn-manager/includes/class-bluevpn-ai-ops.php")
        ai=self.text("bluevpn-manager/includes/class-bluevpn-ai.php")
        self.assertIn("observe_connection_outcome",ops)
        self.assertIn("incident_type='route_degradation'",ops)
        self.assertIn("BlueVPN_AI_Ops::observe_connection_outcome($event)",ai)

if __name__=="__main__": unittest.main()
