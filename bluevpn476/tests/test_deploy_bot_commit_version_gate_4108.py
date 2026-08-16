import pathlib, unittest

ROOT=pathlib.Path(__file__).resolve().parents[1]

class DeployBotCommitVersionGate4108(unittest.TestCase):
    def text(self,p): return (ROOT/p).read_text()

    def bot(self):
        return self.text("bluevpn-manager/includes/class-bluevpn-telegram-bot.php")

    def test_zip_version_is_read_before_deploy(self):
        s=self.bot()
        self.assertIn("expected_release_from_tree",s)
        self.assertIn("branding/app.json",s)
        self.assertIn("release.json",s)
        self.assertIn("BLUEVPN_MANAGER_VERSION",s)
        self.assertIn("DEPLOY_VERSION_MISMATCH",s)

    def test_git_no_change_cannot_dispatch_build(self):
        s=self.bot()
        self.assertIn("DEPLOY_COMMIT_NOT_APPLIED: ZIP هیچ Diff واقعی",s)
        process=s[s.index("public static function process_job"):s.index("private static function download_telegram_zip")]
        self.assertIn("if(empty($deploy['changed']))",process)
        self.assertIn("repository_dispatch مسدود شد",process)

    def test_rest_transport_refuses_empty_tree_commit(self):
        s=self.bot()
        rest=s[s.index("private static function deploy_extracted_via_rest"):
               s.index("private static function extract_zip_safely")]
        self.assertIn("if($treeSha===$baseTree)",rest)
        self.assertIn("Commit خالی ساخته نشد",rest)

    def test_remote_commit_must_have_actual_file_diff(self):
        s=self.bot()
        verify=s[s.index("private static function verify_deployed_release"):
                 s.index("private static function deploy_zip_to_github")]
        self.assertIn("/commits/",verify)
        self.assertIn("count((array)($commitPayload['files']??[]))===0",verify)
        self.assertIn("Commit مقصد در GitHub هیچ فایل تغییرکرده‌ای ندارد",verify)

    def test_exact_sha_branding_version_must_match_zip(self):
        s=self.bot()
        verify=s[s.index("private static function verify_deployed_release"):
                 s.index("private static function deploy_zip_to_github")]
        self.assertIn("github_file_at_commit('branding/app.json'",verify)
        self.assertIn("DEPLOY_VERSION_NOT_APPLIED",verify)
        self.assertIn("expected=",verify)
        self.assertIn("remote=",verify)

    def test_release_and_manager_are_verified_at_exact_sha(self):
        s=self.bot()
        verify=s[s.index("private static function verify_deployed_release"):
                 s.index("private static function deploy_zip_to_github")]
        self.assertIn("github_file_at_commit('release.json'",verify)
        self.assertIn("github_file_at_commit('bluevpn-manager/bluevpn-manager.php'",verify)
        self.assertIn("DEPLOY_RELEASE_METADATA_NOT_APPLIED",verify)
        self.assertIn("DEPLOY_MANAGER_VERSION_NOT_APPLIED",verify)

    def test_dispatch_has_second_empty_commit_gate(self):
        s=self.bot()
        block=s[s.index("private static function start_android_build_for_job"):
                s.index("private static function dispatch_workflow")]
        self.assertIn("verify_commit_on_branch($commit,$s)",block)
        self.assertIn("/commits/",block)
        self.assertIn("Commit بدون Diff است",block)
        self.assertIn("$trigger = self::dispatch_build($commit, $s)",block)

    def test_success_message_reports_verified_version_not_copied_count_claim(self):
        s=self.bot()
        process=s[s.index("public static function process_job"):s.index("private static function download_telegram_zip")]
        self.assertIn("روی SHA مقصد تأیید شد",process)
        self.assertIn("expected_version",process)
        self.assertIn("فایل‌های تغییرکرده",process)

if __name__=="__main__":
    unittest.main()
