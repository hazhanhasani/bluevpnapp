import pathlib, unittest

ROOT=pathlib.Path(__file__).resolve().parents[1]

class SupportAdminUi4107(unittest.TestCase):
    def text(self,p): return (ROOT/p).read_text()

    def test_support_admin_no_longer_uses_white_raw_cards(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-support.php")
        page=s[s.index("public static function admin_page(): void"):
               s.index("public static function admin_reply(): void")]
        self.assertNotIn("background:#fff", page)
        self.assertNotIn("bvs-card{background:#fff", page)
        self.assertNotIn('class="wrap"><h1>پشتیبانی آنلاین BlueVPN', page)

    def test_support_admin_uses_dark_bluevpn_design_tokens(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-support.php")
        for token in [
            "--bvs-bg:#08111d",
            "--bvs-panel:#0d1726",
            "--bvs-accent:#24d6c3",
            "--bvs-blue:#4b83ff",
        ]:
            self.assertIn(token,s)
        self.assertIn("linear-gradient",s)

    def test_support_admin_has_real_inbox_structure(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-support.php")
        for marker in [
            "bvs-topstats",
            "bvs-layout",
            "bvs-list",
            "bvs-chat-body",
            "bvs-compose",
            "bvs-right",
        ]:
            self.assertIn(marker,s)

    def test_support_admin_is_responsive_for_phone_and_tablet(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-support.php")
        self.assertIn("@media(max-width:1100px)",s)
        self.assertIn("@media(max-width:760px)",s)
        self.assertIn("grid-template-columns:repeat(2,minmax(0,1fr))",s)
        self.assertIn(".bvs-layout{display:block}",s)

    def test_chat_messages_have_distinct_customer_operator_bubbles(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-support.php")
        self.assertIn(".bvs-msg.customer",s)
        self.assertIn(".bvs-msg.operator",s)
        self.assertIn("bvs-msg-row",s)
        self.assertIn("پشتیبانی",s)

    def test_operator_controls_are_grouped_not_raw_form_dump(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-support.php")
        self.assertIn("مدیریت گفتگو",s)
        self.assertIn("ارجاع و وضعیت",s)
        self.assertIn("یادداشت داخلی",s)
        self.assertIn("پاسخ آماده",s)
        self.assertIn("بvs-section".replace("ب","b"),s)

    def test_blueai_suggestion_remains_in_chat_context(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-support.php")
        self.assertIn("پیشنهاد BlueAI",s)
        self.assertIn("استفاده از BlueAI",s)

if __name__=="__main__":
    unittest.main()
