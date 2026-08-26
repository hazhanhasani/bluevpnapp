import pathlib
import tempfile
import unittest
import importlib.util

ROOT=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location("apkval", ROOT/"scripts/validate_android_apk.py")
apkval=importlib.util.module_from_spec(spec)
spec.loader.exec_module(apkval)

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

if __name__=="__main__":
    unittest.main()
