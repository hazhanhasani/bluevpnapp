import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]


class AndroidXrayJsonFailover545Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.home = (ROOT / "android-source/BlueVpnHomeActivity.kt").read_text()

    def test_stock_start_failure_is_normalized_and_advances_failover(self):
        self.assertIn("normalizeCoreStartFailure(upstreamReason)", self.home)
        self.assertIn('"failed to parse json" in lower', self.home)
        self.assertIn("failCurrentAndTryNext(normalizeCoreStartFailure", self.home)

    def test_bad_json_route_is_quarantined_without_leaking_raw_core_error(self):
        self.assertIn("BlueVpnPreferences.markSessionInactive(this, failedGuid)", self.home)
        self.assertIn("کانفیگ این مسیر نامعتبر بود", self.home)
        self.assertIn("compact.take(180)", self.home)

    def test_xray_teardown_uses_failure_class_backoff_before_next_guid(self):
        self.assertIn("val retryDelayMs = failurePolicy.retryDelayMs", self.home)
        self.assertIn("ConnectionFailureClass.CONFIG_INVALID -> ConnectionFailurePolicy(failureClass, true, 900L)", self.home)
        self.assertIn("ConnectionFailureClass.EGRESS_VERIFICATION -> ConnectionFailurePolicy(failureClass, false, 350L)", self.home)
        self.assertIn("handler.postDelayed({", self.home)
        self.assertIn("if (failoverActive) startCurrentCandidate()", self.home)

    def test_transient_network_failures_do_not_poison_persistent_server_history(self):
        self.assertIn("ConnectionFailureClass.DNS -> ConnectionFailurePolicy(failureClass, false, 650L)", self.home)
        self.assertIn("ConnectionFailureClass.UNKNOWN -> ConnectionFailurePolicy(failureClass, false, 500L)", self.home)
        self.assertIn("if (failurePolicy.hardPenalty) {", self.home)
        self.assertIn("BlueVpnPreferences.markServerFailure(this, failedGuid)", self.home)
        self.assertIn("BlueVpnRouteIntelligence.recordFailure(this, failedGuid, reason)", self.home)

    def test_failure_classification_is_privacy_safe_audit_metadata(self):
        audit = (ROOT / "android-source/BlueVpnRuntimeAudit.kt").read_text()
        self.assertIn("VPN_FAILURE_CLASSIFIED", audit)
        self.assertIn("failurePolicy.failureClass.name", self.home)
        self.assertIn('if (failurePolicy.hardPenalty) "hard" else "soft"', self.home)


if __name__ == "__main__":
    unittest.main()
