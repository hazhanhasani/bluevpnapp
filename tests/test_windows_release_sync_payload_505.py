import json
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding='utf-8')

class WindowsReleaseSyncPayload50302Tests(unittest.TestCase):
    def test_release_version(self):
        r=json.loads(text('release.json'))
        self.assertEqual(r['version'],'5.3.2')
        self.assertEqual(r['version_code'],50302)

    def test_workflow_pushes_complete_signed_windows_metadata(self):
        wf=text('.github/workflows/build-windows.yml')
        self.assertIn('RELEASE_JSON="$(gh api "repos/${GITHUB_REPOSITORY}/releases/tags/${TAG}"', wf)
        self.assertIn("'release_url':release.get('html_url','')", wf)
        self.assertIn("'published_at':release.get('published_at')", wf)
        self.assertIn("'commit':release.get('target_commitish')", wf)
        self.assertIn("'url':a.get('browser_download_url','')", wf)
        self.assertIn("'size':int(size)", wf)
        self.assertIn('release-sync error detail:', wf)
        self.assertIn('for gh_attempt in 1 2 3 4 5; do', wf)
        self.assertIn('GH_API_STATUS=$?', wf)
        self.assertIn('Direct release metadata push skipped because GitHub release metadata is temporarily unavailable', wf)
        self.assertIn('WordPress pull fallback will run', wf)

if __name__=='__main__':
    unittest.main()
