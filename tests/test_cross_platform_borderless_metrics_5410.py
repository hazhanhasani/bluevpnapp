import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]


class CrossPlatformBorderlessMetrics5410Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.android = (ROOT / "android-source/BlueVpnHomeActivity.kt").read_text()
        cls.android_theme = (ROOT / "android-source/BlueVpnTheme.kt").read_text()
        cls.windows = (ROOT / "bluevpn-windows/App.xaml").read_text()
        cls.windows_theme = (ROOT / "bluevpn-windows/Services/WindowsThemeService.cs").read_text()

    def test_android_live_metrics_have_no_card_chrome(self):
        block = self.android.split("private fun createLiveConnectionMetrics", 1)[1].split("private fun createCompatibilityFields", 1)[0]
        self.assertIn("): FrameLayout {", block)
        self.assertIn("setBackgroundColor(Color.TRANSPARENT)", block)
        self.assertIn("elevation = 0f", block)
        self.assertNotIn("glassCard(", block)

    def test_windows_live_metrics_have_no_card_chrome(self):
        style = self.windows.split('x:Key="BlueVpnMetricCardStyle"', 1)[1].split("</Style>", 1)[0]
        self.assertIn('Property="Background" Value="Transparent"', style)
        self.assertIn('Property="BorderBrush" Value="Transparent"', style)
        self.assertIn('Property="BorderThickness" Value="0"', style)

    def test_light_theme_remaining_lines_are_visible(self):
        self.assertIn('stroke = Color.parseColor("#C8D1E2")', self.android_theme)
        self.assertIn('x:Key="BlueVpnStroke" Color="#FFC8D1E2"', self.windows)
        self.assertIn('Set("BlueVpnStroke", "#FFC8D1E2")', self.windows_theme)


if __name__ == "__main__":
    unittest.main()
