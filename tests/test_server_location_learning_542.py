import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]


class ServerLocationLearning542Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.api = (ROOT / "bluevpn-manager/includes/class-bluevpn-api.php").read_text()
        cls.location = (ROOT / "android-source/BlueVpnLocationUtil.kt").read_text()

    def test_first_successful_exit_trace_can_seed_server_location(self):
        self.assertIn("'source'=>'client_trace'", self.api)
        self.assertIn("'confidence'=>85", self.api)
        self.assertIn("SERVER_LOCATION_SAVE_FAILED", self.api)
        self.assertNotIn("SERVER_LOCATION_NOT_FOUND", self.api)

    def test_verified_country_is_shared_and_reused(self):
        self.assertIn("reportServerLocation(", self.location)
        self.assertIn("resolveServerLocations(", self.location)
        self.assertIn("markVerifiedCountryKey(app, key, code)", self.location)


if __name__ == "__main__":
    unittest.main()
