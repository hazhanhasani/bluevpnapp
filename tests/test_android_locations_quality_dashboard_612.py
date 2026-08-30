from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[1]


class AndroidLocationsQualityDashboard612Test(unittest.TestCase):
    def source(self) -> str:
        return (ROOT / "android-source/BlueVpnServersActivity.kt").read_text(
            encoding="utf-8"
        )

    def test_live_quality_dashboard_runs_the_real_ping_pipeline(self):
        src = self.source()
        start = src.index("private fun runManualQualitySweep")
        end = src.index("private fun updateQualityFilterButtons", start)
        body = src[start:end]
        self.assertIn("createQualityDashboard()", src)
        self.assertIn("markLatencyMeasurementStarted(candidates)", body)
        self.assertIn("mainViewModel.testAllRealPing()", body)
        self.assertIn("BlueVpnLatencyPolicy.MEASUREMENT_TIMEOUT_MS", body)
        self.assertIn('"تست همه"', src)
        self.assertIn('"کیفیت زنده سرورها"', src)

    def test_quality_filters_are_real_stateful_server_filters(self):
        src = self.source()
        for token in [
            "QualityFilter.ALL",
            "QualityFilter.FAST",
            "QualityFilter.USABLE",
            "QualityFilter.NEEDS_TEST",
            "servers.filter(::candidateMatchesQuality)",
            'putString("quality_filter", selectedQualityFilter.name)',
            "append(selectedQualityFilter.name)",
        ]:
            self.assertIn(token, src)
        self.assertIn("snapshot.phase == BlueVpnLatencyPhase.FRESH && level >= 3", src)

    def test_servers_have_visual_quality_surfaces_and_flag_badges(self):
        src = self.source()
        for token in [
            "private fun qualityBadge",
            "private fun qualityPillColor",
            "private fun qualitySurfaceColor",
            "private fun qualityStrokeColor",
            "countryFlagBadge(group.location.flag, sizeDp = 38",
            "countryFlagBadge(group.location.flag, sizeDp = 52",
            '4 -> "▂▄▆█  عالی"',
            '2 -> "▂▄  متوسط"',
            '1 -> "▂  ضعیف"',
        ]:
            self.assertIn(token, src)

    def test_country_cards_expose_aggregate_quality_and_progress(self):
        src = self.source()
        for token in [
            "private fun countryQualitySummary",
            '"$fast سریع • بهترین ${bestLatency}ms"',
            '"$ready آماده اتصال"',
            '"$retry نیازمند تست دوباره"',
            "qualityProgressFill",
            "qualityProgressRemainder",
            'qualityProgress.text = "$percent٪"',
        ]:
            self.assertIn(token, src)


if __name__ == "__main__":
    unittest.main()
