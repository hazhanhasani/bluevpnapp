import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]


class WindowsTapsellMediaAdPublisherOrigin595Tests(unittest.TestCase):
    def test_windows_extracts_mediaad_publisher_from_official_tapsell_snippet(self):
        service = (ROOT / "bluevpn-windows/Services/AdvertisementService.cs").read_text(encoding="utf-8")
        self.assertIn("WindowsWebPublisherHost", service)
        # AdvertisementService uses a regular C# string literal. Therefore the
        # regex escapes that reach System.Text.RegularExpressions (\d, \.) are
        # represented by doubled backslashes in the C# source file.
        self.assertIn("mediaad\\\\.org/serve/", service)
        self.assertIn("loader\\\\.js", service)
        self.assertIn("Uri.CheckHostName", service)

    def test_windows_prioritizes_exact_registered_publisher_origin(self):
        service = (ROOT / "bluevpn-windows/Services/AdvertisementService.cs").read_text(encoding="utf-8")
        bridge = service[service.index("public IReadOnlyList<string> WindowsWebBridgeCandidates"):service.index("private void LoadWindowsWebState")]
        self.assertIn("publisherHost", bridge)
        self.assertIn("x.IdnHost.Equals(publisherHost", bridge)
        self.assertIn("bridge.IdnHost.Equals(publisherHost", bridge)
        self.assertIn("return candidates;", bridge)
        # A MediaAd/Tapsell snippet explicitly registered for blluepanel.ir must
        # not spend its first provider timeout on bot.blluepanel.ir.
        self.assertIn("do not intentionally run it first on a different origin", bridge)


if __name__ == "__main__":
    unittest.main()
