import pathlib, unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]

class StagedRegressionGate4100(unittest.TestCase):
    def text(self,p): return (ROOT/p).read_text()

    def test_gate_runs_named_substages(self):
        s=self.text(".github/workflows/build-apk.yml")
        for name in [
            "repository-cleanup",
            "pycompile",
            "release-validator",
            "python-regression",
            "php-release",
        ]:
            self.assertIn(f"run_gate {name}", s)
        self.assertIn("REGRESSION_SUBSTAGE_FAILED=", s)
        self.assertIn("REGRESSION_EXIT_CODE=", s)

    def test_cleanup_runs_immediately_inside_gate(self):
        s=self.text(".github/workflows/build-apk.yml")
        start=s.index("BlueVPN stability completion regression gate")
        end=s.index("Upload failed Android source preparation log",start)
        gate=s[start:end]
        cleanup=gate.index("run_gate repository-cleanup")
        tests=gate.index("run_gate python-regression")
        self.assertLess(cleanup,tests)
        self.assertIn("python scripts/cleanup_repository.py",gate)

    def test_unittest_is_failfast_and_explicit_pattern(self):
        s=self.text(".github/workflows/build-apk.yml")
        self.assertIn("-p 'test_*.py'",s)
        self.assertIn("-f",s)

    def test_failure_report_names_regression_substage(self):
        s=self.text(".github/workflows/build-apk.yml")
        self.assertIn(".bluevpn-regression-substage",s)
        self.assertIn("Regression substage:",s)
        self.assertIn("REGRESSION_SUBSTAGE_FAILED",s)
        self.assertIn("AssertionError",s)
        self.assertIn("PHP LINT FAILED",s)

if __name__=="__main__":
    unittest.main()
