import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]


class AndroidBetaBuildDetection546Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.update = (ROOT / "android-source/BlueVpnUpdateManager.kt").read_text()

    def test_equal_semantic_version_uses_newer_version_code(self):
        self.assertIn("private fun remoteBuildIsNewer", self.update)
        self.assertIn("comparison == 0 && latestCode > BuildConfig.VERSION_CODE", self.update)

    def test_manual_and_automatic_checks_share_the_same_comparison(self):
        self.assertGreaterEqual(
            self.update.count("remoteBuildIsNewer(latestVersion, latestCode)"),
            2,
        )
        self.assertIn("val newer = remoteBuildIsNewer", self.update)
        self.assertIn("val updateAvailable =", self.update)

    def test_authenticated_beta_channel_contract_remains_enabled(self):
        self.assertIn("BlueVpnAccountManager.mobileConfig(", self.update)
        self.assertIn('KEY_BETA_TESTER = "remote_beta_tester"', self.update)
        self.assertIn('.putBoolean(KEY_BETA_TESTER, betaTester)', self.update)


if __name__ == "__main__":
    unittest.main()
