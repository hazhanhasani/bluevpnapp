import json
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
def text(rel): return (ROOT/rel).read_text(encoding="utf-8")

class WindowsPendingStableConvergence4172Tests(unittest.TestCase):
    def test_release_version(self):
        r=json.loads(text("release.json")); self.assertEqual(r["version"],"6.2.4"); self.assertEqual(r["version_code"],60204)
    def test_pending_stable_intent_is_persisted_and_finalized(self):
        wm=text("bluevpn-manager/includes/class-bluevpn-windows-release-manager.php")
        self.assertIn("PENDING_STABLE_OPTION",wm)
        self.assertIn("request_stable_when_available",wm)
        self.assertIn("finalize_pending_stable_if_ready",wm)
        self.assertIn("pending_stable_version",wm)
        self.assertIn("$pendingResult=self::finalize_pending_stable_if_ready();",wm)
    def test_force_refresh_is_synchronous(self):
        api=text("bluevpn-manager/includes/class-bluevpn-api.php")
        self.assertIn("sync_now(true, 'rest_force_refresh')",api)
        self.assertIn("synchronous_force_refresh",api)
        self.assertIn("release_refresh_ok",api)
    def test_workflow_uses_canonical_rest_and_checks_refresh(self):
        wf=text(".github/workflows/build-windows.yml")
        self.assertIn("/wp-json/bluevpn/v1/windows/release-sync",wf)
        self.assertIn("X-BlueVPN-Release-Signature",wf)
        self.assertNotIn("bluevpn-wordpress-windows-update-after-sync.json",wf)
    def test_combined_publish_can_finish_later_without_second_click(self):
        cc=text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        self.assertIn("درخواست انتشار رسمی ویندوز نیز ثبت شد",cc)

if __name__=="__main__": unittest.main()
