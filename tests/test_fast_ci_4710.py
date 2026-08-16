from pathlib import Path
import json
import unittest

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = (ROOT / '.github/workflows/build-apk.yml').read_text(encoding='utf-8')


class FastCi4710Tests(unittest.TestCase):
    def test_version_is_4710(self):
        app = json.loads((ROOT / 'branding/app.json').read_text(encoding='utf-8'))
        release = json.loads((ROOT / 'release.json').read_text(encoding='utf-8'))
        self.assertEqual((app['version_name'], app['version_code']), ('4.11.8', 41108))
        self.assertEqual((release['version'], release['version_code']), ('4.11.8', 41108))

    def test_manual_fast_and_repository_full_defaults_are_explicit(self):
        self.assertIn('default: fast', WORKFLOW)
        self.assertIn("github.event_name == 'repository_dispatch'", WORKFLOW)
        self.assertIn("github.event.client_payload.build_mode || 'full'", WORKFLOW)
        self.assertIn("inputs.build_mode || 'fast'", WORKFLOW)

    def test_expensive_native_artifacts_are_cached(self):
        self.assertIn('Restore libhevtun cache', WORKFLOW)
        self.assertIn('Restore libv2ray AAR cache', WORKFLOW)
        self.assertIn('Restore pinned Aether runtime cache', WORKFLOW)
        self.assertIn("steps.aether-cache.outputs.cache-hit != 'true'", WORKFLOW)
        self.assertIn('${{ steps.config.outputs.aether_ref }}', WORKFLOW)

    def test_gradle_compile_and_assemble_use_one_invocation(self):
        gradle_calls = WORKFLOW.count('./gradlew')
        self.assertEqual(gradle_calls, 1)
        self.assertIn(':app:compilePlaystoreReleaseKotlin', WORKFLOW)
        self.assertIn(':app:assemblePlaystoreRelease', WORKFLOW)
        self.assertIn('--build-cache', WORKFLOW)
        self.assertIn('--parallel', WORKFLOW)

    def test_fast_mode_uploads_signed_apk_before_wordpress_barrier(self):
        upload = WORKFLOW.index('- name: Upload fast signed APK artifact')
        manager = WORKFLOW.index('- name: Publish synchronized BlueVPN Manager release barrier')
        wordpress = WORKFLOW.index('- name: Wait for WordPress control-plane auto-update')
        self.assertLess(upload, manager)
        self.assertLess(upload, wordpress)
        self.assertIn("if: ${{ env.BLUEVPN_BUILD_MODE == 'fast' }}", WORKFLOW[upload:manager])
        self.assertIn("if: ${{ env.BLUEVPN_BUILD_MODE == 'full' }}", WORKFLOW[manager:wordpress])

    def test_production_only_steps_are_full_mode(self):
        for name in [
            'Publish synchronized BlueVPN Manager release barrier',
            'Wait for WordPress control-plane auto-update',
            'Create GitHub Release metadata and checksums',
            'Publish signed APKs to GitHub Release',
            'Sync Android release metadata to WordPress',
        ]:
            start = WORKFLOW.index(f'- name: {name}')
            block = WORKFLOW[start:start + 260]
            self.assertIn("if: ${{ env.BLUEVPN_BUILD_MODE == 'full' }}", block, name)


if __name__ == '__main__':
    unittest.main()
