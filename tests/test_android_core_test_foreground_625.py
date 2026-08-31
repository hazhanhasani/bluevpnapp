from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class AndroidCoreTestForeground625Tests(unittest.TestCase):
    def test_prepare_patches_core_test_service_before_native_init(self):
        src = (ROOT / "scripts/prepare_android.py").read_text(encoding="utf-8")
        self.assertIn("def patch_core_test_service_foreground_deadline()", src)
        self.assertIn('CoreTestService.kt', src)
        self.assertIn("Android foreground-service deadline: notify before native core init.", src)
        self.assertIn("CoreNativeManager.initCoreEnv(this)", src)
        self.assertIn("NotificationHelper.startForeground(", src)
        self.assertIn(
            "CoreTestService must call startForeground before CoreNativeManager.initCoreEnv",
            src,
        )
        self.assertIn("CoreTestService still foregrounds too late in onStartCommand", src)

    def test_patch_runs_before_other_android_overlay_validation(self):
        src = (ROOT / "scripts/prepare_android.py").read_text(encoding="utf-8")
        main = src[src.index("def main() -> None:"):]
        foreground = main.index("patch_core_test_service_foreground_deadline()")
        notification = main.index("patch_system_notification()")
        runtime_assert = main.index("assert_upstream_runtime_unchanged(runtime_snapshot)")
        self.assertLess(foreground, notification)
        self.assertLess(foreground, runtime_assert)


if __name__ == "__main__":
    unittest.main()
