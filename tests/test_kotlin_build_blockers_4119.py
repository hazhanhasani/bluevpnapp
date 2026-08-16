import pathlib
import unittest

ROOT=pathlib.Path(__file__).resolve().parents[1]

class KotlinBuildBlockers4119(unittest.TestCase):
    def text(self,p):
        return (ROOT/p).read_text()

    def test_home_imports_background_optimizer(self):
        s=self.text('android-source/BlueVpnHomeActivity.kt')
        self.assertIn('import com.v2ray.ang.bluevpn.BlueVpnBackgroundOptimizer',s)
        self.assertIn('BlueVpnBackgroundOptimizer.markPending(',s)
        self.assertIn('BlueVpnBackgroundOptimizer.maybeStart(',s)

    def test_prepare_android_overlays_background_optimizer(self):
        s=self.text('scripts/prepare_android.py')
        self.assertIn('BlueVpnBackgroundOptimizer.kt',s)
        self.assertIn('bluevpn_dir / "BlueVpnBackgroundOptimizer.kt"',s)

    def test_support_does_not_use_unresolvable_scrollview_layoutparams(self):
        s=self.text('android-source/BlueVpnSupportActivity.kt')
        self.assertNotIn('ScrollView.LayoutParams(',s)
        self.assertIn('FrameLayout.LayoutParams(-1, -2)',s)

    def test_4119_version_metadata_is_consistent(self):
        import json
        app=json.loads(self.text('branding/app.json'))
        rel=json.loads(self.text('release.json'))
        self.assertEqual((app['version_name'],app['version_code']),('4.11.10',41110))
        self.assertEqual((rel['version'],rel['version_code']),('4.11.10',41110))

if __name__=='__main__':
    unittest.main()
