import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]

class WindowsAdaptiveTun5411Tests(unittest.TestCase):
    def test_xray_resolution_does_not_require_tun_bundle(self):
        source = (ROOT / "bluevpn-windows/Services/RuntimeLocator.cs").read_text(encoding="utf-8")
        self.assertIn('ResolveXray() => ResolveExecutable("xray.exe")', source)

    def test_controller_starts_only_xray_for_normal_connection(self):
        source = (ROOT / "bluevpn-windows/Services/XrayProcessController.cs").read_text(encoding="utf-8")
        self.assertIn("var xray = _runtime.ResolveXray();", source)
        self.assertIn('RoutingMode = "xray_ready"', source)
        self.assertNotIn("_singBox.StartAsync", source)
        self.assertNotIn("EnsureElevatedForTun", source)

    def test_orchestrator_uses_xray_system_proxy_directly(self):
        source = (ROOT / "bluevpn-windows/Services/ConnectionOrchestrator.cs").read_text(encoding="utf-8")
        self.assertIn("فعال‌سازی Xray", source)
        self.assertIn("FallbackToSystemProxyAsync", source)
        self.assertNotIn("TryAlternateTunStackAsync", source)

if __name__ == "__main__":
    unittest.main()
