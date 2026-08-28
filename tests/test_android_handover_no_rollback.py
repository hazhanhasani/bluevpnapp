from pathlib import Path
import json
import unittest

ROOT = Path(__file__).resolve().parents[1]


class AndroidHandoverNoRollbackTest(unittest.TestCase):
    def text(self, path: str) -> str:
        return (ROOT / path).read_text(encoding="utf-8")

    def test_handover_state_model_is_explicit_and_packaged(self):
        state = self.text("android-source/BlueVpnHandoverState.kt")
        prepare = self.text("scripts/prepare_android.py")
        for phase in ["IDLE", "SELECTING", "SWITCHING", "CONNECTED", "FAILED", "DISCONNECTED"]:
            self.assertIn(phase, state)
        self.assertIn(
            'bluevpn_dir / "BlueVpnHandoverState.kt": ROOT / "android-source/BlueVpnHandoverState.kt"',
            prepare,
        )

    def test_live_switch_enters_selecting_and_switching(self):
        home = self.text("android-source/BlueVpnHomeActivity.kt")
        self.assertIn("handoverState.beginSelection()", home)
        self.assertIn("handoverState.beginSwitch()", home)

    def test_failed_preparation_stops_old_core_instead_of_rollback(self):
        home = self.text("android-source/BlueVpnHomeActivity.kt")
        start = home.index("if (scoredQueue.isEmpty())")
        end = home.index("// A route already known", start)
        body = home[start:end]
        self.assertIn("val failedLiveSwitch = liveLocationSwitch", body)
        self.assertIn("LauncherManager.stopService(this)", body)
        self.assertIn("BlueVpnPreferences.clearConnected(this)", body)
        self.assertIn("handoverState.failed()", body)
        self.assertIn("اتصال قبلی بازگردانی نمی‌شود", body)

    def test_terminal_failure_finishes_disconnected_after_core_stop(self):
        home = self.text("android-source/BlueVpnHomeActivity.kt")
        self.assertIn("handoverState.disconnected()", home)
        self.assertIn("BlueVpnRuntimeGate.endConnection(this)", home)
        self.assertIn("اتصال قبلی بازگردانی نشد", home)

    def test_successful_live_switch_marks_connected(self):
        home = self.text("android-source/BlueVpnHomeActivity.kt")
        self.assertIn("if (completedLiveSwitch) handoverState.connected()", home)


if __name__ == "__main__":
    unittest.main()
