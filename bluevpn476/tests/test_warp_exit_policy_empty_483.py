import pathlib, unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]

class WarpExitPolicyEmpty483(unittest.TestCase):
    def test_wordpress_preserves_explicit_empty_blocklist(self):
        s=(ROOT/"bluevpn-manager/includes/class-bluevpn-ads.php").read_text()
        self.assertIn("$blockedRaw === '' ? []", s)
        self.assertIn("$s['free_warp_blocked_exit_countries'] = $blocked;", s)
        self.assertNotIn("$s['free_warp_blocked_exit_countries'] = $blocked ?: ['IR'];", s)

    def test_public_policy_does_not_reinsert_ir(self):
        s=(ROOT/"bluevpn-manager/includes/class-bluevpn-ads.php").read_text()
        self.assertIn("'blocked_exit_countries'", s)
        self.assertNotIn("($settings['free_warp_blocked_exit_countries'] ?? ['IR'])", s)

    def test_android_explicit_empty_is_authoritative(self):
        s=(ROOT/"android-source/BlueVpnAccountManager.kt").read_text()
        self.assertNotIn('if (isEmpty()) add("IR")', s)
        self.assertNotIn('.ifEmpty { setOf("IR") }', s)
        self.assertIn('getStringSet("warp_blocked_exit_countries", emptySet())', s)

    def test_engine_blocks_ir_only_if_policy_contains_ir(self):
        s=(ROOT/"android-source/BlueVpnWarpEngine.kt").read_text()
        self.assertIn("country in policy.warpBlockedExitCountries", s)
        self.assertIn("IR is allowed when the", s)

if __name__ == "__main__":
    unittest.main()
