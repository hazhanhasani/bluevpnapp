import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

class WindowsXamlCompileGuard509Tests(unittest.TestCase):
    def test_font_family_not_on_non_control_layout_container(self):
        xaml=(ROOT / "bluevpn-windows/MainWindow.xaml").read_text(encoding="utf-8")
        bad=re.search(r"<(?:Border|Grid|StackPanel|DockPanel|Canvas|UniformGrid)\b[^>]*\bFontFamily\s*=", xaml, re.I | re.S)
        self.assertIsNone(bad, "FontFamily on a WPF layout container triggers MC3072")

    def test_auth_font_is_applied_at_window_scope(self):
        xaml=(ROOT / "bluevpn-windows/MainWindow.xaml").read_text(encoding="utf-8")
        self.assertRegex(xaml, r"<Window\b[^>]*\bFontFamily=\"Segoe UI\"")

if __name__ == "__main__":
    unittest.main()
