import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


class IOSFailureDiagnosticsTests(unittest.TestCase):
    def test_ios_build_preserves_and_surfaces_xcode_errors(self):
        workflow = (ROOT / ".github/workflows/build-ios.yml").read_text(encoding="utf-8")
        self.assertIn("tee diagnostics/xcodebuild-ios.log", workflow)
        self.assertIn("diagnostics/xcodebuild-errors.txt", workflow)
        self.assertIn("BLUEVPN_IOS_BUILD_DIAGNOSTIC_BEGIN", workflow)
        self.assertIn("if: ${{ always() }}", workflow)
        self.assertIn("name: BlueVPN-iOS-build-diagnostics", workflow)

    def test_sentinel_monitors_ios_workflow(self):
        sentinel = (ROOT / ".github/workflows/bluevpn-sentinel.yml").read_text(encoding="utf-8")
        self.assertIn("- Build BlueVPN iOS", sentinel)
        self.assertIn("gh run view \"$RUN_ID\"", sentinel)
        self.assertIn("--log-failed", sentinel)


if __name__ == "__main__":
    unittest.main()
