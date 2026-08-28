import json
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class BoundedVersionPolicy500Tests(unittest.TestCase):
    def test_all_canonical_components_are_500(self):
        release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
        branding = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
        self.assertEqual(release["version"], "6.0.9")
        self.assertEqual(release["version_code"], 60009)
        self.assertEqual(branding["version_name"], "6.0.9")
        self.assertEqual(branding["version_code"], 60009)
        self.assertEqual(release["android_version"], "6.0.9")
        self.assertEqual(release["android_version_code"], 60009)
        self.assertEqual(release["windows_version"], "6.0.9")
        self.assertEqual(release["windows_version_code"], 60009)
        self.assertEqual(release["manager_version"], "6.0.9")
        self.assertEqual(release["site_version"], "6.0.9")
        self.assertEqual(release["theme_version"], "6.0.9")

    def test_minor_and_patch_are_bounded(self):
        version = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))["version"]
        m = re.fullmatch(r"(\d+)\.(\d+)\.(\d+)", version)
        self.assertIsNotNone(m)
        _, minor, patch = map(int, m.groups())
        self.assertLessEqual(minor, 10)
        self.assertLessEqual(patch, 10)
        self.assertIn("minor version", (ROOT / "scripts/validate_release.py").read_text(encoding="utf-8"))
        self.assertIn("After x.10.10 use (x+1).0.0", (ROOT / ".github/workflows/build-apk.yml").read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()
