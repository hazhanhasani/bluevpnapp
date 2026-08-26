import json
import pathlib
import re
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]

class CiNode24ArtifactLayout51001Tests(unittest.TestCase):
    def test_release_version(self):
        release = json.loads((ROOT / 'release.json').read_text(encoding='utf-8'))
        self.assertEqual(release['version'], '5.10.1')
        self.assertEqual(release['version_code'], 51001)

    def test_no_deprecated_cache_or_artifact_generations(self):
        workflows = '\n'.join(p.read_text(encoding='utf-8') for p in (ROOT / '.github/workflows').glob('*.yml'))
        self.assertNotIn('actions/cache@v4', workflows)
        self.assertNotIn('actions/upload-artifact@v6', workflows)
        self.assertNotIn('actions/download-artifact@v6', workflows)
        self.assertIn('actions/cache@caa296126883cff596d87d8935842f9db880ef25', workflows)
        self.assertIn('actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a', workflows)
        self.assertIn('actions/download-artifact@3e5f45b2cfb9172054b4087a40e8e0b5a5461e7c', workflows)

    def test_windows_setup_is_uploaded_from_artifact_root(self):
        workflow = (ROOT / '.github/workflows/build-windows.yml').read_text(encoding='utf-8')
        self.assertIn('$setup = "dist/BlueVPN-Setup-$version-$env:RID.exe"', workflow)
        self.assertIn('Move-Item $generatedSetup $setup -Force', workflow)
        self.assertIn('dist/BlueVPN-Setup-*.exe', workflow)
        self.assertNotIn('dist/installers/BlueVPN-Setup-*.exe\n            dist/installers/BlueVPN-Setup-*.exe.sha256', workflow)
        self.assertIn('Normalize Windows release payload layout', workflow)
        self.assertIn('Expected exactly one artifact named $NAME', workflow)

if __name__ == '__main__':
    unittest.main()
