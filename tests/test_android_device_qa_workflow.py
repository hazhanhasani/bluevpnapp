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
        emulator_script = self.text("scripts/android_quality_emulator.sh")
        self.assertIn("bash scripts/android_quality_emulator.sh", workflow)
        self.assertIn(":app:connectedPlaystoreDebugAndroidTest", emulator_script)
        self.assertIn(":benchmark:connectedBenchmarkAndroidTest", emulator_script)

    def test_emulator_qa_enables_kvm_and_avoids_stateful_cd(self):
        workflow = self.text(".github/workflows/android-quality.yml")
        self.assertIn("Enable KVM acceleration for Android emulator", workflow)
        self.assertIn('sudo chown "$USER":"$(id -gn)" /dev/kvm', workflow)
        start = workflow.index("Run Android UI, state, screenshot and performance QA")
        end = workflow.index("Validate light and dark Locations screenshots", start)
        block = workflow[start:end]
        emulator_script = self.text("scripts/android_quality_emulator.sh")
        self.assertNotIn("\n            cd upstream/V2rayNG\n", block)
        self.assertIn("bash scripts/android_quality_emulator.sh", block)
        self.assertIn("upstream/V2rayNG/gradlew -p upstream/V2rayNG", emulator_script)
        self.assertIn("BLUEVPN_QA_APPLICATION_ID", workflow)

    def test_benchmark_jvm_targets_match_and_preflight_runs_before_emulator(self):
        benchmark = self.text("android-benchmark/build.gradle.kts")
        self.assertIn("extensions.configure<TestExtension>", benchmark)
        self.assertNotIn("\nandroid {", benchmark)
        self.assertIn("sourceCompatibility = JavaVersion.VERSION_17", benchmark)
        self.assertIn("targetCompatibility = JavaVersion.VERSION_17", benchmark)
        self.assertIn("jvmTarget.set(JvmTarget.JVM_17)", benchmark)
        self.assertNotIn("kotlinOptions", benchmark)

        workflow = self.text(".github/workflows/android-quality.yml")
        preflight = workflow.index("Preflight Android test and benchmark compilation")
        emulator = workflow.index("Run Android UI, state, screenshot and performance QA")
        self.assertLess(preflight, emulator)
        self.assertIn(":app:assemblePlaystoreDebugAndroidTest", workflow)
        self.assertIn(":benchmark:assembleBenchmark", workflow)

    def test_benchmark_test_apk_is_signed_for_device_install(self):
        benchmark = self.text("android-benchmark/build.gradle.kts")
        self.assertIn(
            'signingConfig = signingConfigs.getByName("debug")',
            benchmark,
        )

    def test_home_locations_accessibility_targets_clickable_card(self):
        home = self.text("android-source/BlueVpnHomeActivity.kt")
        start = home.index("private fun createServerCard")
        end = home.index("private fun createModeRow", start)
        body = home[start:end]
        self.assertIn('id = R.id.bluevpn_server_card', body)
        self.assertIn('contentDescription = "نمایش مکان‌ها"', body)
        self.assertIn("isClickable = true", body)
        self.assertIn("importantForAccessibility = View.IMPORTANT_FOR_ACCESSIBILITY_NO", body)

    def test_emulator_macrobenchmark_error_is_suppressed_only_for_ci_smoke(self):
        benchmark = self.text("android-benchmark/build.gradle.kts")
        self.assertIn(
            'testInstrumentationRunnerArguments["androidx.benchmark.suppressErrors"] = "EMULATOR"',
            benchmark,
        )
        self.assertIn("published performance numbers still require real hardware", benchmark)

    def test_device_qa_is_pinned_and_uploads_artifacts(self):
        workflow = self.text(".github/workflows/android-quality.yml")
        self.assertIn(
            "ReactiveCircus/android-emulator-runner@a421e43855164a8197daf9d8d40fe71c6996bb0d",
            workflow,
        )
        self.assertIn("Upload Android QA artifacts", workflow)
        self.assertIn("reports/android-quality/", workflow)

    def test_screenshots_are_pulled_before_benchmark_replaces_target_app(self):
        workflow = self.text(".github/workflows/android-quality.yml")
        start = workflow.index("Run Android UI, state, screenshot and performance QA")
        end = workflow.index("Validate light and dark Locations screenshots", start)
        block = workflow[start:end]
        emulator_script = self.text("scripts/android_quality_emulator.sh")
        app_tests = emulator_script.index(":app:connectedPlaystoreDebugAndroidTest")
        pull = emulator_script.index('adb pull "$QA_DEVICE_DIR/."')
        benchmark = emulator_script.index(":benchmark:connectedBenchmarkAndroidTest")
        self.assertLess(app_tests, pull)
        self.assertLess(pull, benchmark)
        self.assertNotIn("/sdcard/Android/data/$BLUEVPN_QA_APPLICATION_ID/files/qa/", emulator_script)
        self.assertIn('"/storage/emulated/0/Download"', emulator_script)
        self.assertIn('"/data/local/tmp"', emulator_script)
        self.assertIn("android.testInstrumentationRunnerArguments.bluevpnQaDir", emulator_script)
        self.assertIn("QA_DEVICE_DIR", emulator_script)
        self.assertIn("set -euo pipefail", emulator_script)
        self.assertNotIn('adb shell mkdir -p "/sdcard/Download/bluevpn-qa"', emulator_script)

    def test_visual_qa_captures_light_and_dark_rtl_locations(self):
        ui = self.text("android-test/BlueVpnLocationsUiTest.kt")
        self.assertIn("captureLightAndDarkRtlSnapshots", ui)
        self.assertIn('cmd uimode night no', ui)
        self.assertIn('cmd uimode night yes', ui)
        self.assertIn('locations-light-rtl.png', ui)
        self.assertIn('locations-dark-rtl.png', ui)
        self.assertIn("val qaDir = resolveQaDir(device)", ui)
        self.assertIn('getString("bluevpnQaDir")', ui)
        self.assertIn('"/storage/emulated/0/Download/bluevpn-qa"', ui)
        self.assertIn('"/data/local/tmp/bluevpn-qa"', ui)
        self.assertIn("screencap -p $qaDir/locations-light-rtl.png", ui)
        self.assertIn("screencap -p $qaDir/locations-dark-rtl.png", ui)
        self.assertNotIn("getExternalFilesDir", ui)

    def test_macrobenchmark_uses_stable_home_accessibility_selector(self):
        macro = self.text(
            "android-benchmark/src/main/java/com/bluevpn/benchmark/BlueVpnLocationsMacrobenchmark.kt"
        )
        baseline = self.text(
            "android-benchmark/src/main/java/com/bluevpn/benchmark/BlueVpnBaselineProfileGenerator.kt"
        )
        self.assertIn('By.res(packageName, "bluevpn_server_card")', macro)
        self.assertIn('By.res(packageName, "bluevpn_server_card")', baseline)
        self.assertNotIn('By.text("مکان‌ها")', macro)

    def test_benchmark_plugin_is_registered_in_version_catalog(self):
        prepare = self.text("scripts/prepare_android.py")
        self.assertIn('android-test = { id = "com.android.test", version.ref = "agp" }', prepare)
        self.assertIn("alias(libs.plugins.android.test) apply false", prepare)


if __name__ == "__main__":
    unittest.main()
