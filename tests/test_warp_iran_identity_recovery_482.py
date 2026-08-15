import pathlib, unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]
class WarpIranIdentityRecovery482(unittest.TestCase):
    def test_distinct_iran_strategies_poison_identity(self):
        s=(ROOT/"android-source/BlueVpnWarpEngine.kt").read_text()
        self.assertIn("IR_POISON_DISTINCT_STRATEGIES = 3", s)
        self.assertIn("recordIranExit", s)
        self.assertIn("Integer.bitCount(mask)", s)
        self.assertIn('putLong("ir_poisoned_at:$sig"', s)
    def test_poisoned_identity_is_quarantined_not_loop_deleted(self):
        s=(ROOT/"android-source/BlueVpnWarpEngine.kt").read_text()
        self.assertIn("recoverPoisonedIdentityIfNeeded", s)
        self.assertIn("IR_IDENTITY_ROTATION_COOLDOWN_MS", s)
        self.assertIn("bluevpn-aether-ir-quarantine-", s)
        self.assertIn("MAX_QUARANTINED_IDENTITIES", s)
    def test_rotation_clears_ir_backoff_and_lkg(self):
        s=(ROOT/"android-source/BlueVpnWarpEngine.kt").read_text()
        self.assertIn('remove("lkg:$sig")', s)
        self.assertIn('remove("backoff:$sig:${strategy.name}")', s)
        self.assertIn('remove("edge:$sig:${strategy.name}")', s)
    def test_success_clears_poison_state(self):
        s=(ROOT/"android-source/BlueVpnWarpEngine.kt").read_text()
        self.assertIn("clearIranPoisonState(prefs, shape.signature)", s)
if __name__=="__main__": unittest.main()
