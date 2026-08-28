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

    def test_emulator_qa_enables_kvm_and_avoids_stateful_cd(self):
        workflow = self.text(".github/workflows/android-quality.yml")
        self.assertIn("Enable KVM acceleration for Android emulator", workflow)
        self.assertIn('sudo chown "$USER":"$(id -gn)" /dev/kvm', workflow)
        start = workflow.index("Run Android UI, state, screenshot and performance QA")
        end = workflow.index("Validate light and dark Locations screenshots", start)
        block = workflow[start:end]
        self.assertNotIn("\n            cd upstream/V2rayNG\n", block)
        self.assertIn("upstream/V2rayNG/gradlew -p upstream/V2rayNG", block)
        self.assertIn("BLUEVPN_QA_APPLICATION_ID", workflow)

    def test_benchmark_jvm_targets_match_and_preflight_runs_before_emulator(self):
        benchmark = self.text("android-benchmark/build.gradle.kts")
        self.assertIn("sourceCompatibility = JavaVersion.VERSION_11", benchmark)
        self.assertIn("targetCompatibility = JavaVersion.VERSION_11", benchmark)
        self.assertIn('jvmTarget = "11"', benchmark)

        workflow = self.text(".github/workflows/android-quality.yml")
        preflight = workflow.index("Preflight Android test and benchmark compilation")
        emulator = workflow.index("Run Android UI, state, screenshot and performance QA")
        self.assertLess(preflight, emulator)
        self.assertIn(":app:assemblePlaystoreDebugAndroidTest", workflow)
        self.assertIn(":benchmark:assembleBenchmark", workflow)

    def test_device_qa_is_pinned_and_uploads_artifacts(self):
        workflow = self.text(".github/workflows/android-quality.yml")
        self.assertIn(
            "ReactiveCircus/android-emulator-runner@a421e43855164a8197daf9d8d40fe71c6996bb0d",
            workflow,
        )
        self.assertIn("Upload Android QA artifacts", workflow)
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
