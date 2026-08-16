import pathlib, unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]

class ProductionRuntimeValidation492(unittest.TestCase):
    def text(self,p): return (ROOT/p).read_text()

    def test_runtime_audit_is_bounded_and_privacy_safe(self):
        s=self.text("android-source/BlueVpnRuntimeAudit.kt")
        self.assertIn("MAX_EVENTS = 64",s)
        self.assertIn("BlueVpnRuntimeAudit",s)
        self.assertIn("<redacted>",s)
        self.assertIn("<ip>",s)
        self.assertIn("TASK_REMOVED",s)
        self.assertIn("PREDICTIVE_FAILOVER",s)

    def test_runtime_audit_is_wired_to_service_and_controller(self):
        keep=self.text("android-source/BlueVpnWarpKeepAliveService.kt")
        ctrl=self.text("android-source/BlueVpnSystemController.kt")
        self.assertIn("WARP_FOREGROUND_START",keep)
        self.assertIn("WARP_FOREGROUND_STOP",keep)
        self.assertIn("TASK_REMOVED",keep)
        self.assertIn("VPN_STOP_REQUEST",ctrl)
        self.assertIn("VPN_RESTART_REQUEST",ctrl)
        self.assertIn("SYSTEM_START_REQUEST",ctrl)
        self.assertIn("VPN_CONNECTED",ctrl)

    def test_ai_diagnostics_include_runtime_audit(self):
        s=self.text("android-source/BlueVpnIntelligenceCore.kt")
        self.assertIn('put("runtime_audit", BlueVpnRuntimeAudit.snapshot(context))',s)

    def test_apk_validator_requires_aggregate_aether_abi_coverage(self):
        s=self.text("scripts/validate_android_apk.py")
        self.assertIn('SUPPORTED_AETHER_ABIS = {',s)
        self.assertIn('"arm64-v8a"',s)
        self.assertIn('"armeabi-v7a"',s)
        self.assertIn("validate_apk_set",s)
        self.assertIn("aggregate_aether_coverage",s)
        self.assertIn("zip_integrity",s)
        self.assertIn("sha256",s)

    def test_workflow_validates_signed_apk_after_signing(self):
        s=self.text(".github/workflows/build-apk.yml")
        sign=s.index("Align and sign APKs permanently")
        validate=s.index("Validate signed APK runtime contract")
        self.assertGreater(validate,sign)
        self.assertIn("validate_android_apk.py",s)
        validator=self.text("scripts/validate_android_apk.py")
        self.assertIn("BlueVpnWarpKeepAliveService",validator)
        self.assertIn("BlueVpnQuickTileService",validator)
        self.assertIn("BlueVpnSystemActionReceiver",validator)
        self.assertIn("POST_NOTIFICATIONS",validator)
        self.assertIn("apksigner",s)

    def test_prepare_android_installs_runtime_audit(self):
        s=self.text("scripts/prepare_android.py")
        self.assertIn("BlueVpnRuntimeAudit.kt",s)

if __name__=="__main__": unittest.main()
