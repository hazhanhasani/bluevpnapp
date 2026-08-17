from pathlib import Path
import json
import unittest

ROOT = Path(__file__).resolve().parents[1]

class PreGradlePython4121(unittest.TestCase):
    def test_release_version(self):
        app = json.loads((ROOT / "branding/app.json").read_text())
        rel = json.loads((ROOT / "release.json").read_text())
        self.assertEqual((app["version_name"], app["version_code"]), ("4.12.8", 41208))
        self.assertEqual((rel["version"], rel["version_code"]), ("4.12.8", 41208))

    def test_no_setup_python_after_aar(self):
        wf = (ROOT / ".github/workflows/build-apk.yml").read_text()
        self.assertNotIn("uses: actions/setup-python@v6", wf)
        self.assertIn("BUILD_STAGE=prepare-python-environment", wf)
        self.assertIn("python -m pip install", wf)
        self.assertIn('tee -a "$GITHUB_WORKSPACE/android-build.log"', wf)

    def test_gradle_preserves_pregradle_log(self):
        wf = (ROOT / ".github/workflows/build-apk.yml").read_text()
        self.assertIn('2>&1 | tee -a "$GITHUB_WORKSPACE/android-build.log"', wf)

if __name__ == "__main__":
    unittest.main()
