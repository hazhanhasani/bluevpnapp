import pathlib
import tempfile
import unittest
import importlib.util
from unittest.mock import patch

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

    def test_signed_playstore_abi_version_code_matches_upstream_offset(self):
        contract = {"version_name": "6.3.5", "version_code": 60305,
                    "application_id": "ir.blluepanel.bluevpn"}
        with patch.object(
            apkval, "apkanalyzer_value",
            side_effect=["6.3.5", "4060305", "ir.blluepanel.bluevpn"],
        ):
            report = apkval.validate_embedded_version(
                pathlib.Path("BlueVPN_6.3.5_arm64-v8a.apk"), contract, "apkanalyzer"
            )
        self.assertEqual(report["version_code"], 4060305)
        self.assertEqual(report["release_identity"], "PASS")

    def test_signed_playstore_rejects_raw_or_wrong_version_code(self):
        contract = {"version_name": "6.3.5", "version_code": 60305,
                    "application_id": "ir.blluepanel.bluevpn"}
        for wrong_code in ("60305", "4060304", "5060305"):
            with self.subTest(wrong_code=wrong_code):
                with patch.object(
                    apkval, "apkanalyzer_value",
                    side_effect=["6.3.5", wrong_code, "ir.blluepanel.bluevpn"],
                ):
                    with self.assertRaisesRegex(ValueError, "versionCode"):
                        apkval.validate_embedded_version(
                            pathlib.Path("BlueVPN_6.3.5_arm64-v8a.apk"),
                            contract, "apkanalyzer"
                        )

    def test_workflow_does_not_depend_on_aapt2_xmltree(self):
        s=(ROOT/".github/workflows/build-apk.yml").read_text()
        self.assertNotIn('aapt2" dump xmltree',s)
        self.assertIn("Generated AndroidManifest.xml runtime contract: PASS",s)
        self.assertIn("Validate signed APK runtime contract",s)

if __name__=="__main__":
    unittest.main()
