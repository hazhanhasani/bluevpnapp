import pathlib, unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]

class MahsaCoreCanary497(unittest.TestCase):
    def text(self,p): return (ROOT/p).read_text()

    def test_core_flavor_is_build_time_and_not_dual_aar_runtime(self):
        s=self.text("android-source/BlueVpnCoreFlavor.kt")
        self.assertIn("__BLUEVPN_CORE_FAMILY__", s)
        self.assertIn("cannot coexist safely in one APK", s)
        self.assertIn("IS_MAHSA_CANARY", s)

    def test_prepare_android_resolves_core_flavor_from_environment(self):
        s=self.text("scripts/prepare_android.py")
        self.assertIn("BLUEVPN_CORE_MODE", s)
        self.assertIn("mahsa-canary", s)
        self.assertIn("8a5c4d4549338e13fa00ac1fe1e431074823f339", s)

    def test_production_defaults_to_stock_and_rejects_full_canary(self):
        s=self.text(".github/workflows/build-apk.yml")
        self.assertIn("core_mode:", s)
        self.assertIn("default: stock", s)
        self.assertIn("Experimental Mahsa-Core canary cannot be published as a full production release", s)
        self.assertIn("github.event.client_payload.core_mode || 'stock'", s)

    def test_canary_build_is_pinned_and_verifies_go_mod(self):
        s=self.text(".github/workflows/build-apk.yml")
        self.assertIn("8a5c4d4549338e13fa00ac1fe1e431074823f339", s)
        self.assertIn("github.com/GFW-knocker/Xray-core v1.26.5-mahsa-r1", s)
        self.assertIn("golang.org/x/mobile v0.0.0-20260217195705-b56b3793a9c4", s)
        self.assertIn("gomobile bind", s)
        self.assertIn("-androidapi 21", s)

    def test_stock_and_canary_results_are_separated_in_ai_dataset(self):
        db=self.text("bluevpn-manager/includes/class-bluevpn-db.php")
        ai=self.text("bluevpn-manager/includes/class-bluevpn-ai.php")
        android=self.text("android-source/BlueVpnAi.kt")
        self.assertIn("ai_core_aggregates", db)
        self.assertIn("core_family varchar(32)", db)
        self.assertIn("update_core_aggregate", ai)
        self.assertIn("core_comparison", ai)
        self.assertIn('put("core_family", BlueVpnCoreFlavor.FAMILY)', android)

    def test_blueai_evaluates_canary_without_auto_promote(self):
        ops=self.text("bluevpn-manager/includes/class-bluevpn-ai-ops.php")
        self.assertIn("detect_core_canary_opportunities", ops)
        self.assertIn("core_canary_outperforms", ops)
        self.assertIn("core_canary_underperforms", ops)
        self.assertIn("پس از تأیید دستی", ops)

    def test_provenance_document_is_shipped(self):
        s=self.text("third_party/MAHSA_CORE_CANARY.md")
        self.assertIn("8a5c4d4549338e13fa00ac1fe1e431074823f339", s)
        self.assertIn("LGPL-3.0", s)
        self.assertIn("MPL-2.0", s)

if __name__=="__main__":
    unittest.main()
