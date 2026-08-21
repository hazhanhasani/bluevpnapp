from pathlib import Path
import json
import unittest

ROOT = Path(__file__).resolve().parents[1]


def text(rel):
    return (ROOT / rel).read_text(encoding="utf-8")


class WindowsUpdateStorage4179Tests(unittest.TestCase):
    def test_release_is_4179(self):
        app = json.loads(text("branding/app.json"))
        release = json.loads(text("release.json"))
        self.assertEqual(app["version_name"], "5.1.2")
        self.assertEqual(app["version_code"], 50102)
        self.assertEqual(release["version"], "5.1.2")
        self.assertEqual(release["windows_version"], "5.1.2")

    def test_update_candidate_carries_asset_size(self):
        models = text("bluevpn-windows/Models/WindowsRuntimeModels.cs")
        api = text("bluevpn-windows/Services/AppUpdateService.cs")
        self.assertIn("long SizeBytes", models)
        self.assertIn("Math.Max(0, info.Size)", api)
        self.assertIn("candidate.SizeBytes", api)

    def test_app_update_preflights_and_cleans_storage(self):
        storage = text("bluevpn-windows/Services/UpdateStorageManager.cs")
        updater = text("bluevpn-windows/Services/AppUpdateService.cs")
        self.assertIn("DriveInfo", storage)
        self.assertIn("AvailableFreeSpace", storage)
        self.assertIn("CleanupUpdateCache", storage)
        self.assertIn('"*.part"', storage)
        self.assertIn("MinimumInstallReserveBytes", storage)
        self.assertIn("PrepareAppUpdate", updater)
        self.assertIn("InsufficientUpdateSpaceException", updater)
        self.assertIn("فضای کافی برای بروزرسانی BlueVPN وجود ندارد", storage)

    def test_download_supports_range_resume_and_verified_size(self):
        gh = text("bluevpn-windows/Services/GitHubReleaseClient.cs")
        self.assertIn("RangeHeaderValue(existing, null)", gh)
        self.assertIn("HttpStatusCode.PartialContent", gh)
        self.assertIn("FileMode.Append", gh)
        self.assertIn("expectedSize", gh)
        self.assertIn("actualSize != expectedSize", gh)
        self.assertIn("Sha256Async(temp", gh)

    def test_runtime_update_has_disk_guard(self):
        runtime = text("bluevpn-windows/Services/RuntimeUpdateService.cs")
        self.assertIn('asset.TryGetProperty("size"', runtime)
        self.assertIn("UpdateStorageManager.PrepareRuntimeUpdate", runtime)
        self.assertIn("compressedBytes", runtime)

    def test_ui_shows_progress_and_never_exposes_raw_disk_error(self):
        main = text("bluevpn-windows/MainWindow.xaml.cs")
        self.assertIn("new Progress<double>", main)
        self.assertIn("فضای کافی برای بروزرسانی وجود ندارد", main)
        self.assertIn("بروزرسانی BlueVPN", main)


if __name__ == "__main__":
    unittest.main()
