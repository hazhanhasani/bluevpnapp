import json
import pathlib
import re
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]

def text(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")

class UnifiedComponentVersions4153Tests(unittest.TestCase):
    def test_android_manager_theme_release_are_exactly_equal(self):
        release = json.loads(text("release.json"))
        branding = json.loads(text("branding/app.json"))
        manager = text("bluevpn-manager/bluevpn-manager.php")
        style = text("bluevpn-site/style.css")
        functions = text("bluevpn-site/functions.php")

        version = release["version"]
        self.assertEqual(version, "6.1.0")
        self.assertEqual(branding["version_name"], version)
        self.assertEqual(branding["version_code"], 60100)
        self.assertIn("Version: " + version, manager)
        self.assertIn("BLUEVPN_MANAGER_VERSION', '" + version, manager)
        self.assertRegex(style, rf"(?m)^Version:\s*{re.escape(version)}\s*$")
        self.assertIn("BLUEVPN_SITE_VERSION', '" + version, functions)
        self.assertEqual(release["manager_version"], version)
        self.assertEqual(release["site_version"], version)
        self.assertEqual(release["theme_version"], version)

    def test_no_current_split_version_markers(self):
        release = json.loads(text("release.json"))
        version = release["version"]
        manager = text("bluevpn-manager/bluevpn-manager.php")
        style = text("bluevpn-site/style.css")
        functions = text("bluevpn-site/functions.php")
        branding = json.loads(text("branding/app.json"))

        self.assertIn("Version: " + version, manager)
        self.assertRegex(style, rf"(?m)^Version:\s*{re.escape(version)}\s*$")
        self.assertIn("BLUEVPN_SITE_VERSION', '" + version, functions)
        self.assertEqual(branding["version_name"], version)

    def test_deploy_bot_rejects_theme_version_drift(self):
        bot = text("bluevpn-manager/includes/class-bluevpn-telegram-bot.php")
        self.assertIn("DEPLOY_SITE_VERSION_MISSING", bot)
        self.assertIn("style.css پوسته با نسخه Android/Manager", bot)
        self.assertIn("BLUEVPN_SITE_VERSION با نسخه Android/Manager", bot)
        self.assertIn("DEPLOY_SITE_VERSION_NOT_APPLIED", bot)

    def test_theme_release_workflow_requires_global_version_match(self):
        workflow = text(".github/workflows/bluevpn-site-theme-release.yml")
        self.assertIn("BlueVPN component version mismatch", workflow)
        self.assertIn("release.json", workflow)
        self.assertIn("branding/app.json", workflow)
        self.assertIn("BLUEVPN_MANAGER_VERSION", workflow)

if __name__ == "__main__":
    unittest.main()
