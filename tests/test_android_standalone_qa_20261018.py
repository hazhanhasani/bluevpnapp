"""Android regressions after WordPress-free cPanel control-plane cutover."""
from pathlib import Path
import json
import unittest

ROOT = Path(__file__).resolve().parents[1]

def source(path):
    return (ROOT / path).read_text(encoding="utf-8")

class StandaloneAndroidQualityTests(unittest.TestCase):
    def test_login_exposes_only_enabled_auth_modes(self):
        app = source("android-source/BlueVpnSubscriptionsActivity.kt")
        self.assertIn('private var authMode="email"', app)
        self.assertIn('refreshAuthCapabilities()', app)
        self.assertIn('optBoolean("phone_otp_enabled",false)', app)
        self.assertIn('if(smsAuthAvailable){', app)
        self.assertIn('if(!account.phoneVerified&&smsAuthAvailable)', app)
        self.assertIn('if(!smsAuthAvailable){status.text=', app)

    def test_empty_release_does_not_masquerade_as_latest(self):
        updater = source("android-source/BlueVpnUpdateManager.kt")
        self.assertIn('val hasPublishedApk =', updater)
        self.assertIn('اطلاعات نسخه قابل دانلود هنوز در سرور منتشر نشده است', updater)
        self.assertIn('if (!updateAvailable && releaseMetadataReady)', updater)
        self.assertIn('return newer && apkUrl.startsWith("https://")', updater)

    def test_apk_validation_keeps_checksum_and_signature(self):
        updater = source("android-source/BlueVpnUpdateManager.kt")
        self.assertIn('sha256 = config.optString("apk_sha256")', updater)
        self.assertIn('val updateSigners = signingCertificateDigests(archiveInfo)', updater)
        self.assertIn('currentSigners.intersect(updateSigners).isEmpty()', updater)

    def test_php_capabilities_are_explicit_and_safe(self):
        api = source("bluevpn-standalone/src/Api.php")
        self.assertIn("'free_access'=>[", api)
        self.assertIn("'enabled'=>false,'guest_allowed'=>false", api)
        self.assertIn("'phone_otp_enabled'=>false", api)
        self.assertIn("'release_metadata_ready'=>(bool)$ready", api)
        self.assertIn("'apk_sha256'=>$ready?$hash:''", api)

    def test_apk_release_build_has_no_wordpress_barrier(self):
        workflow = source(".github/workflows/build-apk.yml")
        for dead_stage in (
            "Synchronize WordPress Manager with BlueVPN version",
            "Wait for independent BlueVPN Manager release",
            "Wait for WordPress control-plane auto-update",
            "Sync Android release metadata to WordPress",
        ):
            self.assertNotIn("- name: " + dead_stage, workflow)
        self.assertIn("Verify published Android release independently of WordPress", workflow)
        self.assertIn("apksigner", workflow)
        self.assertIn("ANDROID_KEYSTORE_BASE64", workflow)

    def test_android_qa_runs_on_pull_request(self):
        workflow = source(".github/workflows/android-quality.yml")
        self.assertIn("pull_request:", workflow)
        self.assertIn("ReactiveCircus/android-emulator-runner@", workflow)
        self.assertIn("Validate light and dark Locations screenshots", workflow)

    def test_control_plane_metadata_is_native(self):
        app = json.loads(source("branding/app.json"))
        self.assertEqual(app["control_plane"], "standalone_mysql_native")
        self.assertEqual(app["application_id"], "ir.blluepanel.bluevpn")

if __name__ == "__main__":
    unittest.main()
