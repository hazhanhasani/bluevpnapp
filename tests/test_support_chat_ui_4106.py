import pathlib
import unittest

ROOT=pathlib.Path(__file__).resolve().parents[1]

class SupportChatUi4106(unittest.TestCase):
    def text(self,p):
        return (ROOT/p).read_text()

    def test_support_is_real_chat_layout_not_raw_form(self):
        s=self.text("android-source/BlueVpnSupportActivity.kt")
        self.assertIn("createHeader()",s)
        self.assertIn("createConversationStrip()",s)
        self.assertIn("createChatSurface()",s)
        self.assertIn("createComposer()",s)
        self.assertIn("createEmptyState()",s)
        self.assertNotIn("Spinner",s)
        self.assertNotIn("simple_spinner_dropdown_item",s)

    def test_compact_messenger_composer(self):
        s=self.text("android-source/BlueVpnSupportActivity.kt")
        self.assertIn("private lateinit var messageInput: EditText",s)
        self.assertIn('text = "+"',s)
        self.assertIn('text = "➤"',s)
        self.assertIn("dp(46), dp(46)",s)
        self.assertIn("SOFT_INPUT_ADJUST_RESIZE",s)

    def test_department_selection_is_chat_native(self):
        s=self.text("android-source/BlueVpnSupportActivity.kt")
        self.assertIn("showDepartmentChooser",s)
        self.assertIn("rebuildDepartmentList()",s)
        self.assertIn("showTopicChooser",s)
        self.assertIn("انتخاب بخش",s)
        self.assertIn("انتخاب موضوع",s)
        self.assertIn("موضوع دقیق درخواست را انتخاب کنید",s)

    def test_message_bubbles_have_identity_metadata_and_seen_state(self):
        s=self.text("android-source/BlueVpnSupportActivity.kt")
        bubble=s[s.index("private fun addMessageBubble"):s.index("private fun uploadAttachment")]
        self.assertIn('"BlueVPN"',bubble)
        self.assertIn("formatMessageTime",bubble)
        self.assertIn('if (seen) "  ✓✓" else "  ✓"',bubble)
        self.assertIn("attachments",bubble)
        self.assertIn("humanSize",bubble)

    def test_chat_autoscroll_and_incremental_polling_remain_bounded(self):
        s=self.text("android-source/BlueVpnSupportActivity.kt")
        self.assertIn("handler.postDelayed(this, 4500L)",s)
        self.assertIn("if (cid <= 0 || loadingMessages) return",s)
        self.assertIn("scrollToBottom()",s)
        self.assertIn("fullScroll(View.FOCUS_DOWN)",s)

    def test_signed_out_state_is_designed_not_broken_composer(self):
        s=self.text("android-source/BlueVpnSupportActivity.kt")
        block=s[s.index("private fun renderSignedOut"):s.index("private fun loadDepartments")]
        self.assertIn("composer.visibility = View.GONE",block)
        self.assertIn("نیاز به ورود",block)

    def test_backend_support_contract_is_unchanged(self):
        s=self.text("android-source/BlueVpnSupportActivity.kt")
        self.assertIn("/api/v1/support/departments",s)
        self.assertIn("/api/v1/support/conversations",s)
        self.assertIn("/attachments",s)
        self.assertIn("BlueVpnSupportNotifications.schedule(this)",s)

if __name__=="__main__":
    unittest.main()
