from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class AndroidLocationsLatencyPayloadTest(unittest.TestCase):
    def text(self, path: str) -> str:
        return (ROOT / path).read_text(encoding="utf-8")

    def test_diffutil_emits_latency_only_payload(self):
        diff = self.text("android-source/BlueVpnLocationRowDiff.kt")
        self.assertIn('const val PAYLOAD_LATENCY', diff)
        self.assertIn("override fun getChangePayload", diff)
        self.assertIn("oldItem.latencyPhase != newItem.latencyPhase", diff)
        self.assertIn("oldItem.latencyMs != newItem.latencyMs", diff)
        self.assertIn("oldItem.signalLevel != newItem.signalLevel", diff)
        self.assertIn("latencyChanged -> PAYLOAD_LATENCY", diff)
        self.assertIn("stateChanged -> PAYLOAD_SERVER_STATE", diff)

    def test_adapter_consumes_latency_payload_without_full_row_rebuild(self):
        src = self.text("android-source/BlueVpnServersActivity.kt")
        start = src.index("override fun onBindViewHolder(\n            holder: RowHolder")
        end = src.index("override fun onBindViewHolder(holder: RowHolder, position: Int)", start)
        payload = src[start:end]
        self.assertIn("PAYLOAD_LATENCY", payload)
        self.assertIn("bindLatencyPayload(holder, item)", payload)
        self.assertIn("return", payload)
        self.assertNotIn("removeAllViews()", payload)

    def test_payload_updates_only_health_signal_and_accessibility(self):
        src = self.text("android-source/BlueVpnServersActivity.kt")
        start = src.index("private fun bindLatencyPayload")
        end = src.index("\n    }\n\n    private val mainViewModel", start)
        body = src[start:end]
        self.assertIn("TAG_SERVER_HEALTH", body)
        self.assertIn("TAG_SERVER_SIGNAL", body)
        self.assertIn("health.text =", body)
        self.assertIn("bars.text =", body)
        self.assertIn("surface?.contentDescription", body)
        self.assertNotIn("holder.host.removeAllViews()", body)

    def test_server_row_exposes_payload_targets(self):
        src = self.text("android-source/BlueVpnServersActivity.kt")
        self.assertIn("tag = TAG_SERVER_SURFACE", src)
        self.assertIn("tag = TAG_SERVER_HEALTH", src)
        self.assertIn("tag = TAG_SERVER_SIGNAL", src)


if __name__ == "__main__":
    unittest.main()
