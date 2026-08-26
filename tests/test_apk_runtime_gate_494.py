import pathlib
import tempfile
import unittest
import importlib.util

ROOT=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location("apkval", ROOT/"scripts/validate_android_apk.py")
apkval=importlib.util.module_from_spec(spec)
spec.loader.exec_module(apkval)
optimizer_spec=importlib.util.spec_from_file_location("apkopt", ROOT/"scripts/optimize_android_release.py")
apkopt=importlib.util.module_from_spec(optimizer_spec)
optimizer_spec.loader.exec_module(apkopt)

class ApkRuntimeGate494(unittest.TestCase):
    def test_manifest_parser_accepts_real_bluevpn_contract(self):
        xml = '''<manifest xmlns:android="http://schemas.android.com/apk/res/android" package="com.v2ray.ang">
          <uses-permission android:name="android.permission.POST_NOTIFICATIONS"/>
          <uses-permission android:name="android.permission.FOREGROUND_SERVICE"/>
          <application>
            <service android:name="com.v2ray.ang.bluevpn.BlueVpnWarpKeepAliveService"/>
            <service android:name="com.v2ray.ang.bluevpn.BlueVpnQuickTileService"/>
            <receiver android:name="com.v2ray.ang.bluevpn.BlueVpnSystemActionReceiver"/>
          </application>
        </manifest>'''
        with tempfile.TemporaryDirectory() as td:
            p=pathlib.Path(td)/"AndroidManifest.xml"
            p.write_text(xml)
            report=apkval.validate_manifest_xml(p)
        self.assertEqual(report["permissions"],"PASS")
        self.assertEqual(report["services"],"PASS")
        self.assertEqual(report["receivers"],"PASS")

    def test_manifest_parser_rejects_missing_service(self):
        xml = '''<manifest xmlns:android="http://schemas.android.com/apk/res/android">
          <uses-permission android:name="android.permission.POST_NOTIFICATIONS"/>
          <uses-permission android:name="android.permission.FOREGROUND_SERVICE"/>
          <application>
            <service android:name="com.v2ray.ang.bluevpn.BlueVpnWarpKeepAliveService"/>
            <receiver android:name="com.v2ray.ang.bluevpn.BlueVpnSystemActionReceiver"/>
          </application>
        </manifest>'''
        with tempfile.TemporaryDirectory() as td:
            p=pathlib.Path(td)/"AndroidManifest.xml"
            p.write_text(xml)
            with self.assertRaises(ValueError):
                apkval.validate_manifest_xml(p)

    def test_workflow_does_not_depend_on_aapt2_xmltree(self):
        s=(ROOT/".github/workflows/build-apk.yml").read_text()
        self.assertNotIn('aapt2" dump xmltree',s)
        self.assertIn("Generated AndroidManifest.xml runtime contract: PASS",s)
        self.assertIn("Validate signed APK runtime contract",s)

    def test_release_optimizer_enables_r8_and_resource_shrinking_without_losing_splits(self):
        gradle='''android {\n    buildTypes {\n        release {\n            isMinifyEnabled = false\n        }\n    }\n    splits {\n        abi {\n            isUniversalApk = abiFilterList.isNullOrEmpty()\n        }\n    }\n}\n'''
        with tempfile.TemporaryDirectory() as td:
            p=pathlib.Path(td)/"build.gradle.kts"
            p.write_text(gradle)
            apkopt.optimize(p)
            optimized=p.read_text()
        self.assertIn("isMinifyEnabled = true",optimized)
        self.assertIn("isShrinkResources = true",optimized)
        self.assertIn("isUniversalApk = abiFilterList.isNullOrEmpty()",optimized)

    def test_existing_cleanup_hook_applies_android_release_optimizer_before_gradle(self):
        cleanup=(ROOT/"scripts/cleanup_repository.py").read_text()
        self.assertIn('"build.gradle.kts"',cleanup)
        self.assertIn("from optimize_android_release import optimize",cleanup)
        self.assertIn("optimize(android_gradle)",cleanup)

if __name__=="__main__":
    unittest.main()
