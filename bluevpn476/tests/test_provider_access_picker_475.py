import json
import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]

class ProviderAccessPicker475Tests(unittest.TestCase):
    def setUp(self):
        self.providers = (ROOT/'bluevpn-manager/includes/class-bluevpn-providers.php').read_text()
        self.control = (ROOT/'bluevpn-manager/includes/class-bluevpn-control-center.php').read_text()
        self.db = (ROOT/'bluevpn-manager/includes/class-bluevpn-db.php').read_text()
        self.js = (ROOT/'bluevpn-manager/assets/admin-unified.js').read_text()

    def test_pasarguard_catalog_and_explicit_selection(self):
        self.assertIn("public static function access_catalog", self.providers)
        self.assertIn("'/api/groups'", self.providers)
        self.assertIn("array_intersect($fallback,array_values($ids))", self.providers)
        self.assertIn("group_ids_selected[]", self.control)

    def test_marzban_catalog_and_plan_filter(self):
        self.assertIn("'/api/inbounds'", self.providers)
        self.assertIn("marzban_inbounds_json", self.db)
        self.assertIn("marzban_inbounds_selected[]", self.control)
        self.assertIn("$selected=self::normalize_marzban_selection($selected)", self.providers)
        self.assertIn("array_filter($tags", self.providers)

    def test_live_picker_is_authenticated_ajax(self):
        self.assertIn("wp_ajax_bluevpn_cc_provider_access_catalog", self.control)
        self.assertIn("check_ajax_referer('bluevpn_provider_access_catalog','nonce')", self.control)
        self.assertIn("bluevpn_cc_provider_access_catalog", self.js)
        self.assertIn("credentials:'same-origin'", self.js)

    def test_empty_selection_keeps_auto_all_mode(self):
        self.assertIn("pasarguard_access_picker_present", self.control)
        self.assertIn("حالت خودکار: همه موارد فعال", self.control)

if __name__ == '__main__':
    unittest.main()
