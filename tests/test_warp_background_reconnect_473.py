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

    def test_lkg_uses_native_quick_reconnect_without_parallel_aether(self):
        warp=(ROOT/'android-source/BlueVpnWarpEngine.kt').read_text()
        quick=warp.index('val quick = policy.warpQuickReconnect && cachedStrategy')
        start=warp.index('startWithPortRetries(app, strategy, quick, policy, null, shape)', quick)
        self.assertLess(quick, start)
        self.assertNotIn('Channel<ProbeOutcome>', warp)
        self.assertNotIn('raceCandidates(', warp)

    def test_aether_identity_survives_activity_and_app_updates(self):
        warp=(ROOT/'android-source/BlueVpnWarpEngine.kt').read_text()
        self.assertIn('persistentAetherDataDir(context)', warp)
        self.assertIn('context.noBackupFilesDir', warp)
        self.assertIn('legacy.copyRecursively(target, overwrite = false)', warp)

    def test_prepare_android_declares_keepalive_service_and_permissions(self):
        prep=(ROOT/'scripts/prepare_android.py').read_text()
        self.assertIn('BlueVpnWarpKeepAliveService.kt', prep)
        self.assertIn('android.permission.FOREGROUND_SERVICE', prep)
        self.assertIn('android.permission.FOREGROUND_SERVICE_SPECIAL_USE', prep)
        self.assertIn('android:foregroundServiceType="specialUse"', prep)
        self.assertIn('android:stopWithTask="false"', prep)

if __name__ == '__main__': unittest.main()
