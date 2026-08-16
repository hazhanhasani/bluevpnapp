import pathlib, unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]

class NativeNetworkAdaptationNoMahsa499(unittest.TestCase):
    def text(self,p): return (ROOT/p).read_text()

    def test_build_is_stock_xray_only(self):
        workflow=self.text(".github/workflows/build-apk.yml")
        prepare=self.text("scripts/prepare_android.py")
        self.assertNotIn("mahsa-canary", workflow.lower())
        self.assertNotIn("GFW-knocker/AndroidLibXrayLite", workflow)
        self.assertNotIn("BLUEVPN_CORE_MODE", workflow)
        self.assertNotIn("BlueVpnCoreFlavor.kt", prepare)
        self.assertNotIn("patch_bluevpn_core_flavor", prepare)

    def test_no_mahsa_core_source_or_provenance_is_shipped(self):
        self.assertFalse((ROOT/"android-source/BlueVpnCoreFlavor.kt").exists())
        self.assertFalse((ROOT/"third_party/MAHSA_CORE_CANARY.md").exists())

    def test_native_adaptation_is_bluevpn_owned_and_network_aware(self):
        s=self.text("android-source/BlueVpnNativeNetworkAdaptation.kt")
        self.assertIn("BlueVPN-native network adaptation", s)
        self.assertIn("networkFingerprint(context).id", s)
        self.assertIn("observeSuccess", s)
        self.assertIn("observeFailure", s)
        self.assertIn("rankingAdjustment", s)
        self.assertIn("UDP_BLOCKED", s)
        self.assertIn("fragmentAware", s)

    def test_native_adaptation_consumes_real_route_outcomes(self):
        route=self.text("android-source/BlueVpnRouteIntelligence.kt")
        smart=self.text("android-source/BlueVpnSmartSelector.kt")
        self.assertIn("BlueVpnNativeNetworkAdaptation.observeSuccess", route)
        self.assertIn("BlueVpnNativeNetworkAdaptation.observeFailure", route)
        self.assertIn("BlueVpnNativeNetworkAdaptation.rankingAdjustment", smart)
        self.assertIn("score += nativeNetworkAdjustment", smart)

    def test_native_adaptation_does_not_rewrite_credentials_or_dns(self):
        s=self.text("android-source/BlueVpnNativeNetworkAdaptation.kt")
        self.assertNotIn("setServer", s)
        self.assertNotIn("setPassword", s)
        self.assertNotIn("setId", s)
        self.assertNotIn("setDns", s)
        self.assertIn("must not", s)

    def test_wordpress_no_longer_has_core_canary_dataset(self):
        db=self.text("bluevpn-manager/includes/class-bluevpn-db.php")
        ai=self.text("bluevpn-manager/includes/class-bluevpn-ai.php")
        ops=self.text("bluevpn-manager/includes/class-bluevpn-ai-ops.php")
        self.assertNotIn("ai_core_aggregates", db)
        self.assertNotIn("core_family varchar", db)
        self.assertNotIn("update_core_aggregate", ai)
        self.assertNotIn("core_canary_outperforms", ops)
        self.assertNotIn("Mahsa-Core", ops)

if __name__=="__main__":
    unittest.main()
