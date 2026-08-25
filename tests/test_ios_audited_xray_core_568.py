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

    def test_build_verifies_source_license_and_architectures(self):
        build = (ROOT / "scripts/build_ios_xray_core.sh").read_text()
        validator = (ROOT / "scripts/validate_ios_runtime.py").read_text()
        self.assertIn('checkout --detach "$LIBXRAY_COMMIT"', build)
        self.assertIn('shasum -a 256 "$WORK/LICENSE"', build)
        self.assertIn("SupportedArchitectures", validator)
        self.assertIn("{'arm64','x86_64'}", validator)

    def test_packet_tunnel_uses_real_libxray_api_and_fails_closed_without_bridge(self):
        adapter = (ROOT / "bluevpn-ios/PacketTunnel/BlueXrayRuntime.swift").read_text()
        tunnel = (ROOT / "bluevpn-ios/PacketTunnel/PacketTunnelProvider.swift").read_text()
        self.assertIn("CGoInvoke", adapter)
        self.assertIn("CGoFree", adapter)
        for method in ("testXray", "runXray", "stopXray", "convertShareLinksToXrayJson"):
            self.assertIn(method, adapter)
        self.assertIn("BluePacketBridge.embedded", tunnel)
        self.assertIn("packetBridgeNotEmbedded", tunnel)
        self.assertIn("static let embedded = false", tunnel)


if __name__ == "__main__":
    unittest.main()
