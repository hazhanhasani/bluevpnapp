import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CLEANUP = ROOT / "scripts" / "cleanup_repository.py"


class RepositoryCleanup470Tests(unittest.TestCase):
    def test_retired_legacy_tests_are_removed_without_blanket_test_deletion(self):
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            (root / "scripts").mkdir()
            (root / "tests").mkdir()
            (root / "android-source" / "generated").mkdir(parents=True)
            (root / "scripts" / "cleanup_repository.py").write_text(CLEANUP.read_text())

            retired = [
                "test_blueai_scoring.py",
                "test_bluepay_payment_runtime_v378.py",
                "test_database_fk_migration_regression.py",
                "test_updater_release_metadata.py",
            ]
            for name in retired:
                (root / "tests" / name).write_text("raise RuntimeError('legacy test must not run')\n")
            survivor = root / "tests" / "test_current_release.py"
            survivor.write_text("# current regression suite\n")

            cp = subprocess.run(
                [sys.executable, str(root / "scripts" / "cleanup_repository.py")],
                cwd=root,
                check=False,
                text=True,
                stdout=subprocess.PIPE,
                stderr=subprocess.STDOUT,
            )
            self.assertEqual(cp.returncode, 0, cp.stdout)
            for name in retired:
                self.assertFalse((root / "tests" / name).exists(), name)
            self.assertTrue(survivor.exists())
            self.assertFalse((root / "android-source" / "generated").exists())

    def test_all_failed_ci_legacy_modules_are_explicitly_retired(self):
        source = CLEANUP.read_text()
        failed_modules = [
            "test_blueai_scoring.py", "test_blueai_submit_event.py",
            "test_bluepanel_sms_center_v332.py", "test_bluepay_invalid_invoice_purge_v320.py",
            "test_bluepay_official_contract_v374.py", "test_bluepay_payment_runtime_v378.py",
            "test_checkout_lifecycle_v318.py", "test_database_fk_migration_regression.py",
            "test_email_global_sub_v335.py", "test_entitlement_hot_swap_v371.py",
            "test_expiry_regression_v323.py", "test_farazsms_502_resilience_v354.py",
            "test_farazsms_catalog_sync_v358.py", "test_farazsms_pattern_pagination_v360.py",
            "test_farazsms_shared_sender_v353.py", "test_iranpayamak_v355.py",
            "test_iranpayamak_validation_v357.py", "test_jalali_tehran_v321.py",
            "test_live_connections_v317.py", "test_locations_bluepay_recovery_v372.py",
            "test_payment_expiry_v315.py", "test_pending_orders_v316.py",
            "test_phone_otp_v325.py", "test_runtime_pool_bluepay_recovery_v376.py",
            "test_safe_ad_render_v348.py", "test_subscription_entitlement_detection_v324.py",
            "test_subscription_recovery_v322.py", "test_updater_release_metadata.py",
        ]
        for name in failed_modules:
            self.assertIn(f'"{name}"', source)


if __name__ == "__main__":
    unittest.main(verbosity=2)
