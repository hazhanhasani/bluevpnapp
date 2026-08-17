from pathlib import Path
import json
import unittest

ROOT = Path(__file__).resolve().parents[1]

class PreGradleStageHardening4122(unittest.TestCase):
    def test_release_version(self):
        app=json.loads((ROOT/'branding/app.json').read_text())
        rel=json.loads((ROOT/'release.json').read_text())
        self.assertEqual((app['version_name'],app['version_code']),('4.12.4',41204))
        self.assertEqual((rel['version'],rel['version_code']),('4.12.4',41204))

    def test_pre_gradle_steps_are_logged(self):
        wf=(ROOT/'.github/workflows/build-apk.yml').read_text()
        for stage in ['repository-cleanup','overlay-bluevpn','aether-cache','setup-rust','build-aether-warp','verify-aether-warp','apply-auth-ui']:
            self.assertIn(stage, wf)
        self.assertGreaterEqual(wf.count('tee -a "$GITHUB_WORKSPACE/android-build.log"'), 6)

    def test_cache_is_not_build_fatal(self):
        wf=(ROOT/'.github/workflows/build-apk.yml').read_text()
        marker='- name: Restore pinned Aether runtime cache'
        block=wf[wf.index(marker):wf.index(marker)+500]
        self.assertIn('continue-on-error: true', block)

    def test_rust_external_action_removed(self):
        wf=(ROOT/'.github/workflows/build-apk.yml').read_text()
        self.assertNotIn('dtolnay/rust-toolchain@stable', wf)
        self.assertIn('rustup target add "$target"', wf)

if __name__ == '__main__':
    unittest.main()
