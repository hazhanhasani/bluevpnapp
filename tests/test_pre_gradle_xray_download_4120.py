from pathlib import Path
import json
import unittest

ROOT = Path(__file__).resolve().parents[1]


class PreGradleXrayDownload4120Tests(unittest.TestCase):
    def test_release_version_is_4120(self):
        app = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
        release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
        self.assertEqual(app["version_name"], "4.12.2")
        self.assertEqual(app["version_code"], 41202)
        self.assertEqual(release["version"], "4.12.2")

    def test_libv2ray_download_is_logged_retriable_and_verified(self):
        workflow = (ROOT / ".github/workflows/build-apk.yml").read_text(encoding="utf-8")
        self.assertIn("resolve-libv2ray-artifact", workflow)
        self.assertIn("verify-libv2ray-artifact", workflow)
        self.assertIn("continue-on-error: true", workflow)
        self.assertIn("--retry 5", workflow)
        self.assertIn("--retry-all-errors", workflow)
        self.assertIn("gh release download", workflow)
        self.assertIn("zipfile.ZipFile", workflow)
        self.assertIn("sha256", workflow)
        self.assertNotIn("robinraju/release-downloader", workflow)


if __name__ == "__main__":
    unittest.main()
