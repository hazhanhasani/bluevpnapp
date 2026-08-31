from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]

class AndroidUpdateRelay60302Tests(unittest.TestCase):
    def text(self,path):
        return (ROOT/path).read_text(encoding="utf-8")

    def test_mobile_config_rewrites_github_assets_to_first_party_relay(self):
        api=self.text("bluevpn-manager/includes/class-bluevpn-api.php")
        self.assertIn("/app/download/(?P<version>",api)
        self.assertIn("app_apk_relay_url",api)
        self.assertIn("first_party_streaming_relay",api)
        self.assertIn("$publicApkAssets[(string)$key]=self::app_apk_relay_url",api)

    def test_relay_streams_binary_and_follows_github_redirects(self):
        api=self.text("bluevpn-manager/includes/class-bluevpn-api.php")
        block=api[api.index("public static function app_apk_download"):api.index("public static function mobile_config")]
        for token in [
            "application/vnd.android.package-archive",
            "CURLOPT_FOLLOWLOCATION",
            "CURLOPT_WRITEFUNCTION",
            "X-BlueVPN-APK-Relay",
            "X-Accel-Buffering",
            "Content-Length",
        ]:
            self.assertIn(token,block)

    def test_release_lookup_is_exact_and_server_authoritative(self):
        manager=self.text("bluevpn-manager/includes/class-bluevpn-app-release-manager.php")
        self.assertIn("public static function release_by_version",manager)
        self.assertIn("WHERE version=%s",manager)

    def test_android_prefers_default_vpn_route_before_underlying_network(self):
        updater=self.text("android-source/BlueVpnUpdateManager.kt")
        block=updater[updater.index("private fun openDownloadConnection"):updater.index("private fun shouldFallbackToDefaultNetwork")]
        self.assertLess(block.index("target.openConnection()"),block.index("physicalNetwork.openConnection(target)"))
        self.assertIn("if (code in 200..299) return defaultConnection",block)
        self.assertIn("readTimeout = 60_000",updater)
        self.assertIn("اگر VPN فعال باشد ابتدا همان تونل استفاده می‌شود",updater)

if __name__=="__main__":
    unittest.main()
