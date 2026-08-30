from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class WindowsCleanPcRuntime615Tests(unittest.TestCase):
    def test_tapsell_uses_standard_wpf_webview2_not_composition_control(self):
        src = (ROOT / "bluevpn-windows/MainWindow.xaml.cs").read_text(encoding="utf-8")
        self.assertIn("Microsoft.Web.WebView2.Wpf.WebView2?", src)
        self.assertIn("new Microsoft.Web.WebView2.Wpf.WebView2", src)
        self.assertNotIn("WebView2CompositionControl", src)
        self.assertIn("Microsoft.Windows.SDK.NET dependency", src)

    def test_publish_gate_catches_missing_windows_sdk_runtime_assembly(self):
        wf = (ROOT / ".github/workflows/build-windows.yml").read_text(encoding="utf-8")
        self.assertIn("Guard clean-PC Windows SDK runtime closure", wf)
        self.assertIn("Microsoft.Windows.SDK.NET.dll", wf)
        self.assertIn("would crash on a clean customer PC", wf)
        self.assertIn("BlueVPN.deps.json", wf)


if __name__ == "__main__":
    unittest.main()
