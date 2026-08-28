from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class AndroidInstrumentationPerformanceTest(unittest.TestCase):
    def text(self, path: str) -> str:
        return (ROOT / path).read_text(encoding="utf-8")

    def test_instrumentation_dependencies_and_runner_are_wired(self):
        prepare = self.text("scripts/prepare_android.py")
        self.assertIn("testInstrumentationRunner", prepare)
        for dep in [
            "androidx.test.ext:junit:1.2.1",
            "androidx.test:runner:1.6.2",
            "androidx.test:rules:1.6.1",
            "androidx.test.espresso:espresso-core:3.6.1",
            "androidx.benchmark:benchmark-junit4:1.3.3",
        ]:
            self.assertIn(dep, prepare)

    def test_real_ui_and_perf_tests_are_packaged(self):
        prepare = self.text("scripts/prepare_android.py")
        ui = self.text("android-test/BlueVpnLocationsUiTest.kt")
        perf = self.text("android-test/BlueVpnLocationDiffBenchmark.kt")
        self.assertIn("BlueVpnLocationsUiTest.kt", prepare)
        self.assertIn("BlueVpnLocationDiffBenchmark.kt", prepare)
        self.assertIn("ActivityScenario.launch", ui)
        self.assertIn("scenario.recreate()", ui)
        self.assertIn("RecyclerView::class.java", ui)
        self.assertIn("BenchmarkRule", perf)
        self.assertIn("0 until 1000", perf)

    def test_release_build_compiles_android_test_apk(self):
        workflow = self.text(".github/workflows/build-apk.yml")
        self.assertEqual(workflow.count("./gradlew"), 1)
        self.assertIn(":app:assemblePlaystoreDebugAndroidTest", workflow)
        self.assertIn(":benchmark:assembleBenchmark", workflow)

    def test_baseline_profile_covers_critical_locations_journey(self):
        profile = self.text("android-source/baseline-prof.txt")
        prepare = self.text("scripts/prepare_android.py")
        for cls in [
            "BlueVpnHomeActivity",
            "BlueVpnServersActivity",
            "BlueVpnLocationUtil",
            "BlueVpnSmartSelector",
            "BlueVpnLocationRowDiff",
        ]:
            self.assertIn(cls, profile)
        self.assertIn('APP / "src/main/baseline-prof.txt"', prepare)

    def test_process_death_state_has_durable_fallback(self):
        src = self.text("android-source/BlueVpnServersActivity.kt")
        self.assertIn("restorePersistedLocationUiState()", src)
        self.assertIn("persistLocationUiState()", src)
        self.assertIn('.putString("tab", selectedTab.name)', src)
        self.assertIn('.putString("query_text", queryText)', src)
        self.assertIn('.putStringSet("expanded_keys"', src)


if __name__ == "__main__":
    unittest.main()
