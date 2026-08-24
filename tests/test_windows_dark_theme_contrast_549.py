import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]


class WindowsDarkThemeContrast549Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.app = (ROOT / "bluevpn-windows/App.xaml").read_text()
        cls.xaml = (ROOT / "bluevpn-windows/MainWindow.xaml").read_text()
        cls.code = (ROOT / "bluevpn-windows/MainWindow.xaml.cs").read_text()
        cls.theme = (ROOT / "bluevpn-windows/Services/WindowsThemeService.cs").read_text()

    def test_native_input_and_popup_controls_are_theme_aware(self):
        self.assertIn('<Style TargetType="ComboBox">', self.app)
        self.assertIn('<Style TargetType="ComboBoxItem">', self.app)
        self.assertIn('<Style TargetType="CheckBox">', self.app)
        self.assertIn('Value="{DynamicResource BlueVpnText}"', self.app)

    def test_orb_does_not_keep_light_hardcoded_surfaces(self):
        orb = self.xaml.split('x:Name="OrbHalo"', 1)[1].split('</Grid>', 2)[0]
        self.assertIn('Background="{DynamicResource BlueVpnSurfaceSoft}"', orb)
        self.assertIn('Background="{DynamicResource BlueVpnSurface}"', orb)
        self.assertNotIn('#F7FFFFFF', orb)
        self.assertNotIn('#DDE8F8FF', orb)

    def test_runtime_states_and_location_picker_use_theme_brushes(self):
        self.assertNotIn("Background = Brushes.White", self.code)
        self.assertIn('Background = (Brush)FindResource("BlueVpnBg")', self.code)
        self.assertIn('Background = (Brush)FindResource("BlueVpnSurface")', self.code)
        self.assertGreaterEqual(self.code.count('OrbHalo.Background = (Brush)FindResource("BlueVpnSurfaceSoft")'), 3)

    def test_dark_accent_text_has_a_light_contrast_variant(self):
        self.assertIn('Set("BlueVpnBlue2", "#FF8AABFF")', self.theme)
        self.assertIn('Set("BlueVpnBlue2", "#FF2455CC")', self.theme)


if __name__ == "__main__":
    unittest.main()
