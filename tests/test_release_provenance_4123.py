from pathlib import Path
import json
import unittest

ROOT = Path(__file__).resolve().parents[1]

class ReleaseProvenance4123(unittest.TestCase):
    def test_release_version(self):
        app = json.loads((ROOT / "branding/app.json").read_text())
        release = json.loads((ROOT / "release.json").read_text())
        self.assertEqual((app["version_name"], app["version_code"]), (release["version"], release["version_code"]))
        self.assertEqual((release["version"], release["version_code"]), (app["version_name"], app["version_code"]))

    def test_provenance_is_stable_before_and_during_ci(self):
        app = json.loads((ROOT / "branding/app.json").read_text())
        release = json.loads((ROOT / "release.json").read_text())
        expected = "source_declared_release_version"
        self.assertEqual(app["version_source"], expected)
        self.assertEqual(release["version_source"], expected)
        workflow = (ROOT / ".github/workflows/build-apk.yml").read_text()
        self.assertIn('app["version_source"] = (', workflow)
        self.assertIn('release["version_source"] = "source_declared_release_version"', workflow)

if __name__ == "__main__":
    unittest.main()
