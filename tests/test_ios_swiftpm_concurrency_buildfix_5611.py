import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]


class IOSSwiftPMConcurrencyBuildFix5611Tests(unittest.TestCase):
    def test_dependency_is_compiled_in_compatible_language_mode(self):
        workflow = (ROOT / ".github/workflows/build-ios.yml").read_text(encoding="utf-8")
        self.assertIn("SWIFT_VERSION=5", workflow)
        self.assertIn("SWIFT_STRICT_CONCURRENCY=minimal", workflow)

    def test_compiler_errors_are_emitted_as_action_annotations(self):
        workflow = (ROOT / ".github/workflows/build-ios.yml").read_text(encoding="utf-8")
        self.assertIn("BlueVPN iOS compiler", workflow)
        self.assertIn("diagnostics/xcodebuild-errors.txt", workflow)


if __name__ == "__main__":
    unittest.main()
