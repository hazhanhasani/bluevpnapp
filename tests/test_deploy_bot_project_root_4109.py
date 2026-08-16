import pathlib, unittest

ROOT=pathlib.Path(__file__).resolve().parents[1]

class DeployBotProjectRoot4109(unittest.TestCase):
    def text(self,p): return (ROOT/p).read_text()

    def test_deploy_resolves_nested_project_root(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-telegram-bot.php")
        self.assertIn("private static function resolve_project_root",s)
        self.assertIn("$extractRoot = self::extract_zip_safely",s)
        self.assertIn("$root = self::resolve_project_root($extractRoot)",s)

    def test_project_root_requires_core_version_metadata(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-telegram-bot.php")
        block=s[s.index("private static function resolve_project_root"):
                s.index("private static function expected_release_from_tree")]
        self.assertIn("'branding/app.json'",block)
        self.assertIn("'release.json'",block)
        self.assertIn("'bluevpn-manager/bluevpn-manager.php'",block)

    def test_flat_zip_still_supported(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-telegram-bot.php")
        block=s[s.index("private static function resolve_project_root"):
                s.index("private static function expected_release_from_tree")]
        self.assertIn("if($matches($extractedRoot))return $extractedRoot;",block)

    def test_ambiguous_multiple_roots_fail_closed(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-telegram-bot.php")
        self.assertIn("DEPLOY_PROJECT_ROOT_AMBIGUOUS",s)
        self.assertIn("DEPLOY_PROJECT_ROOT_NOT_FOUND",s)

    def test_cleanup_targets_extraction_root(self):
        s=self.text("bluevpn-manager/includes/class-bluevpn-telegram-bot.php")
        deploy=s[s.index("private static function deploy_zip_to_github"):
                 s.index("private static function deploy_extracted_via_git")]
        self.assertIn("$cleanupRoot = isset($extractRoot) ? $extractRoot : $root",deploy)

if __name__=="__main__":
    unittest.main()
