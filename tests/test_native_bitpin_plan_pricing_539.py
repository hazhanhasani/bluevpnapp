import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]


class NativeBitpinPlanPricing539Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.pricing = (ROOT / "bluevpn-manager/includes/class-bluevpn-dollar-pricing.php").read_text()
        cls.plugin = (ROOT / "bluevpn-manager/bluevpn-manager.php").read_text()
        cls.db = (ROOT / "bluevpn-manager/includes/class-bluevpn-db.php").read_text()
        cls.api = (ROOT / "bluevpn-manager/includes/class-bluevpn-api.php").read_text()
        cls.admin = (ROOT / "bluevpn-manager/includes/class-bluevpn-admin.php").read_text()
        cls.control_center = (ROOT / "bluevpn-manager/includes/class-bluevpn-control-center.php").read_text()
        cls.payments = (ROOT / "bluevpn-manager/includes/class-bluevpn-payments.php").read_text()

    def test_native_manager_owns_pricing_without_woocommerce(self):
        self.assertIn("BlueVPN_Dollar_Pricing::init()", self.plugin)
        self.assertNotIn("WooCommerce", self.pricing)
        self.assertIn("BlueVPN_DB::table('plans')", self.pricing)

    def test_schema_persists_usd_source_and_last_calculated_price(self):
        for column in ("usd_price", "usd_managed", "usd_last_price_toman", "usd_updated_at"):
            self.assertIn(column, self.db)
        self.assertIn("BLUEVPN_MANAGER_SCHEMA_VERSION', '1.34.0", self.plugin)

    def test_bitpin_endpoint_is_fixed_and_ssrf_safe(self):
        self.assertIn("https://api.bitpin.market/api/v1/mkt/tickers/", self.pricing)
        self.assertIn("https://api.bitpin.market/v1/mkt/markets/", self.pricing)
        self.assertIn("wp_safe_remote_get($endpoint", self.pricing)
        self.assertIn("'redirection'=>0", self.pricing)
        self.assertNotIn("endpoint']", self.pricing)

    def test_refresh_is_locked_batched_and_last_good_safe(self):
        self.assertIn("add_option(self::LOCK", self.pricing)
        self.assertIn("LIMIT 100", self.pricing)
        self.assertIn("finally { delete_option(self::LOCK); }", self.pricing)
        self.assertIn("قیمت‌ها بدون تغییر ماندند", self.pricing)
        self.assertIn("bluevpn_dollar_last_rate", self.pricing)

    def test_existing_plan_api_keeps_publishing_toman_price(self):
        self.assertIn("price_toman", self.api)
        self.assertIn("'usd_managed'=>1", self.pricing)
        self.assertIn("'price_toman'=>$price", self.pricing)

    def test_panel_uses_usd_as_the_only_editable_plan_price(self):
        self.assertIn("self::input('usd_price','قیمت پلن (USD)'", self.control_center)
        self.assertIn('name="usd_price"', self.admin)
        self.assertNotIn('name="price_toman"', self.control_center)
        self.assertNotIn('name="price_toman"', self.admin)
        self.assertIn("BlueVPN_Dollar_Pricing::quote_toman($usd)", self.control_center)
        self.assertIn("BlueVPN_Dollar_Pricing::quote_toman($usd)", self.admin)

    def test_legacy_toman_only_plans_are_not_listed_or_purchasable(self):
        rule = "usd_managed=1 AND usd_price>0"
        self.assertIn(rule, self.api)
        self.assertIn(rule, self.payments)

    def test_upgrade_clears_stale_404_and_retries_on_a_dedicated_hook(self):
        self.assertIn("migrate_legacy_endpoint_error()", self.pricing)
        self.assertIn("str_contains($error, 'HTTP 404')", self.pricing)
        self.assertIn("delete_option('bluevpn_dollar_last_error')", self.pricing)
        self.assertIn("bluevpn_dollar_pricing_upgrade_refresh", self.pricing)
        self.assertIn("wp_schedule_single_event(time() + 15", self.pricing)


if __name__ == "__main__":
    unittest.main()
