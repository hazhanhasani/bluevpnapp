import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]


class ConnectionTruth536Tests(unittest.TestCase):
    def text(self, path):
        return (ROOT / path).read_text(encoding="utf-8")

    def test_android_never_renders_connected_while_verifying(self):
        home = self.text("android-source/BlueVpnHomeActivity.kt")
        block = home.split("private fun renderVerifyingState()", 1)[1].split("private fun isThemeConnectionGraceActive", 1)[0]
        self.assertNotIn("renderPremiumInstantConnectedUi", block)
        self.assertIn("OrbVisualState.CONNECTING", block)

    def test_windows_ipv6_is_diagnostic_not_success_gate(self):
        verifier = self.text("bluevpn-windows/Services/SystemTunnelVerifier.cs")
        self.assertIn("if (adapterOk && routeOk && ipChanged && warpOk && !countryBlocked)", verifier)
        self.assertNotIn("if (adapterOk && routeOk && route.Ipv6Safe", verifier)

    def test_windows_ai_cannot_override_live_quality(self):
        ai = self.text("bluevpn-windows/Services/WindowsBlueAiService.cs")
        self.assertIn(".ThenBy(LiveQualityCost)", ai)
        self.assertIn(".ThenByDescending(HistoricalScore)", ai)

    def test_warp_requires_real_egress(self):
        warp = self.text("bluevpn-windows/Services/WarpConnectionController.cs")
        self.assertIn("if (!trace.Reachable || string.IsNullOrWhiteSpace(trace.PublicIp))", warp)


if __name__ == "__main__":
    unittest.main()
