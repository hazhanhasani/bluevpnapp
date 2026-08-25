import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]


class UpstreamConnectionQuality585Tests(unittest.TestCase):
    def test_android_revalidates_and_recovers_zombie_tunnel_after_doze(self):
        source = (ROOT / "android-source/BlueVpnHomeActivity.kt").read_text(encoding="utf-8")
        self.assertIn("backgroundDuration >= 15_000L", source)
        self.assertIn("forceRecoveryOnFailure = true", source)
        self.assertIn("forceRestart = forceRecoveryOnFailure", source)
        self.assertIn("if (transportAlive)", source)
        self.assertIn("if (!forceRestart)", source)

    def test_windows_probe_races_ipv4_and_ipv6(self):
        source = (ROOT / "bluevpn-windows/Services/EndpointSelector.cs").read_text(encoding="utf-8")
        self.assertIn("Dns.GetHostAddressesAsync", source)
        self.assertIn("GroupBy(address => address.AddressFamily)", source)
        self.assertIn("Task.WhenAny(attempts)", source)
        self.assertIn("new TcpClient(address.AddressFamily)", source)


if __name__ == "__main__":
    unittest.main()
