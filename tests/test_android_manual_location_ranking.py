from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class AndroidManualLocationRankingTest(unittest.TestCase):
    def text(self, path: str) -> str:
        return (ROOT / path).read_text(encoding="utf-8")

    def test_disconnected_country_selection_uses_ranked_candidate(self):
        src = self.text("android-source/BlueVpnServersActivity.kt")
        start = src.index("private fun selectGroup")
        end = src.index("private fun selectServer", start)
        body = src[start:end]
        self.assertIn("BlueVpnSmartSelector.rankTrusted(this, group.servers)", body)
        self.assertIn(".firstOrNull()", body)
        self.assertIn("?.candidate", body)
        self.assertIn("MmkvManager.setSelectServer(it.guid)", body)
        self.assertNotIn(
            "cachedCandidates(this)\n                .firstOrNull",
            body,
        )

    def test_connected_country_selection_still_returns_to_home_handover(self):
        src = self.text("android-source/BlueVpnServersActivity.kt")
        start = src.index("private fun selectGroup")
        end = src.index("private fun selectServer", start)
        body = src[start:end]
        self.assertIn("if (BlueVpnRuntimeGate.connectionActive(this))", body)
        self.assertIn("finishWithLocationResult()", body)


if __name__ == "__main__":
    unittest.main()
