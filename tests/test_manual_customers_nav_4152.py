import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]

def text(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")

class ManualCustomersNav4152Tests(unittest.TestCase):
    def test_manual_customers_is_in_services_group(self):
        ui = text("bluevpn-manager/includes/class-bluevpn-unified-ui.php")
        services = ui.split("'کاربران و فروش' => [", 1)[1].split("'شبکه و سرورها' => [", 1)[0]
        self.assertIn("bluevpn-manual-customers", services)
        self.assertIn("مشتریان دستی", services)
        self.assertIn("bluevpn-manual", services)
        self.assertLess(
            services.index("bluevpn-manual-customers"),
            services.index("bluevpn-manual',"),
        )

    def test_manual_activation_has_direct_crm_button(self):
        cc = text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        block = cc.split("private static function tab_manual(): void", 1)[1].split(
            "private static function tab_customers(): void", 1
        )[0]
        self.assertIn("بازکردن مشتریان دستی", block)
        self.assertIn("page=bluevpn-manual-customers", block)

if __name__ == "__main__":
    unittest.main()
