import pathlib
import re
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]

class WindowsPublicRouteLabelBuildFix50606Tests(unittest.TestCase):
    def test_public_route_label_helper_exists_for_connect_ui(self):
        src = (ROOT / "bluevpn-windows/MainWindow.xaml.cs").read_text(encoding="utf-8")
        self.assertEqual(src.count("PublicRouteLabel(\"ویژه\", result.Verification.Country)"), 1)
        self.assertEqual(src.count("PublicRouteLabel(\"رایگان\", result.Verification.Country)"), 1)
        self.assertRegex(
            src,
            r"private\s+static\s+string\s+PublicRouteLabel\s*\(string\s+tierLabel,\s*string\?\s+country\)",
        )

    def test_public_route_label_has_no_raw_endpoint_input(self):
        src = (ROOT / "bluevpn-windows/MainWindow.xaml.cs").read_text(encoding="utf-8")
        helper = src.split("private static string PublicRouteLabel", 1)[1].split("private static string FormatLatency", 1)[0]
        self.assertNotIn("result.Endpoint", helper)
        self.assertNotIn("DiagnosticName", helper)
        self.assertNotIn(".Name", helper)
        self.assertIn("BlueVPN", helper)

if __name__ == "__main__":
    unittest.main()
