import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]

class WindowsComboBoxDarkTemplate555Tests(unittest.TestCase):
    def test_combobox_replaces_native_white_chrome(self):
        xaml = (ROOT / "bluevpn-windows/App.xaml").read_text(encoding="utf-8")
        self.assertIn('<Style TargetType="ComboBox">', xaml)
        self.assertIn('<Setter Property="OverridesDefaultStyle" Value="True"/>', xaml)
        self.assertIn('x:Name="ComboChrome"', xaml)
        self.assertIn('Background="{TemplateBinding Background}"', xaml)
        self.assertIn('TextElement.Foreground="{TemplateBinding Foreground}"', xaml)

    def test_popup_uses_theme_resources_and_bounded_height(self):
        xaml = (ROOT / "bluevpn-windows/App.xaml").read_text(encoding="utf-8")
        self.assertIn('x:Name="PART_Popup"', xaml)
        self.assertIn('<Setter Property="MaxDropDownHeight" Value="250"/>', xaml)
        self.assertIn('Background="{DynamicResource BlueVpnSurface}"', xaml)
        self.assertIn('BorderBrush="{DynamicResource BlueVpnStroke}"', xaml)

if __name__ == "__main__":
    unittest.main()
