import json
import tempfile
import unittest
import zipfile
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[1]


class ThemeReleaseProbePackaging4168(unittest.TestCase):
    def test_release_version(self):
        release = json.loads((ROOT / 'release.json').read_text(encoding='utf-8'))
        self.assertEqual(release['version'], '6.2.2')
        self.assertEqual(release['version_code'], 60202)

    def test_optional_exact_tag_404_is_declared_expected(self):
        updater = (ROOT / 'bluevpn-site/inc/class-bluevpn-site-updater.php').read_text(encoding='utf-8')
        self.assertIn("expect_http_status_once($url, [404])", updater)
        self.assertIn("releases/tags/", updater)

    def test_theme_release_workflow_contract_exists(self):
        workflow = (ROOT / '.github/workflows/bluevpn-site-theme-release.yml').read_text(encoding='utf-8')
        self.assertIn('bluevpn-site-v${THEME_VERSION}', workflow)
        self.assertIn('bluevpn-site-theme-v${THEME_VERSION}.zip', workflow)
        self.assertIn('gh release create', workflow)

    def test_platform_packager_preserves_dotgithub(self):
        with tempfile.TemporaryDirectory() as td:
            out = Path(td) / 'platform.zip'
            subprocess.run(['python', str(ROOT / 'scripts/package_platform.py'), str(out)], check=True, cwd=ROOT)
            with zipfile.ZipFile(out) as zf:
                names = set(zf.namelist())
            self.assertIn('.github/workflows/bluevpn-site-theme-release.yml', names)
            self.assertIn('.github/workflows/bluevpn-sentinel.yml', names)


if __name__ == '__main__':
    unittest.main()
