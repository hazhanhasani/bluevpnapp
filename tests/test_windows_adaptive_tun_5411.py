import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]

class WindowsAdaptiveTun5411Tests(unittest.TestCase):
    def test_tun_config_has_nekoray_compatible_network_guards(self):
        source = (ROOT / "bluevpn-windows/Services/V2RayNTunConfigBuilder.cs").read_text(encoding="utf-8")
        self.assertIn('string stack = "mixed"', source)
        self.assertIn("endpoint_independent_nat = true", source)
        self.assertIn("udp_fragment = true", source)

    def test_controller_rotates_independent_tun_stacks(self):
        source = (ROOT / "bluevpn-windows/Services/XrayProcessController.cs").read_text(encoding="utf-8")
        self.assertIn('TunStacks = ["mixed", "gvisor"]', source)
        self.assertIn("TryAlternateTunStackAsync", source)
        self.assertIn("ActiveTunStack", source)

    def test_both_stacks_are_checked_before_proxy_fallback(self):
        source = (ROOT / "bluevpn-windows/Services/ConnectionOrchestrator.cs").read_text(encoding="utf-8")
        alternate = source.index("TryAlternateTunStackAsync")
        fallback = source.index("FallbackToSystemProxyAsync", alternate)
        self.assertLess(alternate, fallback)

if __name__ == "__main__":
    unittest.main()
