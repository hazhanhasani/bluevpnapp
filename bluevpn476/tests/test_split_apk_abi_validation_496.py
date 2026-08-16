import importlib.util
import pathlib
import tempfile
import unittest
import zipfile

ROOT=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location("apkval", ROOT/"scripts/validate_android_apk.py")
apkval=importlib.util.module_from_spec(spec)
spec.loader.exec_module(apkval)

def make_apk(path, abi):
    with zipfile.ZipFile(path, "w", zipfile.ZIP_STORED) as z:
        z.writestr("AndroidManifest.xml", b"manifest")
        z.writestr("classes.dex", b"dex" * 50000)
        z.writestr("resources.arsc", b"r" * 120000)
        z.writestr(f"lib/{abi}/libbluevpn_aether.so", b"a" * 150000)

class SplitApkAbiValidation496(unittest.TestCase):
    def test_each_split_apk_can_contain_only_its_own_aether_abi(self):
        with tempfile.TemporaryDirectory() as td:
            td=pathlib.Path(td)
            arm64=td/"bluevpn-arm64-v8a.apk"
            armv7=td/"bluevpn-armeabi-v7a.apk"
            make_apk(arm64,"arm64-v8a")
            make_apk(armv7,"armeabi-v7a")
            r1=apkval.validate_apk(arm64)
            r2=apkval.validate_apk(armv7)
        self.assertEqual(r1["aether_abis"],["arm64-v8a"])
        self.assertEqual(r2["aether_abis"],["armeabi-v7a"])

    def test_signed_apk_set_requires_aggregate_coverage(self):
        with tempfile.TemporaryDirectory() as td:
            td=pathlib.Path(td)
            arm64=td/"bluevpn-arm64-v8a.apk"
            armv7=td/"bluevpn-armeabi-v7a.apk"
            make_apk(arm64,"arm64-v8a")
            make_apk(armv7,"armeabi-v7a")
            reports=apkval.validate_apk_set([arm64,armv7])
        covered=set()
        for report in reports:
            covered.update(report["aether_abis"])
        self.assertEqual(covered,{"arm64-v8a","armeabi-v7a"})

    def test_incomplete_apk_set_is_rejected(self):
        with tempfile.TemporaryDirectory() as td:
            td=pathlib.Path(td)
            arm64=td/"bluevpn-arm64-v8a.apk"
            make_apk(arm64,"arm64-v8a")
            with self.assertRaises(ValueError):
                apkval.validate_apk_set([arm64])

    def test_workflow_no_longer_requires_both_abis_per_single_apk(self):
        s=(ROOT/".github/workflows/build-apk.yml").read_text()
        start=s.index("Validate signed APK runtime contract")
        end=s.index("Upload APK runtime validation report", start)
        block=s[start:end]
        self.assertNotIn('grep -q "lib/arm64-v8a/libbluevpn_aether.so"', block)
        self.assertNotIn('grep -q "lib/armeabi-v7a/libbluevpn_aether.so"', block)
        self.assertIn("ABI coverage is validated across the complete signed APK set", block)

if __name__=="__main__":
    unittest.main()
