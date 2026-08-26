import json
import unittest
import xml.etree.ElementTree as ET
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

class ElementorSvgUi4124Tests(unittest.TestCase):
    def test_release_version(self):
        app=json.loads((ROOT/'branding/app.json').read_text())
        rel=json.loads((ROOT/'release.json').read_text())
        self.assertEqual(
            (rel['version'], rel['version_code']),
            (app['version_name'], app['version_code']),
            'release.json and branding/app.json must stay synchronized',
        )
        parts=[int(x) for x in str(app['version_name']).split('.')]
        self.assertEqual(len(parts), 3)
        self.assertEqual(app['version_code'], parts[0]*10000 + parts[1]*100 + parts[2])

    def test_editor_recursion_guard(self):
        src=(ROOT/'bluevpn-site/inc/class-bluevpn-elementor.php').read_text()
        self.assertIn('private static function is_editor_request()', src)
        self.assertIn('return self::render_editor_page();', src)
        self.assertIn("if (self::is_editor_request()) return false;", src)
        self.assertIn('BlueVPN Elementor widget registration failed:', src)

    def test_svg_assets_parse(self):
        names=['bluevpn-hero-orbit.svg','bluevpn-global-shield.svg','bluevpn-speed-tunnel.svg','bluevpn-premium-cards.svg']
        for name in names:
            p=ROOT/'bluevpn-site/assets/images/illustrations'/name
            self.assertTrue(p.exists(), name)
            self.assertLess(p.stat().st_size, 100_000, name)
            root=ET.parse(p).getroot()
            self.assertTrue(root.tag.endswith('svg'))

    def test_widgets_reference_current_visual_assets(self):
        src=(ROOT/'bluevpn-site/inc/elementor/widgets.php').read_text()
        for name in ['bluevpn-global-shield.svg','bluevpn-premium-cards.svg']:
            self.assertIn(name, src)
        self.assertIn("BLUEVPN_SITE_VERSION', '5.10.9", (ROOT/'bluevpn-site/functions.php').read_text())

if __name__ == '__main__':
    unittest.main()
