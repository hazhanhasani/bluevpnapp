from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class AndroidMacrobenchmarkContractTest(unittest.TestCase):
    def text(self, path: str) -> str:
        return (ROOT / path).read_text(encoding="utf-8")

    def test_macrobenchmark_module_targets_bluevpn_app(self):
        gradle = self.text("android-benchmark/build.gradle.kts")
        self.assertIn('alias(libs.plugins.android.test)', gradle)
        self.assertIn('targetProjectPath = ":app"', gradle)
        self.assertIn('missingDimensionStrategy("distribution", "playstore")', gradle)
        self.assertIn("benchmark-macro-junit4:1.3.3", gradle)

    def test_macrobenchmark_covers_startup_locations_search_and_scroll(self):
        src = self.text(
            "android-benchmark/src/main/java/com/bluevpn/benchmark/BlueVpnLocationsMacrobenchmark.kt"
        )
        self.assertIn("StartupTimingMetric()", src)
        self.assertIn("FrameTimingMetric()", src)
        self.assertIn("StartupMode.COLD", src)
        self.assertIn('By.res(packageName, "bluevpn_server_card")', src)
        self.assertIn("POST_NOTIFICATIONS", src)
        self.assertIn("prepareHomeForBenchmark", src)
        self.assertIn('setText("Germany")', src)
        self.assertIn("device.swipe(", src)

    def test_baseline_generator_covers_locations_journey(self):
        src = self.text(
            "android-benchmark/src/main/java/com/bluevpn/benchmark/BlueVpnBaselineProfileGenerator.kt"
        )
        self.assertIn("BaselineProfileRule", src)
        self.assertIn("includeInStartupProfile = true", src)
        self.assertIn('By.res(packageName, "bluevpn_server_card")', src)
        self.assertIn("POST_NOTIFICATIONS", src)
        self.assertIn("prepareHomeForBenchmark", src)
        self.assertIn('setText("Germany")', src)

    def test_prepare_script_overlays_benchmark_module(self):
        prepare = self.text("scripts/prepare_android.py")
        self.assertIn("def patch_benchmark_module()", prepare)
        self.assertIn('include(":benchmark")', prepare)
        self.assertIn('create("benchmark")', prepare)
        self.assertIn("patch_benchmark_module()", prepare)

    def test_quality_gate_resolves_screenshot_storage_before_instrumentation(self):
        workflow = self.text(".github/workflows/android-quality.yml")
        emulator_script = self.text("scripts/android_quality_emulator.sh")
        ui_test = self.text("android-test/BlueVpnLocationsUiTest.kt")

        self.assertIn("bash scripts/android_quality_emulator.sh", workflow)
        self.assertIn('"/storage/emulated/0/Download"', emulator_script)
        self.assertIn('"/data/local/tmp"', emulator_script)
        self.assertIn("android.testInstrumentationRunnerArguments.bluevpnQaDir", emulator_script)
        self.assertNotIn('adb shell mkdir -p "/sdcard/Download/bluevpn-qa"', emulator_script)

        self.assertIn("private fun resolveQaDir(): String", ui_test)
        self.assertIn('getString("bluevpnQaDir")', ui_test)
        self.assertIn('return "/data/local/tmp/bluevpn-qa"', ui_test)
        self.assertNotIn("BLUEVPN_QA_READY", ui_test)

    def test_quality_gate_runs_macrobenchmark_without_utp_trace_copy(self):
        script = self.text("scripts/android_quality_emulator.sh")
        self.assertIn(":app:installPlaystoreBenchmark", script)
        self.assertIn(":benchmark:installBenchmark", script)
        self.assertIn("adb shell am instrument -w -r", script)
        self.assertIn(
            "-e class com.bluevpn.benchmark.BlueVpnLocationsMacrobenchmark",
            script,
        )
        self.assertIn("macrobenchmark-instrumentation.txt", script)
        self.assertIn("java.io.EOFException", script)
        self.assertIn("dump badging", script)
        self.assertIn("BENCH_PACKAGE", script)
        self.assertIn("INSTRUMENTATION_LIST", script)
        self.assertNotIn("grep 'com.bluevpn.benchmark'", script)
        self.assertNotIn(":benchmark:connectedBenchmarkAndroidTest", script)

    def test_release_gate_compiles_macrobenchmark(self):
        workflow = self.text(".github/workflows/build-apk.yml")
        self.assertIn(":benchmark:assembleBenchmark", workflow)
        self.assertEqual(workflow.count("./gradlew"), 1)


if __name__ == "__main__":
    unittest.main()
