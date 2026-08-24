import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]

class WindowsXrayProxyConfirmation556Tests(unittest.TestCase):
    def test_xray_path_never_uses_tun_verifier_after_proxy_success(self):
        source = (ROOT / "bluevpn-windows/Services/ConnectionOrchestrator.cs").read_text(encoding="utf-8")
        start = source.index('progress?.Report($"فعال‌سازی Xray')
        end = source.index('if (!verified.Success)', start)
        block = source[start:end]
        self.assertIn("ConfirmStableXrayProxyAsync", block)
        self.assertNotIn("ConfirmStableTunnelAsync", block)

    def test_second_confirmation_uses_http_proxy_verifier(self):
        source = (ROOT / "bluevpn-windows/Services/ConnectionOrchestrator.cs").read_text(encoding="utf-8")
        start = source.index("private async Task<TunnelVerificationResult> ConfirmStableXrayProxyAsync")
        block = source[start:source.index("private void CancelConnectAttempt", start)]
        self.assertIn("VerifySystemProxyAsync", block)
        self.assertIn("XrayConfigBuilder.LocalHttpPort", block)
        self.assertNotIn("VerifyAsync(", block)

    def test_ui_does_not_claim_xray_failure_is_tun_failure(self):
        window = (ROOT / "bluevpn-windows/MainWindow.xaml.cs").read_text(encoding="utf-8")
        self.assertIn("مسیر اتصال این سرور کامل نشد", window)
        self.assertNotIn("مسیر TUN این سرور کامل نشد", window)

if __name__ == "__main__":
    unittest.main()
