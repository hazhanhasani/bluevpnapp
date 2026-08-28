from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]

class AndroidLocationServerExpansionTests(unittest.TestCase):
    def text(self,path):
        return (ROOT/path).read_text(encoding="utf-8")

    def test_country_rows_expand_into_stable_numbered_servers(self):
        src=self.text("android-source/BlueVpnServersActivity.kt")
        for token in [
            "expandedLocationKeys",
            "stableServerRows",
            "bluevpn_server_labels",
            "group.location.title + \" \" + ordinal",
            "createServerRow",
        ]:
            self.assertIn(token,src)

    def test_server_quality_uses_signal_bars_and_ping(self):
        src=self.text("android-source/BlueVpnServersActivity.kt")
        for token in [
            '4 -> "▂▄▆█"',
            '3 -> "▂▄▆"',
            '2 -> "▂▄"',
            '1 -> "▂"',
            'candidate.delay.toString() + " ms • " + signalQuality(candidate)',
        ]:
            self.assertIn(token,src)
        self.assertNotIn('4 -> "📡 ▂▄▆█"',src)

    def test_auto_mode_expands_actual_connected_country_without_switching_mode(self):
        src=self.text("android-source/BlueVpnServersActivity.kt")
        start=src.index("val activeLocationKey = when")
        end=src.index("val recentKeys =",start)
        body=src[start:end]
        self.assertIn("connectedNow && automatic && activeLocationKey.isNotBlank()",body)
        self.assertIn("expandedLocationKeys.add(activeLocationKey)",body)
        self.assertNotIn("setManualServerSelection",body)

    def test_actual_auto_server_is_marked_inside_expanded_country(self):
        src=self.text("android-source/BlueVpnServersActivity.kt")
        start=src.index("private fun createServerRow")
        end=src.index("private fun createLocationSection",start)
        body=src[start:end]
        self.assertIn("automatic && connected && candidate.guid == selectedGuid",body)
        self.assertIn('"خودکار"',body)

if __name__=="__main__":
    unittest.main()
