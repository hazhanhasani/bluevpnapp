from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class AndroidLatencyStateContractTest(unittest.TestCase):
    def text(self, path: str) -> str:
        return (ROOT / path).read_text(encoding="utf-8")

    def test_latency_state_model_is_explicit_and_packaged(self):
        model = self.text("android-source/BlueVpnLatencyState.kt")
        prepare = self.text("scripts/prepare_android.py")
        for phase in ["UNKNOWN", "MEASURING", "FRESH", "STALE", "TIMEOUT", "OFFLINE"]:
            self.assertIn(phase, model)
        self.assertIn("FRESH_FOR_MS = 90_000L", model)
        self.assertIn("MEASUREMENT_TIMEOUT_MS = 30_000L", model)
        self.assertIn(
            'bluevpn_dir / "BlueVpnLatencyState.kt": ROOT / "android-source/BlueVpnLatencyState.kt"',
            prepare,
        )

    def test_ping_broadcast_records_fresh_samples(self):
        src = self.text("android-source/BlueVpnServersActivity.kt")
        start = src.index("mainViewModel.updateTestResultAction.observe")
        end = src.index("renderLocations()", start)
        body = src[start:end]
        self.assertIn("recordPublishedLatencySamples(event)", body)
        self.assertIn('event == "batch-complete"', body)
        self.assertNotIn("renderLocations()", body)

    def test_measurement_start_and_timeout_are_tracked(self):
        src = self.text("android-source/BlueVpnServersActivity.kt")
        self.assertIn("markLatencyMeasurementStarted(candidates)", src)
        self.assertIn("BlueVpnLatencyPolicy.MEASUREMENT_TIMEOUT_MS", src)
        self.assertIn("refreshVisibleHealthPresentation()", src)

    def test_ui_distinguishes_latency_states(self):
        src = self.text("android-source/BlueVpnServersActivity.kt")
        for label in [
            '"در حال سنجش"',
            '"بدون پاسخ"',
            '" ms • قدیمی"',
            '" ms • ذخیره‌شده"',
            '"هنوز سنجیده نشده"',
        ]:
            self.assertIn(label, src)

    def test_signal_quality_uses_live_latency_snapshot(self):
        src = self.text("android-source/BlueVpnServersActivity.kt")
        start = src.index("private fun signalLevel")
        end = src.index("private fun signalBars", start)
        body = src[start:end]
        self.assertIn("val latency = latencySnapshot(candidate)", body)
        self.assertIn("val delay = latency.latencyMs", body)
        self.assertNotIn("candidate.delay in 1..80", body)


if __name__ == "__main__":
    unittest.main()
