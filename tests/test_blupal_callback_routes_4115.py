import pathlib, unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]
class BluPalCallbackRoutes4115(unittest.TestCase):
    def text(self,p): return (ROOT/p).read_text()
    def test_friendly_webhook_and_callback_are_real_rewrites(self):
        s=self.text('bluevpn-manager/includes/class-bluevpn-compat.php')
        self.assertIn("^api/v1/webhooks/blupal/?$",s)
        self.assertIn("^bluevpn/payment/callback/?$",s)
        self.assertIn("bluevpn_blupal_callback",s)
        self.assertIn("BlueVPN_Payments::render_callback_page()",s)
    def test_payment_class_exports_exact_public_urls(self):
        s=self.text('bluevpn-manager/includes/class-bluevpn-payments.php')
        self.assertIn("home_url('/api/v1/webhooks/blupal')",s)
        self.assertIn("home_url('/bluevpn/payment/callback/')",s)
    def test_callback_never_trusts_browser_status(self):
        s=self.text('bluevpn-manager/includes/class-bluevpn-payments.php')
        block=s[s.index('private static function callback_refresh_order'):s.index('public static function render_callback_page')]
        self.assertIn('return self::refresh_remote($order)',block)
        self.assertNotIn("$_GET['status']",block)
    def test_callback_can_consume_common_invoice_id_names(self):
        s=self.text('bluevpn-manager/includes/class-bluevpn-payments.php')
        block=s[s.index('private static function callback_invoice_id'):s.index('private static function callback_refresh_order')]
        for k in ['invoice_id','payment_id','invoice','id']: self.assertIn(k,block)
    def test_callback_page_has_success_pending_failure_states(self):
        s=self.text('bluevpn-manager/includes/class-bluevpn-payments.php')
        block=s[s.index('public static function render_callback_page'):s.index('private static function order_row')]
        self.assertIn('پرداخت موفق و سرویس فعال شد',block)
        self.assertIn('پرداخت تأیید شد',block)
        self.assertIn('پرداخت تکمیل نشد',block)
        self.assertIn('در حال تأیید پرداخت',block)
        self.assertIn('noindex, nofollow',block)
    def test_panel_displays_exact_blupal_addresses(self):
        s=self.text('bluevpn-manager/includes/class-bluevpn-control-center.php')
        self.assertIn('BlueVPN_Payments::webhook_url()',s)
        self.assertIn('BlueVPN_Payments::callback_url()',s)
        self.assertIn('Callback page',s)
if __name__=='__main__': unittest.main()
