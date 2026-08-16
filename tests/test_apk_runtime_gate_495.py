import pathlib, unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]

class ApkRuntimeGate495(unittest.TestCase):
    def text(self,p): return (ROOT/p).read_text()

    def test_generated_manifest_is_authoritative_for_components(self):
        s=self.text(".github/workflows/build-apk.yml")
        self.assertIn("Generated AndroidManifest.xml runtime contract: PASS", s)
        self.assertIn("BlueVpnWarpKeepAliveService", s)
        self.assertIn("BlueVpnQuickTileService", s)
        self.assertIn("BlueVpnSystemActionReceiver", s)
        self.assertIn("POST_NOTIFICATIONS", s)

    def test_post_sign_gate_has_no_manifest_decoder_dependency(self):
        s=self.text(".github/workflows/build-apk.yml")
        start=s.index("Validate signed APK runtime contract")
        end=s.index("Upload APK runtime validation report", start)
        block=s[start:end]
        self.assertNotIn("apkanalyzer", block)
        self.assertNotIn("aapt2", block)
        self.assertNotIn("manifest print", block)

    def test_post_sign_gate_keeps_real_apk_checks(self):
        s=self.text(".github/workflows/build-apk.yml")
        start=s.index("Validate signed APK runtime contract")
        end=s.index("Upload APK runtime validation report", start)
        block=s[start:end]
        self.assertIn("apksigner", block)
        self.assertIn("unzip -t", block)
        self.assertIn("lib/arm64-v8a/libbluevpn_aether.so", block)
        self.assertIn("lib/armeabi-v7a/libbluevpn_aether.so", block)
        self.assertIn("classes", block)

if __name__=="__main__":
    unittest.main()
