import pathlib
import re
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]

class SupportEditTextCompile41100(unittest.TestCase):
    def text(self, p):
        return (ROOT / p).read_text()

    def test_message_input_is_edittext(self):
        s = self.text("android-source/BlueVpnSupportActivity.kt")
        self.assertIn("private lateinit var messageInput: EditText", s)

    def test_edittext_never_receives_string_via_text_property_assignment(self):
        s = self.text("android-source/BlueVpnSupportActivity.kt")
        direct = re.findall(r"\bmessageInput\.text\s*=", s)
        self.assertEqual(direct, [])

    def test_exact_failed_assignments_use_set_text(self):
        s = self.text("android-source/BlueVpnSupportActivity.kt")
        self.assertGreaterEqual(s.count('messageInput.setText("")'), 2)
        self.assertIn("messageInput.setText(value)", s)

    def test_reading_message_input_still_uses_editable_to_string(self):
        s = self.text("android-source/BlueVpnSupportActivity.kt")
        self.assertIn("messageInput.text?.toString()?.trim().orEmpty()", s)

if __name__ == "__main__":
    unittest.main()
