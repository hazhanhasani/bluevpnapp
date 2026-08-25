import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]


class AndroidPremiumTapsellLockedCarousel579Tests(unittest.TestCase):
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
        self.assertIn("setOnClickListener { openCurrentCampaign() }", source)
        self.assertNotIn("setOnClickListener { performClick() }", source)
        self.assertNotIn("override fun performClick", source)

    def test_campaign_click_has_no_recursive_android_perform_click_path(self):
        source = (ROOT / "android-source/BlueVpnAdsCarouselView.kt").read_text(encoding="utf-8")
        click_handler = source[
            source.index("private fun openCurrentCampaign"):
            source.index("private fun safeUrl")
        ]
        self.assertIn("BlueVpnAdActionRouter.open(", click_handler)
        self.assertNotIn("performClick", click_handler)
        self.assertNotIn("playSoundEffect", click_handler)


if __name__ == "__main__":
    unittest.main()
