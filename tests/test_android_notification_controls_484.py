import pathlib, unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]

class AndroidNotificationControls484(unittest.TestCase):
    def text(self,p): return (ROOT/p).read_text()

    def test_android_13_notification_permission_is_declared_and_requested(self):
        prep=self.text("scripts/prepare_android.py")
        home=self.text("android-source/BlueVpnHomeActivity.kt")
        self.assertIn("android.permission.POST_NOTIFICATIONS", prep)
        self.assertIn("Manifest.permission.POST_NOTIFICATIONS", home)
        self.assertIn("notificationPermissionLauncher", home)
        self.assertIn("ensureNotificationPermission()", home)

    def test_foreground_notification_has_real_controls(self):
        s=self.text("android-source/BlueVpnWarpKeepAliveService.kt")
        self.assertIn("setContentTitle(\"BlueVPN • اتصال فعال\")", s)
        self.assertIn("BlueVpnSystemController.ACTION_STOP", s)
        self.assertIn("BlueVpnSystemController.ACTION_RESTART", s)
        self.assertIn('addAction(0, "توقف"', s)
        self.assertIn('addAction(0, "راه‌اندازی مجدد"', s)
        self.assertIn("BlueVpnHomeActivity::class.java", s)

    def test_notification_updates_live_traffic_and_time(self):
        s=self.text("android-source/BlueVpnWarpKeepAliveService.kt")
        self.assertIn("TrafficStats.getUidRxBytes", s)
        self.assertIn("TrafficStats.getUidTxBytes", s)
        self.assertIn("UPDATE_MS = 3_000L", s)
        self.assertIn("setUsesChronometer(true)", s)
        self.assertIn("formatRate", s)

    def test_notification_is_sticky_and_not_activity_owned(self):
        s=self.text("android-source/BlueVpnWarpKeepAliveService.kt")
        self.assertIn("return START_STICKY", s)
        self.assertIn("Removing the UI from Recents must not disconnect the VPN", s)
        self.assertIn("startForeground(NOTIFICATION_ID", s)

if __name__=="__main__": unittest.main()
