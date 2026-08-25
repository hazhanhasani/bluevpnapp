import json
import re
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


class IOSAuditedXrayCoreTests(unittest.TestCase):
    def test_runtime_sources_and_abi_are_immutable(self):
        lock = json.loads((ROOT / "bluevpn-ios/runtime-lock.json").read_text())
        self.assertRegex(lock["libxray_commit"], r"^[0-9a-f]{40}$")
        self.assertRegex(lock["xray_core_commit"], r"^[0-9a-f]{40}$")
        self.assertEqual(lock["api_version"], 2)
        self.assertEqual(lock["bluevpn_abi"], 1)
        self.assertRegex(lock["bridge_commit"], r"^[0-9a-f]{40}$")
        self.assertRegex(lock["binary_checksum"], r"^[0-9a-f]{64}$")

    def test_build_verifies_source_license_and_module_pin(self):
        build = (ROOT / "scripts/build_ios_xray_core.sh").read_text()
        validator = (ROOT / "scripts/validate_ios_runtime.py").read_text()
        self.assertIn('checkout --detach "$LIBXRAY_COMMIT"', build)
        self.assertIn('shasum -a 256 "$WORK/LICENSE"', build)
        self.assertIn('ACTUAL_XRAY_MODULE', build)
        self.assertIn('bridge_commit', validator)

    def test_packet_tunnel_uses_pinned_public_socketpair_bridge(self):
        tunnel = (ROOT / "bluevpn-ios/PacketTunnel/PacketTunnelProvider.swift").read_text()
        project = (ROOT / "bluevpn-ios/project.yml").read_text()
        self.assertIn("import SwiftyXrayKit", tunnel)
        self.assertIn("XrayBridge(packetFlow:packetFlow)", tunnel)
        self.assertIn("preset:.mobile", tunnel)
        self.assertIn("revision: 3c5405521ae547de110f6ea65df00b1c05f6a0bc", project)
        self.assertNotIn("value(forKey:", tunnel)


if __name__ == "__main__":
    unittest.main()
