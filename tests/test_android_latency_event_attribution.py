from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class AndroidLatencyEventAttributionTest(unittest.TestCase):
    def text(self, path: str) -> str:
        return (ROOT / path).read_text(encoding="utf-8")

    def test_legacy_facade_emits_one_batch_completion_transition(self):
        src = self.text("android-source/BlueVpnLegacyViewModel.kt")
        self.assertIn("var previousTesting = false", src)
        self.assertIn("if (previousTesting && !state.isTesting)", src)
        self.assertIn('updateTestResultAction.value = "batch-complete"', src)
        self.assertIn("previousTesting = state.isTesting", src)

    def test_current_ping_event_carries_selected_guid(self):
        src = self.text("android-source/BlueVpnLegacyViewModel.kt")
        self.assertIn('"current:" + state.selectedGuid', src)
        self.assertIn("previousConnectionTestKey", src)

    def test_locations_attributes_freshness_only_to_event_targets(self):
        src = self.text("android-source/BlueVpnServersActivity.kt")
        start = src.index("private fun recordPublishedLatencySamples")
        end = src.index("private fun latencySnapshot", start)
        body = src[start:end]
        self.assertIn('event == "batch-complete"', body)
        self.assertIn('event?.startsWith("current:") == true', body)
        self.assertIn('substringAfter("current:").substringBefore(":")', body)
        self.assertIn("candidate.guid !in targetGuids", body)
        self.assertNotIn("targetGuids = candidates.map", body.split('event == "batch-complete"', 1)[1].split("else ->", 1)[1])

    def test_current_ping_does_not_finish_batch_sweep(self):
        src = self.text("android-source/BlueVpnServersActivity.kt")
        start = src.index("mainViewModel.updateTestResultAction.observe")
        end = src.index("renderLocations()", start)
        body = src[start:end]
        self.assertIn('if (event == "batch-complete")', body)
        self.assertIn("healthSweepInProgress = false", body)


if __name__ == "__main__":
    unittest.main()
