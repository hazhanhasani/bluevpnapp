from pathlib import Path
import importlib.util
import json
import unittest

ROOT = Path(__file__).resolve().parents[1]

class PreGradleStageHardening4122(unittest.TestCase):
    def test_release_version(self):
        app=json.loads((ROOT/'branding/app.json').read_text())
        rel=json.loads((ROOT/'release.json').read_text())
        self.assertEqual((app['version_name'],app['version_code']),(rel['version'],rel['version_code']))
        self.assertEqual((rel['version'],rel['version_code']),(app['version_name'],app['version_code']))

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

    def test_account_mmkv_iterators_are_hardened_before_r8(self):
        module_path = ROOT/'scripts/harden_android_locations.py'
        spec = importlib.util.spec_from_file_location('bluevpn_location_hardener', module_path)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)

        original=(ROOT/'android-source/BlueVpnAccountManager.kt').read_text()
        patched=module.harden_account_manager(original)

        self.assertIn('BLUEVPN_NULL_SAFE_MMKV_BOUNDARY_V5105', patched)
        self.assertIn('private fun safeDecodedSubscriptions(): List<SubscriptionItem>', patched)
        self.assertIn('private fun safeDecodedServerGuids(subscriptionGuid: String): List<String>', patched)
        self.assertEqual(patched.count('MmkvManager.decodeSubscriptions()'), 1)
        self.assertEqual(patched.count('MmkvManager.decodeServerList('), 1)
        self.assertNotIn('MmkvManager.decodeSubscriptions()\n            .asSequence()', patched)
        self.assertIn('safeDecodedSubscriptions()\n            .asSequence()', patched)
        self.assertIn('mapNotNull { it as? SubscriptionItem }', patched)
        self.assertIn('(it as? String)?.trim()?.takeIf', patched)
        self.assertEqual(module.harden_account_manager(patched), patched)

if __name__ == '__main__':
    unittest.main()
