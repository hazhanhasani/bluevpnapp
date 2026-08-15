import json, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
ENGINE=(ROOT/'android-source/BlueVpnWarpEngine.kt').read_text()
ACCOUNT=(ROOT/'android-source/BlueVpnAccountManager.kt').read_text()

class WarpAdaptive4610Tests(unittest.TestCase):
    def test_version(self):
        app=json.loads((ROOT/'branding/app.json').read_text()); rel=json.loads((ROOT/'release.json').read_text())
        self.assertEqual((app['version_name'],app['version_code']),('4.6.10',40610)); self.assertEqual(rel['version'],'4.6.10')
    def test_authoritative_false_is_not_or_merged(self):
        self.assertIn('enabled = storage.getBoolean("enabled", warpEnabled),',ACCOUNT)
        self.assertNotIn('enabled = storage.getBoolean("enabled", warpEnabled) || warpEnabled',ACCOUNT)
    def test_cancellation_generation_and_job(self):
        self.assertIn('generation.incrementAndGet()',ENGINE); self.assertIn('connectJob?.cancelAndJoin()',ENGINE); self.assertIn('ensureGeneration(gen',ENGINE)
    def test_process_cleanup_waits_then_forces(self):
        self.assertIn('p.waitFor(350, TimeUnit.MILLISECONDS)',ENGINE); self.assertIn('p.destroyForcibly()',ENGINE)
    def test_dynamic_port_retries(self):
        self.assertIn('private const val PORT_MAX = 1849',ENGINE); self.assertIn('repeat(3)',ENGINE); self.assertIn('ServerSocket().use',ENGINE)
