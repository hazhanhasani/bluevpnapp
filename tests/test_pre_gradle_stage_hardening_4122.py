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
        workflow = ROOT/'.github/workflows/bluevpn.yml'
        if not workflow.exists():
            workflow = ROOT/'.github/workflows/build-apk.yml'
        wf=workflow.read_text()
        for stage in ['repository-cleanup','overlay-bluevpn','aether-cache','setup-rust','build-aether-warp','verify-aether-warp','apply-auth-ui']:
            self.assertIn(stage, wf)
        self.assertGreaterEqual(wf.count('tee -a "$GITHUB_WORKSPACE/android-build.log"'), 6)

    def test_cache_is_not_build_fatal(self):
        workflow = ROOT/'.github/workflows/bluevpn.yml'
        if not workflow.exists():
            workflow = ROOT/'.github/workflows/build-apk.yml'
        wf=workflow.read_text()
        marker='- name: Restore pinned Aether runtime cache'
        block=wf[wf.index(marker):wf.index(marker)+500]
        self.assertIn('continue-on-error: true', block)

    def test_rust_external_action_removed(self):
        workflow = ROOT/'.github/workflows/bluevpn.yml'
        if not workflow.exists():
            workflow = ROOT/'.github/workflows/build-apk.yml'
        wf=workflow.read_text()
        self.assertNotIn('dtolnay/rust-toolchain@stable', wf)
        self.assertIn('rustup target add "$target"', wf)

    def test_account_mmkv_iterators_preserve_upstream_cache_type(self):
        module_path = ROOT/'scripts/harden_android_locations.py'
        spec = importlib.util.spec_from_file_location('bluevpn_location_hardener', module_path)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)

        original=(ROOT/'android-source/BlueVpnAccountManager.kt').read_text()
        patched=module.harden_account_manager(original)

        self.assertIn('BLUEVPN_NULL_SAFE_MMKV_BOUNDARY_V5105', patched)
        self.assertIn('import com.v2ray.ang.dto.SubscriptionCache', patched)
        self.assertIn('private fun safeDecodedSubscriptions(): List<SubscriptionCache>', patched)
        self.assertIn('mapNotNull { it as? SubscriptionCache }', patched)
        self.assertNotIn('private fun safeDecodedSubscriptions(): List<SubscriptionItem>', patched)
        self.assertIn('private fun safeDecodedServerGuids(subscriptionGuid: String): List<String>', patched)
        self.assertNotIn('safeDecodedServerGuids(subscriptionRows:', patched)
        self.assertIn('safeDecodedSubscriptions()\n            .asSequence()', patched)
        self.assertIn('.subscription.enabled', patched)
        self.assertIn('.guid.trim()', patched)
        self.assertIn('(it as? String)?.trim()?.takeIf', patched)
        self.assertEqual(module.harden_account_manager(patched), patched)

    def test_account_hardener_does_not_rewrite_comments_or_strings(self):
        module_path = ROOT/'scripts/harden_android_locations.py'
        spec = importlib.util.spec_from_file_location('bluevpn_location_hardener_scan', module_path)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        sample = '''// MmkvManager.decodeSubscriptions()\nval x = MmkvManager.decodeSubscriptions()\nval note = "MmkvManager.decodeSubscriptions()"\n'''
        patched, count = module._replace_kotlin_calls(
            sample,
            'MmkvManager.decodeSubscriptions()',
            'safeDecodedSubscriptions()',
        )
        self.assertEqual(count, 1)
        self.assertIn('// MmkvManager.decodeSubscriptions()', patched)
        self.assertIn('val x = safeDecodedSubscriptions()', patched)
        self.assertIn('"MmkvManager.decodeSubscriptions()"', patched)

if __name__ == '__main__':
    unittest.main()
