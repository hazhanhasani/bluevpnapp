import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]

class AdminTransitionRejection554Tests(unittest.TestCase):
    def test_client_filters_only_known_stackless_browser_cancellation(self):
        js = (ROOT / "bluevpn-manager/assets/admin-unified.js").read_text(encoding="utf-8")
        self.assertIn("bvIsBenignBrowserCancellation", js)
        self.assertIn("transition was aborted because of invalid state", js)
        self.assertIn("if(stack)return false", js)
        self.assertIn("e.preventDefault()", js)
        self.assertIn("bvAdminReport('unhandledrejection'", js)

    def test_server_filters_cached_clients_but_keeps_real_rejections(self):
        php = (ROOT / "bluevpn-manager/includes/class-bluevpn-error-monitor.php").read_text(encoding="utf-8")
        self.assertIn("$kind === 'unhandledrejection' && $stack === ''", php)
        self.assertIn("transition was aborted because of invalid state", php)
        self.assertIn("JS_UNHANDLED_REJECTION", php)

if __name__ == "__main__":
    unittest.main()
