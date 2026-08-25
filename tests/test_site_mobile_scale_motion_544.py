import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]


class SiteMobileScaleMotion544Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.css = (ROOT / "bluevpn-site/assets/css/site.css").read_text()
        cls.js = (ROOT / "bluevpn-site/assets/js/site.js").read_text()

    def test_mobile_uses_compact_typography_sections_and_visuals(self):
        self.assertIn("BlueVPN 5.6.6 — true mobile scale", self.css)
        self.assertIn("font-size:34px", self.css)
        self.assertIn(".bv5-section,body.bluevpn-site .bv-section{padding:48px 0}", self.css)
        self.assertIn(".bv5-device{min-height:390px}", self.css)

    def test_mobile_and_data_saver_skip_desktop_reveal_motion(self):
        self.assertIn("const compactMobile=window.matchMedia?.('(max-width: 760px)')", self.js)
        self.assertIn("const lightweightMotion=reducedMotion||compactMobile||saveData", self.js)
        self.assertIn("if(lightweightMotion||!('IntersectionObserver'in window))", self.js)
        self.assertIn("html.bv-mobile-motion-lite [data-bv-reveal]", self.css)
        self.assertIn("animation:none!important", self.css)


if __name__ == "__main__":
    unittest.main()
