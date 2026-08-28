from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]

class AndroidExpandableServerRowsTests(unittest.TestCase):
    def text(self,path):
        return (ROOT/path).read_text(encoding="utf-8")

    def test_manual_server_mode_is_supported(self):
        util=self.text("android-source/BlueVpnLocationUtil.kt")
        self.assertIn("fun setManualServerSelection",util)
        self.assertIn("BlueVpnSelectionMode.MANUAL_SERVER.name",util)
        selection=util[util.index("fun selectionMode"):util.index("fun smartBalance")]
        self.assertNotIn("Migrate any selection",selection)
        self.assertNotIn("remove(KEY_MANUAL_SERVER_GUID)",selection)

    def test_country_rows_expand_into_named_servers(self):
        ui=self.text("android-source/BlueVpnServersActivity.kt")
        self.assertIn("expandedLocationKeys",ui)
        self.assertIn("stableServerRows",ui)
        self.assertIn('getSharedPreferences("bluevpn_server_labels"',ui)
        self.assertIn('group.location.title + " " + ordinal',ui)
        self.assertIn('group.servers.size.toString() + " سرور',ui)

    def test_server_quality_uses_ping_and_signal_bars(self):
        ui=self.text("android-source/BlueVpnServersActivity.kt")
        for token in ['"▂▄▆█"','"▂▄▆"','delay in 1..80','delay > 280']:
            self.assertIn(token,ui)
        self.assertNotIn('"📡 ▂▄▆█"',ui)
        self.assertIn('latency.latencyMs.toString() + " ms • " + signalQuality(candidate)',ui)
        self.assertIn("val latency = latencySnapshot(candidate)",ui)

    def test_auto_and_manual_server_states_are_distinct(self):
        ui=self.text("android-source/BlueVpnServersActivity.kt")
        self.assertIn('"فعال • خودکار"',ui)
        self.assertIn('"فعال • دستی"',ui)
        self.assertIn("BlueVpnPreferences.setManualServerSelection",ui)
        self.assertIn("startLiveLocationSwitch",self.text("android-source/BlueVpnHomeActivity.kt"))

    def test_automatic_subtitle_names_real_selected_server(self):
        ui=self.text("android-source/BlueVpnServersActivity.kt")
        self.assertIn("activeAutoCandidate",ui)
        self.assertIn("stableServerRows(candidate.location, peers)",ui)

if __name__=="__main__":
    unittest.main()
