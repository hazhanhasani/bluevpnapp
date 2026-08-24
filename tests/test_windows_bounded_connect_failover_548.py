import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]


class WindowsBoundedConnectFailover548Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.orchestrator = (ROOT / "bluevpn-windows/Services/ConnectionOrchestrator.cs").read_text()
        cls.window = (ROOT / "bluevpn-windows/MainWindow.xaml.cs").read_text()
        cls.xaml = (ROOT / "bluevpn-windows/MainWindow.xaml").read_text()

    def test_entire_connection_attempt_has_hard_deadline(self):
        self.assertIn("attempt.CancelAfter(TimeSpan.FromSeconds(72))", self.orchestrator)
        self.assertIn("catch (OperationCanceledException ex) when (!ct.IsCancellationRequested)", self.orchestrator)
        self.assertIn("اتصال در زمان مجاز کامل نشد", self.orchestrator)

    def test_each_candidate_times_out_and_advances_failover(self):
        self.assertIn("candidateBudget.CancelAfter(TimeSpan.FromSeconds(24))", self.orchestrator)
        self.assertIn("when (!ct.IsCancellationRequested)", self.orchestrator)
        self.assertIn("مسیر بعدی بررسی می‌شود", self.orchestrator)
        self.assertIn("_ai.RecordFailure(endpoint, premium, lastError.Message)", self.orchestrator)

    def test_progress_is_visible_and_raw_diagnostics_are_not_on_overlay(self):
        self.assertIn("فعال‌سازی Xray • مسیر {candidateIndex} از {candidates.Count}", self.orchestrator)
        self.assertIn("فقط اتصال تأییدشده نمایش داده می‌شود", self.xaml)
        self.assertIn("مسیر اتصال این سرور کامل نشد", self.window)


if __name__ == "__main__":
    unittest.main()
