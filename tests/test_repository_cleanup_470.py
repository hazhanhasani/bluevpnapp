import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CLEANUP = ROOT / "scripts" / "cleanup_repository.py"
MANIFEST = ROOT / "tests" / "release_test_manifest.json"


class RepositoryCleanup470Tests(unittest.TestCase):
    def _fixture(self, root: Path):
        (root / "scripts").mkdir()
        (root / "tests").mkdir()
        (root / "android-source" / "generated").mkdir(parents=True)
        (root / "scripts" / "cleanup_repository.py").write_text(CLEANUP.read_text(encoding="utf-8"), encoding="utf-8")
        manifest = {
            "schema": 1,
            "authoritative": True,
            "tests": ["test_current_release.py", "test_repository_cleanup_470.py"],
        }
        (root / "tests" / "release_test_manifest.json").write_text(json.dumps(manifest), encoding="utf-8")
        for name in manifest["tests"]:
            (root / "tests" / name).write_text("# approved current release test\n", encoding="utf-8")

    def test_manifest_removes_arbitrary_overlay_stale_tests_without_deleting_release_tests(self):
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            self._fixture(root)
            stale = [
                "test_blueai_scoring.py",
                "test_admin_security.py",
                "test_admin_sms_catalog_render_v359.py",
                "test_future_unknown_legacy_module.py",
            ]
            for name in stale:
                (root / "tests" / name).write_text("raise RuntimeError('stale test must not run')\n", encoding="utf-8")

            cp = subprocess.run(
                [sys.executable, str(root / "scripts" / "cleanup_repository.py")],
                cwd=root,
                check=False,
                text=True,
                stdout=subprocess.PIPE,
                stderr=subprocess.STDOUT,
            )
            self.assertEqual(cp.returncode, 0, cp.stdout)
            for name in stale:
                self.assertFalse((root / "tests" / name).exists(), name)
            self.assertTrue((root / "tests" / "test_current_release.py").exists())
            self.assertTrue((root / "tests" / "test_repository_cleanup_470.py").exists())
            self.assertFalse((root / "android-source" / "generated").exists())

    def test_current_release_manifest_exactly_matches_shipped_python_test_modules(self):
        payload = json.loads(MANIFEST.read_text(encoding="utf-8"))
        self.assertTrue(payload["authoritative"])
        approved = set(payload["tests"])
        shipped = {p.name for p in (ROOT / "tests").glob("test_*.py")}
        self.assertEqual(approved, shipped)

    def test_cleanup_fails_closed_if_manifest_is_missing(self):
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            self._fixture(root)
            (root / "tests" / "release_test_manifest.json").unlink()
            cp = subprocess.run(
                [sys.executable, str(root / "scripts" / "cleanup_repository.py")],
                cwd=root,
                check=False,
                text=True,
                stdout=subprocess.PIPE,
                stderr=subprocess.STDOUT,
            )
            self.assertNotEqual(cp.returncode, 0)
            self.assertIn("release test manifest", cp.stdout)


if __name__ == "__main__":
    unittest.main(verbosity=2)
