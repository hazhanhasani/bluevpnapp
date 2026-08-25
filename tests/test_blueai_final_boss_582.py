import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]


class BlueAiFinalBoss582Tests(unittest.TestCase):
    def test_android_uses_confidence_lower_bound_and_failure_diversity(self):
        source = (ROOT / "android-source/BlueVpnSmartSelector.kt").read_text(encoding="utf-8")
        self.assertIn("uncertaintyPenalty", source)
        self.assertIn("robustScore", source)
        self.assertIn("diversifyFailover", source)
        self.assertIn("seenServers", source)
        self.assertIn("import java.util.Locale", source)
        self.assertGreaterEqual(source.count("profile.server.orEmpty()"), 2)

    def test_windows_explores_unknown_not_historically_worst_routes(self):
        source = (ROOT / "bluevpn-windows/Services/WindowsBlueAiService.cs").read_text(encoding="utf-8")
        self.assertIn("PersonalSampleCount", source)
        self.assertNotIn("scored.AsEnumerable().Reverse()", source)

    def test_windows_penalizes_uncertainty_and_diversifies_hosts(self):
        source = (ROOT / "bluevpn-windows/Services/WindowsBlueAiService.cs").read_text(encoding="utf-8")
        self.assertIn("var uncertainty", source)
        self.assertIn("DiversifyHosts", source)
        self.assertIn("HashSet<string>(StringComparer.OrdinalIgnoreCase)", source)


if __name__ == "__main__":
    unittest.main()
