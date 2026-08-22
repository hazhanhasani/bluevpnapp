import json
import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]

def text(rel):
    return (ROOT / rel).read_text(encoding="utf-8")

class BrandRuntimeBoundary50104Tests(unittest.TestCase):
    def test_release_version(self):
        release = json.loads(text("release.json"))
        self.assertEqual(release["version"], "5.1.4")
        self.assertEqual(release["version_code"], 50104)

    def test_quick_tile_never_uses_running_profile_name(self):
        src = text("android-source/BlueVpnQuickTileService.kt")
        self.assertNotIn("getRunningServerName()", src)
        self.assertIn("tile.label = getString(R.string.app_name)", src)

    def test_raw_main_activity_is_not_a_public_entrypoint(self):
        prep = text("scripts/prepare_android.py")
        self.assertIn('android:name=".ui.MainActivity"', prep)
        self.assertIn('android:enabled="false"', prep)
        self.assertIn('android:exported="false"', prep)
        home_block = prep.split('android:name=".ui.BlueVpnHomeActivity"', 1)[1].split('android:name=".ui.MainActivity"', 1)[0]
        self.assertIn("android.service.quicksettings.action.QS_TILE_PREFERENCES", home_block)
        self.assertNotIn("android.app.shortcuts", home_block)
        self.assertIn("BlueVpnHomeActivity", prep)
        self.assertIn('r\'\\s*<receiver\\s+android:name="\\.receiver\\.WidgetProvider".*?</receiver>\'', prep)
        self.assertIn('r\'\\s*<activity\\s+android:name="\\.ui\\.TaskerActivity".*?</activity>\'', prep)
        self.assertIn('r\'\\s*<receiver\\s+android:name="\\.receiver\\.TaskerReceiver".*?</receiver>\'', prep)

    def test_notification_uses_public_profile_name(self):
        prep = text("scripts/prepare_android.py")
        self.assertIn("BlueVpnPublicProfileName.forProfile(service, currentConfig)", prep)
        self.assertIn("Intent(service, BlueVpnHomeActivity::class.java)", prep)
        home = text("android-source/BlueVpnHomeActivity.kt")
        self.assertNotIn('"v2rayNG/Xray نتوانست', home)

    def test_server_location_json_transport_is_hardened(self):
        account = text("android-source/BlueVpnAccountManager.kt")
        manager = text("bluevpn-manager/includes/class-bluevpn-api.php")
        self.assertIn('"/wp-json/bluevpn/v1/server-locations/resolve"', account)
        self.assertIn('"application/json; charset=utf-8"', account)
        self.assertIn("setFixedLengthStreamingMode(payload.size)", account)
        self.assertIn("toByteArray(Charsets.UTF_8)", account)
        self.assertIn("$r->get_body()", manager)

    def test_windows_ui_does_not_expose_runtime_or_raw_config_names(self):
        xaml = text("bluevpn-windows/MainWindow.xaml")
        ui = text("bluevpn-windows/MainWindow.xaml.cs")
        model = text("bluevpn-windows/Models/ProxyEndpoint.cs")
        connection = text("bluevpn-windows/Services/ConnectionOrchestrator.cs")
        self.assertNotIn('Text="v2rayN"', xaml)
        self.assertIn('Text="BlueVPN Core"', xaml)
        self.assertIn('public string DisplayName => "BlueVPN • مسیر امن";', model)
        self.assertNotIn("EndpointText.Text = result.Endpoint.DiagnosticName", ui)
        self.assertIn('ActiveEngine = "BlueVPN Core";', connection)
        self.assertNotIn('ActiveEngine = "v2rayN', connection)

    def test_windows_runtime_is_one_complete_v2rayn_bundle(self):
        runtime = text("bluevpn-windows/Services/RuntimeLocator.cs")
        updater = text("bluevpn-windows/Services/RuntimeUpdateService.cs")
        controller = text("bluevpn-windows/Services/XrayProcessController.cs")
        workflow = text(".github/workflows/build-windows.yml")
        self.assertIn("ResolveV2RayNBundle", runtime)
        for name in ("v2rayN.exe", "xray.exe", "sing-box.exe", "wintun.dll"):
            self.assertIn(name, runtime)
            self.assertIn(name, updater)
        self.assertIn("var bundle = _runtime.ResolveV2RayNBundle();", controller)
        self.assertIn("Required v2rayN application runtime missing", workflow)

if __name__ == "__main__":
    unittest.main()
