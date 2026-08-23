import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def text(rel: str) -> str:
    return (ROOT / rel).read_text(encoding="utf-8")

class Phase524GradleFlavorWebhookHotfixTests(unittest.TestCase):
    def test_version(self):
        release = json.loads(text("release.json"))
        self.assertEqual((release["version"], release["version_code"]), ("5.2.4", 50204))

    def test_fdroid_dependencies_use_agp9_safe_add(self):
        prepare = text("scripts/prepare_android.py")
        for notation in (
            "ir.tapsell:tapsell:",
            "ir.tapsell.mediation.adapter:legacy:",
            "ir.tapsell.mediation.adapter:legacy-ima-extension:",
            "ir.tapsell.mediation.adapter:legacy-taproll:",
        ):
            self.assertIn('add("fdroidImplementation", "' + notation, prepare)
        self.assertNotIn("fdroidImplementation(", prepare)

    def test_release_validator_blocks_typed_accessor_regression(self):
        validator = text("scripts/validate_release.py")
        self.assertIn("AGP9-incompatible typed fdroidImplementation accessor", validator)
        self.assertIn("DependencyHandler.add", validator)

    def test_telegram_webhook_does_not_depend_on_pretty_rest_rewrites(self):
        bot = text("bluevpn-manager/includes/class-bluevpn-telegram-bot.php")
        self.assertIn("/?rest_route=", bot)
        self.assertNotIn("return rest_url('bluevpn-bot/v1/webhook/", bot)

if __name__ == "__main__":
    unittest.main()
