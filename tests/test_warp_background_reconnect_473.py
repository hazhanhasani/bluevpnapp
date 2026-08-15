from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]

class WarpBackgroundReconnect473Tests(unittest.TestCase):
    def test_keepalive_is_sticky_foreground_and_not_task_owned(self):
        src=(ROOT/'android-source/BlueVpnWarpKeepAliveService.kt').read_text()
        self.assertIn('startForeground(', src)
        self.assertIn('START_STICKY', src)
        self.assertIn('onTaskRemoved', src)
        self.assertNotIn('BlueVpnWarpEngine.stop()', src)

    def test_home_starts_keepalive_only_after_successful_warp_prepare(self):
        home=(ROOT/'android-source/BlueVpnHomeActivity.kt').read_text()
        success=home.index('BlueVpnWarpKeepAliveService.start(this@BlueVpnHomeActivity)')
        guid=home.index('val guid = result.getOrNull().orEmpty()')
        blank=home.index('if (guid.isBlank())', guid)
        self.assertGreater(success, blank)
        self.assertIn('BlueVpnWarpKeepAliveService.stop(this)', home)

    def test_lkg_direct_probe_precedes_strategy_scan_backoff(self):
        warp=(ROOT/'android-source/BlueVpnWarpEngine.kt').read_text()
        race=warp.index('if (policy.warpEndpointRacingEnabled && strategy != Strategy.GOOL)')
        backoff=warp.index('if (isBackedOff(prefs, shape.signature, strategy)) continue', race)
        self.assertLess(race, backoff)

    def test_failed_cached_edge_is_invalidated(self):
        warp=(ROOT/'android-source/BlueVpnWarpEngine.kt').read_text()
        block=warp[warp.index('private fun recordEdgeFailure'):]
        self.assertIn('remove("edge:$sig:${strategy.name}")', block)
        self.assertIn('remove("edge_at:$sig:${strategy.name}")', block)
        self.assertIn('remove("lkg_at:$sig")', block)

    def test_prepare_android_declares_keepalive_service_and_permissions(self):
        prep=(ROOT/'scripts/prepare_android.py').read_text()
        self.assertIn('BlueVpnWarpKeepAliveService.kt', prep)
        self.assertIn('android.permission.FOREGROUND_SERVICE', prep)
        self.assertIn('android.permission.FOREGROUND_SERVICE_SPECIAL_USE', prep)
        self.assertIn('android:foregroundServiceType="specialUse"', prep)
        self.assertIn('android:stopWithTask="false"', prep)

if __name__ == '__main__': unittest.main()
