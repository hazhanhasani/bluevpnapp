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

    def test_xray_teardown_gets_drain_window_before_next_guid(self):
        self.assertIn("val retryDelayMs = if", self.home)
        self.assertIn(") 900L else 350L", self.home)
        self.assertIn("handler.postDelayed({", self.home)
        self.assertIn("if (failoverActive) startCurrentCandidate()", self.home)


if __name__ == "__main__":
    unittest.main()
