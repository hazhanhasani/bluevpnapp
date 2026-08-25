import json
import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]


class IOSPacketBridgeRecovery5610Tests(unittest.TestCase):
    def text(self, path):
        return (ROOT / path).read_text(encoding="utf-8")

    def test_swift_package_and_binary_are_immutable(self):
        lock = json.loads(self.text("bluevpn-ios/runtime-lock.json"))
        project = self.text("bluevpn-ios/project.yml")
        self.assertEqual(lock["bridge_commit"], "3c5405521ae547de110f6ea65df00b1c05f6a0bc")
        self.assertEqual(lock["binary_checksum"], "3a0f43e908e8acaa84b17467614cded31d12cc1918a4f89eb928caecfd8b2b09")
        self.assertIn(lock["bridge_commit"], project)
        self.assertNotIn("from:", project)

    def test_packet_flow_uses_socketpair_bridge_without_private_api(self):
        tunnel = self.text("bluevpn-ios/PacketTunnel/PacketTunnelProvider.swift")
        self.assertIn("XrayBridge(packetFlow:packetFlow)", tunnel)
        self.assertIn("preset:.mobile", tunnel)
        self.assertNotIn("value(forKey:", tunnel)
        self.assertNotIn("SOCK_SEQPACKET", tunnel)

    def test_iran_network_policy_is_ipv4_only(self):
        tunnel = self.text("bluevpn-ios/PacketTunnel/PacketTunnelProvider.swift")
        self.assertIn("settings.ipv6Settings=nil", tunnel)
        self.assertIn('"queryStrategy":"UseIPv4"', tunnel)

    def test_wifi_cellular_sleep_and_wake_recovery(self):
        tunnel = self.text("bluevpn-ios/PacketTunnel/PacketTunnelProvider.swift")
        for marker in ("NWPathMonitor", ".usesInterfaceType(.wifi)", ".usesInterfaceType(.cellular)", "override func sleep", "override func wake", "core.restart"):
            self.assertIn(marker, tunnel)
        self.assertIn("reasserting=true", tunnel)
        self.assertIn("reasserting=false", tunnel)

    def test_ci_resolves_package_and_keeps_action_pins(self):
        workflow = self.text(".github/workflows/build-ios.yml")
        self.assertIn("xcodegen generate", workflow)
        self.assertNotRegex(workflow, r"uses:\s+[^\s]+@v\d+")


if __name__ == "__main__":
    unittest.main()
