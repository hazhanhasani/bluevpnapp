import json
import pathlib
import subprocess
import sys
import tempfile
import unittest

ROOT=pathlib.Path(__file__).resolve().parents[1]

class AuthoritativePhpReleaseGate4910(unittest.TestCase):
    def text(self,p): return (ROOT/p).read_text()

    def test_php_manifest_matches_shipped_php_files(self):
        payload=json.loads((ROOT/"bluevpn-manager/release_php_manifest.json").read_text())
        self.assertTrue(payload["authoritative"])
        expected=sorted(payload["php_files"])
        actual=sorted(
            p.relative_to(ROOT/"bluevpn-manager").as_posix()
            for p in (ROOT/"bluevpn-manager").rglob("*.php")
        )
        self.assertEqual(expected,actual)

    def test_cleanup_removes_overlay_stale_php(self):
        s=self.text("scripts/cleanup_repository.py")
        self.assertIn("load_authoritative_php_files",s)
        self.assertIn("remove_stale_php_files",s)
        self.assertIn("release_php_manifest.json",s)

    def test_ci_php_lint_is_visible_and_manifest_driven(self):
        wf=self.text(".github/workflows/build-apk.yml")
        validator=self.text("scripts/validate_php_release.py")
        self.assertIn("python scripts/validate_php_release.py",wf)
        start=wf.index("BlueVPN stability completion regression gate")
        end=wf.index("Upload failed Android source preparation log", start)
        gate=wf[start:end]
        self.assertNotIn('php -l "$file" >/dev/null',gate)
        self.assertIn("PHP LINT FAILED:",validator)
        self.assertIn("stale/unshipped PHP files remain",validator)

    def test_validator_checks_exact_manifest_before_lint(self):
        s=self.text("scripts/validate_php_release.py")
        self.assertIn("extra =",s)
        self.assertIn("missing =",s)
        self.assertIn('subprocess.run(',s)
        self.assertIn('["php", "-l", str(path)]',s)

if __name__=="__main__":
    unittest.main()
