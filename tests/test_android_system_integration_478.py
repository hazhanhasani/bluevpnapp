import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]

class AndroidSystemIntegration478Tests(unittest.TestCase):
    def text(self, path):
        return (ROOT / path).read_text(encoding='utf-8')

    def test_quick_settings_tile_is_bluevpn_aware(self):
        src = self.text('android-source/BlueVpnQuickTileService.kt')
        self.assertIn('class BlueVpnQuickTileService : TileService()', src)
        self.assertIn('sendBroadcast(', src)
        self.assertIn('BlueVpnSystemController.ACTION_START', src)
        self.assertIn('BlueVpnSystemController.ACTION_STOP', src)
        self.assertIn('Tile.STATE_ACTIVE', src)
        self.assertIn('CoreServiceManager.isRunning()', src)
        self.assertNotIn('CoreServiceManager.getRunningServerName()', src)
        self.assertIn('tile.label = getString(R.string.app_name)', src)

    def test_system_controller_cleans_free_and_premium_runtime(self):
        src = self.text('android-source/BlueVpnSystemController.kt')
        self.assertIn('LauncherManager.stopService(app)', src)
        self.assertIn('BlueVpnWarpKeepAliveService.stop(app)', src)
        self.assertIn('BlueVpnWarpEngine.stop()', src)
        self.assertIn('BlueVpnAccountManager.stopFreeSession', src)
        self.assertIn('BlueVpnWarpEngine.prepareAdaptive(app)', src)
        self.assertIn('LauncherManager.startService(app, prepared.guid)', src)
        self.assertIn('ACTION_START', src)

    def test_notification_actions_are_redirected_to_bluevpn_controller(self):
        prep = self.text('scripts/prepare_android.py')
        self.assertIn('BlueVpnSystemActionReceiver::class.java', prep)
        self.assertIn('BlueVpnSystemController.ACTION_STOP', prep)
        self.assertIn('BlueVpnSystemController.ACTION_RESTART', prep)
        self.assertIn('Intent(service, BlueVpnHomeActivity::class.java)', prep)
        self.assertIn('BlueVpnQuickTileService', prep)

    def test_no_sms_or_extra_sensitive_permission_is_added(self):
        prep = self.text('scripts/prepare_android.py')
        self.assertNotIn('android.permission.READ_SMS', prep)
        self.assertNotIn('android.permission.RECEIVE_SMS', prep)

if __name__ == '__main__': unittest.main()
