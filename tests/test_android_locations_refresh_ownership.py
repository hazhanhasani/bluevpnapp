from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class AndroidLocationsRefreshOwnershipTest(unittest.TestCase):
    def text(self, path: str) -> str:
        return (ROOT / path).read_text(encoding="utf-8")

    def test_refresh_coordinator_is_packaged_into_android_overlay(self):
        src = self.text("android-source/BlueVpnRefreshCoordinator.kt")
        prepare = self.text("scripts/prepare_android.py")
        self.assertIn("class BlueVpnRefreshCoordinator", src)
        self.assertIn("ACCOUNT_SYNC", src)
        self.assertIn("POOL_RELOAD", src)
        self.assertIn("fun finish(token: Long)", src)
        self.assertIn(
            'bluevpn_dir / "BlueVpnRefreshCoordinator.kt": ROOT / "android-source/BlueVpnRefreshCoordinator.kt"',
            prepare,
        )

    def test_ping_and_list_broadcasts_cannot_finish_manual_refresh(self):
        src = self.text("android-source/BlueVpnServersActivity.kt")

        list_start = src.index("mainViewModel.updateListAction.observe")
        ping_start = src.index("mainViewModel.updateTestResultAction.observe", list_start)
        list_body = src[list_start:ping_start]

        resume_start = src.index("renderLocations()", ping_start)
        ping_body = src[ping_start:resume_start]

        for body in (list_body, ping_body):
            self.assertNotIn("refreshCoordinator.finish(", body)
            self.assertNotIn("stopRefreshingVisual()", body)

    def test_manual_refresh_owns_account_then_pool_reload(self):
        src = self.text("android-source/BlueVpnServersActivity.kt")
        self.assertIn("val token = refreshCoordinator.begin()", src)
        self.assertIn(
            "refreshEntitlementState(force = true, refreshToken = token)",
            src,
        )
        self.assertIn("refreshCoordinator.beginPoolReload(it)", src)
        self.assertIn(
            "loadCandidates(force = true, refreshToken = refreshToken)",
            src,
        )
        self.assertIn("refreshCoordinator.finish(token)", src)

    def test_ui_deadline_is_later_than_account_network_deadline(self):
        activity = self.text("android-source/BlueVpnServersActivity.kt")
        account = self.text("android-source/BlueVpnAccountManager.kt")
        self.assertIn("accountSyncRequest -> 35_000", account)
        self.assertIn("postDelayed(refreshTimeoutRunnable, 42_000L)", activity)

    def test_timeout_keeps_existing_location_pool(self):
        src = self.text("android-source/BlueVpnServersActivity.kt")
        start = src.index("if (result.isSuccess)")
        end = src.index("if (accountSyncPending)", start)
        body = src[start:end]
        self.assertIn("refreshVisibleHealthPresentation()", body)
        self.assertNotIn("BlueVpnLocationUtil.invalidateCache()", body.split("} else {", 1)[1])


if __name__ == "__main__":
    unittest.main()
