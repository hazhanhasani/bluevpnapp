from pathlib import Path
import json, unittest
ROOT=Path(__file__).resolve().parents[1]

class AdminInformationArchitecture60002Tests(unittest.TestCase):
    def test_release(self):
        d=json.loads((ROOT/"release.json").read_text(encoding="utf-8"))
        self.assertEqual((d["version"],d["version_code"]),("6.0.2",60002))

    def test_admin_navigation_is_domain_grouped_and_searchable(self):
        ui=(ROOT/"bluevpn-manager/includes/class-bluevpn-unified-ui.php").read_text(encoding="utf-8")
        for token in ["کاربران و فروش","شبکه و زیرساخت","اپ و انتشار","تبلیغات و ارتباط","سلامت و عملیات","هوش و اتوماسیون","تنظیمات","bluevpn-production","bluevpnNavSearch","current_group"]:
            self.assertIn(token,ui)
        js=(ROOT/"bluevpn-manager/assets/admin-unified.js").read_text(encoding="utf-8")
        self.assertIn("bluevpnNavSearch",js)
        self.assertIn("is-filter-hidden",js)

    def test_general_settings_are_separated_from_operational_settings(self):
        admin=(ROOT/"bluevpn-manager/includes/class-bluevpn-admin.php").read_text(encoding="utf-8")
        self.assertIn("تنظیمات عمومی BlueVPN",admin)
        self.assertIn("تنظیمات تخصصی",admin)
        self.assertIn("سلامت و Backup",admin)
        self.assertIn("اتصال رایگان / WARP",admin)

if __name__=="__main__": unittest.main()
