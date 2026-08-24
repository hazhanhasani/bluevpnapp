import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]


class WindowsHomeFitBanner547Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.xaml = (ROOT / "bluevpn-windows/MainWindow.xaml").read_text()
        cls.code = (ROOT / "bluevpn-windows/MainWindow.xaml.cs").read_text()

    def test_home_is_uniformly_fitted_without_page_scroll(self):
        self.assertIn('x:Name="HomeFitViewbox"', self.xaml)
        self.assertIn('Stretch="Uniform" StretchDirection="DownOnly"', self.xaml)
        home = self.xaml.split('x:Name="HomeFitViewbox"', 1)[1].split('</Viewbox>', 1)[0]
        self.assertNotIn("ScrollViewer", home)
        self.assertIn('x:Name="HomeContentGrid" Width="584"', home)

    def test_banner_preserves_artwork_ratio_on_design_surface(self):
        self.assertIn('x:Name="AdCard" Width="440"', self.xaml)
        self.assertIn('x:Name="AdImage" Stretch="Uniform"', self.xaml)
        self.assertIn("var ratioHeight = ratio > 0.25 ? width / ratio", self.code)
        self.assertIn("if (width < 240) width = 440;", self.code)
        self.assertIn("Math.Clamp(ratioHeight, configuredFloor, 220)", self.code)

    def test_drawers_keep_independent_scrolling(self):
        self.assertGreaterEqual(self.xaml.count("<ScrollViewer"), 3)


if __name__ == "__main__":
    unittest.main()
