from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]

class WindowsStartupCrashResilienceTests(unittest.TestCase):
    def text(self,path):
        return (ROOT/path).read_text(encoding="utf-8")

    def test_app_uses_single_manual_startup_path(self):
        xaml=self.text("bluevpn-windows/App.xaml")
        app=self.text("bluevpn-windows/App.xaml.cs")
        self.assertNotIn('StartupUri="MainWindow.xaml"',xaml)
        self.assertIn("var window = new MainWindow();",app)
        self.assertIn("window.Show();",app)

    def test_startup_has_crash_log_and_smoke_mode(self):
        app=self.text("bluevpn-windows/App.xaml.cs")
        self.assertIn("DispatcherUnhandledException",app)
        self.assertIn("startup-fatal",app)
        self.assertIn("--startup-smoke",app)
        self.assertIn("Shutdown(0)",app)

    def test_webview_is_not_constructed_in_mainwindow_constructor(self):
        src=self.text("bluevpn-windows/MainWindow.xaml.cs")
        ctor_start=src.index("public MainWindow()")
        ctor_end=src.index("private bool TryCreateTapsellWebSurface",ctor_start)
        ctor=src[ctor_start:ctor_end]
        self.assertNotIn("TryCreateTapsellWebSurface();",ctor)
        show=src[src.index("private async Task<bool> ShowTapsellWebAdAsync"):]
        self.assertIn("EnsureTapsellWebInitializedAsync",show)
        init=src[src.index("private async Task<bool> EnsureTapsellWebInitializedAsync"):src.index("private void ScheduleTapsellWarmup")]
        self.assertIn("TryCreateTapsellWebSurface()",init)

    def test_ci_launches_real_published_executable(self):
        wf=self.text(".github/workflows/build-windows.yml")
        self.assertIn("Smoke launch published Windows executable",wf)
        self.assertIn("--startup-smoke",wf)
        self.assertIn("Start-Process -FilePath $exe",wf)

if __name__=="__main__":
    unittest.main()
