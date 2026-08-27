from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]

class WindowsStartupCrashRecoveryTests(unittest.TestCase):
    def text(self,path):
        return (ROOT/path).read_text(encoding="utf-8")

    def test_wpf_startup_is_guarded(self):
        app_xaml=self.text("bluevpn-windows/App.xaml")
        app_cs=self.text("bluevpn-windows/App.xaml.cs")
        self.assertNotIn('StartupUri="MainWindow.xaml"', app_xaml)
        self.assertIn("protected override void OnStartup", app_cs)
        self.assertIn("var window = new MainWindow()", app_cs)
        self.assertIn('WriteCrashLog("startup-fatal"', app_cs)
        self.assertIn("DispatcherUnhandledException", app_cs)

    def test_startup_crashes_are_persisted_per_user(self):
        app_cs=self.text("bluevpn-windows/App.xaml.cs")
        self.assertIn("Environment.SpecialFolder.LocalApplicationData", app_cs)
        self.assertIn('"BlueVPN"', app_cs)
        self.assertIn('"logs"', app_cs)
        self.assertIn('"startup.log"', app_cs)
        self.assertIn("AppDomain.CurrentDomain.UnhandledException", app_cs)
        self.assertIn("TaskScheduler.UnobservedTaskException", app_cs)

    def test_appsettings_missing_or_invalid_does_not_kill_startup(self):
        settings=self.text("bluevpn-windows/Services/AppSettings.cs")
        self.assertIn("CreateSafeDefaults()", settings)
        self.assertIn("if (!File.Exists(path)) return fallback;", settings)
        self.assertIn("catch", settings)
        self.assertIn("return fallback;", settings)
        self.assertIn('"https://blluepanel.ir"', settings)

if __name__=="__main__":
    unittest.main()
