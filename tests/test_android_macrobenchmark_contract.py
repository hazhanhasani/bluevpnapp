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

    def test_release_gate_compiles_macrobenchmark(self):
        workflow = self.text(".github/workflows/build-apk.yml")
        self.assertIn(":benchmark:assembleBenchmark", workflow)
        self.assertEqual(workflow.count("./gradlew"), 1)


if __name__ == "__main__":
    unittest.main()
