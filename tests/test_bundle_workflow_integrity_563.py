import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]

class BundleWorkflowIntegrity563Tests(unittest.TestCase):
    def test_all_deployment_workflows_are_present(self):
        expected={"bluevpn-manager-release.yml","bluevpn-sentinel.yml","bluevpn-site-theme-release.yml","build-apk.yml","build-ios.yml","build-windows.yml","external-health.yml","project-health.yml"}
        actual={p.name for p in (ROOT/".github/workflows").glob("*.yml")}
        self.assertTrue(expected.issubset(actual))

    def test_bundle_validator_is_part_of_health_gate(self):
        workflow=(ROOT/".github/workflows/project-health.yml").read_text()
        self.assertIn("python scripts/validate_bundle_integrity.py",workflow)

if __name__ == "__main__": unittest.main()
