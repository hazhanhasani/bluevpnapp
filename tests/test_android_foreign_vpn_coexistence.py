from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


def text(rel: str) -> str:
    return (ROOT / rel).read_text(encoding="utf-8")


class AndroidForeignVpnCoexistenceTests(unittest.TestCase):
    def test_runtime_gate_detects_android_vpn_transport(self):
        source = text("android-source/BlueVpnRuntimeGate.kt")
        self.assertIn("NetworkCapabilities.TRANSPORT_VPN", source)
        self.assertIn("fun otherVpnActive(context: Context): Boolean", source)
        self.assertIn("CoreServiceManager.isRunning()", source)
        self.assertIn("BlueVpnWarpEngine.isRunning()", source)

    def test_connection_gate_blocks_foreign_vpn_before_ownership(self):
        source = text("android-source/BlueVpnRuntimeGate.kt")
        begin = source[source.index("fun beginConnection("):]
        foreign = begin.index("if (otherVpnActive(app))")
        ownership = begin.index("connectionActiveMemory = true")
        self.assertLess(foreign, ownership)
        self.assertIn("PREPARING:blocked_other_vpn", begin)

    def test_system_start_and_recovery_paths_fail_closed(self):
        source = text("android-source/BlueVpnSystemController.kt")
        self.assertGreaterEqual(source.count("BlueVpnRuntimeGate.otherVpnActive(app)"), 3)
        self.assertIn('"blocked_other_vpn"', source)
        self.assertIn('"recovery_blocked_other_vpn"', source)

    def test_migration_removes_cross_workflow_fanout(self):
        source = text("scripts/migrate_bluevpn_unified_pipeline.py")
        self.assertIn('if filename == "project-health.yml" and str(old_id) == "fanout-main-builds"', source)
        self.assertIn('if "gh workflow run " in generated:', source)
        self.assertIn('expected exactly one workflow', source)
        self.assertIn(':app:compilePlaystoreReleaseKotlin', source)


if __name__ == "__main__":
    unittest.main()
