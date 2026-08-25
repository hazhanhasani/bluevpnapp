import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]


class AndroidPremiumTapsellLockedCarousel578Tests(unittest.TestCase):
    def test_premium_standard_banner_reaches_the_actual_rotation_runnable(self):
        source = (ROOT / "android-source/BlueVpnAdsCarouselView.kt").read_text(encoding="utf-8")
        runnable = source[source.index("private val slideRunnable"):source.index("init {")]
        self.assertIn("showTapsellBanner(activity)", runnable)
        self.assertNotIn("isFree", runnable)

    def test_user_cannot_swipe_or_see_slide_indicators(self):
        source = (ROOT / "android-source/BlueVpnAdsCarouselView.kt").read_text(encoding="utf-8")
        self.assertNotIn("MotionEvent", source)
        self.assertNotIn("handleTouch", source)
        self.assertNotIn("setOnTouchListener", source)
        self.assertNotIn("renderDots", source)
        self.assertNotIn("private val dots", source)
        self.assertIn("setOnClickListener { performClick() }", source)


if __name__ == "__main__":
    unittest.main()
