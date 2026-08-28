from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class AndroidDeviceQaWorkflowTest(unittest.TestCase):
    def text(self, path: str) -> str:
        return (ROOT / path).read_text(encoding="utf-8")

    def test_device_qa_runs_real_emulator_tests(self):
        workflow = self.text(".github/workflows/android-quality.yml")
        self.assertIn("ReactiveCircus/android-emulator-runner@", workflow)
        self.assertIn("api-level: 35", workflow)
        self.assertIn("arch: x86_64", workflow)
        self.assertIn(":app:connectedPlaystoreDebugAndroidTest", workflow)
        self.assertIn(":benchmark:connectedBenchmarkAndroidTest", workflow)

    def test_device_qa_is_pinned_and_uploads_artifacts(self):
        workflow = self.text(".github/workflows/android-device-qa.yml")
        self.assertIn(
            "ReactiveCircus/android-emulator-runner@a421e43855164a8197daf9d8d40fe71c6996bb0d",
            workflow,
        )
        self.assertIn("Upload Android device QA artifacts", workflow)
        self.assertIn("reports/android-quality/", workflow)

    def test_visual_qa_captures_light_and_dark_rtl_locations(self):
        ui = self.text("android-test/BlueVpnLocationsUiTest.kt")
        self.assertIn("captureLightAndDarkRtlSnapshots", ui)
        self.assertIn('cmd uimode night no', ui)
        self.assertIn('cmd uimode night yes', ui)
        self.assertIn('locations-light-rtl.png', ui)
        self.assertIn('locations-dark-rtl.png', ui)
        self.assertIn("device.takeScreenshot", ui)

    def test_macrobenchmark_uses_stable_home_accessibility_selector(self):
        macro = self.text(
            "android-benchmark/src/main/java/com/bluevpn/benchmark/BlueVpnLocationsMacrobenchmark.kt"
        )
        baseline = self.text(
            "android-benchmark/src/main/java/com/bluevpn/benchmark/BlueVpnBaselineProfileGenerator.kt"
        )
        self.assertIn('By.desc("نمایش مکان‌ها")', macro)
        self.assertIn('By.desc("نمایش مکان‌ها")', baseline)
        self.assertNotIn('By.text("مکان‌ها")', macro)

    def test_benchmark_plugin_is_registered_in_version_catalog(self):
        prepare = self.text("scripts/prepare_android.py")
        self.assertIn('android-test = { id = "com.android.test", version.ref = "agp" }', prepare)
        self.assertIn("alias(libs.plugins.android.test) apply false", prepare)


if __name__ == "__main__":
    unittest.main()
