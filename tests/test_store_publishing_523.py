import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def text(rel: str) -> str:
    return (ROOT / rel).read_text(encoding="utf-8")


class StorePublishing523Tests(unittest.TestCase):
    def test_version_and_store_metadata(self):
        release = json.loads(text("release.json"))
        app = json.loads(text("branding/app.json"))
        self.assertEqual((release["version"], release["version_code"]), ("5.2.4", 50204))
        self.assertEqual((app["version_name"], app["version_code"]), ("5.2.4", 50204))
        self.assertEqual(app["android_store_artifact"], "aab")
        self.assertGreaterEqual(app["android_store_target_api_min"], 36)
        self.assertFalse(app["android_store_account_creation"])
        self.assertFalse(app["android_store_self_update"])
        self.assertFalse(app["android_store_third_party_ads"])
        self.assertEqual(app["windows_store_path"], "microsoft_store_exe_msi")

    def test_play_flavor_is_consumption_only(self):
        policy = text("android-source/BlueVpnStorePolicy.kt")
        subs = text("android-source/BlueVpnSubscriptionsActivity.kt")
        account = text("android-source/BlueVpnAccountManager.kt")
        update = text("android-source/BlueVpnUpdateManager.kt")
        self.assertIn('BuildConfig.DISTRIBUTION.equals("Play Store", ignoreCase = true)', policy)
        self.assertIn("allowAccountCreation", policy)
        self.assertIn("allowExternalCheckout", policy)
        self.assertIn("allowPackageInstallerUpdates", policy)
        self.assertIn("playStoreConsumptionCard", subs)
        self.assertIn("BlueVpnStorePolicy.allowAccountCreation()", subs)
        self.assertIn("BlueVpnStorePolicy.allowExternalCheckout()", subs)
        self.assertIn('put("login_only", BlueVpnStorePolicy.isGooglePlayBuild())', account)
        self.assertIn("showGooglePlayUpdateDialog", update)
        self.assertIn("BlueVpnStorePolicy.openGooglePlay", update)

    def test_play_otp_is_login_only_end_to_end(self):
        api = text("bluevpn-manager/includes/class-bluevpn-api.php")
        sms = text("bluevpn-manager/includes/class-bluevpn-sms-otp.php")
        self.assertIn("rest_sanitize_boolean($b['login_only']??false)", api)
        self.assertIn("bool $loginOnly = false", sms)
        self.assertIn("ACCOUNT_NOT_FOUND", sms)
        self.assertIn("if($loginOnly)", sms.replace(" ", ""))

    def test_play_manifest_and_tapsell_are_flavor_scoped(self):
        prepare = text("scripts/prepare_android.py")
        stub = text("android-source/BlueVpnTapsellManagerPlay.kt")
        self.assertIn('tools:node="remove"', prepare)
        self.assertIn("android.permission.REQUEST_INSTALL_PACKAGES", prepare)
        self.assertIn("com.google.android.gms.permission.AD_ID", prepare)
        self.assertIn('add("fdroidImplementation", "ir.tapsell:tapsell:', prepare)
        self.assertNotIn('fdroidImplementation(', prepare)
        self.assertNotIn('implementation("ir.tapsell:tapsell:', prepare)
        self.assertIn("BlueVpnTapsellManagerPlay.kt", prepare)
        self.assertNotIn("import ir.tapsell", stub)
        self.assertIn("onUnavailable?.invoke()", stub)

    def test_android_workflow_builds_store_aab_and_16k_gates(self):
        workflow = text(".github/workflows/build-apk.yml")
        self.assertIn(":app:bundlePlaystoreRelease", workflow)
        self.assertIn("BlueVPN-${VERSION}-GooglePlay.aab", workflow)
        self.assertIn("jarsigner -verify", workflow)
        self.assertIn("zipalign\" -c -P 16", workflow)
        self.assertIn("Native ELF LOAD alignment is below 16 KB", workflow)
        self.assertIn("targetSdk >= 36", workflow)
        self.assertIn("Validate merged Google Play manifest", workflow)

    def test_public_privacy_and_terms_pages_exist(self):
        api = text("bluevpn-manager/includes/class-bluevpn-api.php")
        settings = text("android-source/BlueVpnSettingsActivity.kt")
        functions = text("bluevpn-site/functions.php")
        self.assertIn("'privacy_url'=>home_url('/privacy/')", api)
        self.assertIn("'terms_url'=>home_url('/terms/')", api)
        self.assertIn('openRemoteLink("privacy_url")', settings)
        self.assertIn('openRemoteLink("terms_url")', settings)
        self.assertIn("page-privacy.php", functions)
        self.assertIn("page-terms.php", functions)
        self.assertTrue((ROOT / "bluevpn-site/page-privacy.php").is_file())
        self.assertTrue((ROOT / "bluevpn-site/page-terms.php").is_file())

    def test_windows_store_workflow_signs_every_pe_and_installer(self):
        workflow = text(".github/workflows/build-windows.yml")
        for token in (
            "store_release",
            "WINDOWS_SIGN_PFX_BASE64",
            "WINDOWS_SIGN_PFX_PASSWORD",
            "signtool sign",
            "Get-AuthenticodeSignature",
            "Microsoft Store payload contains non-valid Authenticode PE",
            "BlueVPN-MicrosoftStore-Setup-$version-$env:RID.exe",
        ):
            self.assertIn(token, workflow)
        self.assertIn("https://timestamp.digicert.com", workflow)

    def test_store_submission_docs_are_packaged(self):
        required = [
            "store/google-play/PLAY-CONSOLE-CHECKLIST.md",
            "store/google-play/VPN-SERVICE-DECLARATION.md",
            "store/google-play/DATA-SAFETY.md",
            "store/google-play/LISTING-FA.md",
            "store/microsoft-store/PARTNER-CENTER-CHECKLIST.md",
            "store/microsoft-store/CERTIFICATION-NOTES.md",
            "store/microsoft-store/SIGNING-SECRETS.md",
            "store/microsoft-store/LISTING-FA.md",
        ]
        for rel in required:
            self.assertTrue((ROOT / rel).is_file(), rel)


if __name__ == "__main__":
    unittest.main()
