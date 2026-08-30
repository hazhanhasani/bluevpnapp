from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[1]


class AndroidLocationsQualityDashboard612Test(unittest.TestCase):
    def source(self) -> str:
        return (ROOT / "android-source/BlueVpnServersActivity.kt").read_text(
            encoding="utf-8"
        )

    def row_source(self) -> str:
        return (ROOT / "android-source/BlueVpnLocationListRow.kt").read_text(
            encoding="utf-8"
        )

    def diff_source(self) -> str:
        return (ROOT / "android-source/BlueVpnLocationRowDiff.kt").read_text(
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

    def test_quality_filters_use_blended_score_not_ping_only(self):
        src = self.source()
        for token in [
            "private fun serverQualityScore",
            "QualityFilter.FAST",
            "QualityFilter.USABLE",
            "QualityFilter.NEEDS_TEST",
            "servers.filter(::candidateMatchesQuality)",
            "snapshot.phase == BlueVpnLatencyPhase.FRESH && score >= 78",
            "score >= 52",
            '"A / سریع"',
            '"B / پایدار"',
            '"ضعیف / تست"',
        ]:
            self.assertIn(token, src)

    def test_server_rows_have_grade_score_vivid_surface_and_graphical_flags(self):
        src = self.source()
        for token in [
            "private fun qualityBadge(",
            "private fun qualityPillColor",
            "private fun qualitySurfaceColor",
            "private fun qualityStrokeColor",
            "private fun countryBadgeAccent",
            "countryFlagBadge(group.location.flag, sizeDp = 38",
            "countryFlagBadge(group.location.flag, sizeDp = 52",
            '"A+ • $score"',
            '"A • $score"',
            '"B • $score"',
            '"C • $score"',
            "if (active) palette.accent else android.graphics.Color.TRANSPARENT",
        ]:
            self.assertIn(token, src)

    def test_dashboard_exposes_live_grade_counts_and_country_aggregate_score(self):
        src = self.source()
        for token in [
            "qualityExcellentStat",
            "qualityStableStat",
            "qualityRetryStat",
            '"A+ عالی\\n$excellent سرور"',
            '"B پایدار\\n$stable سرور"',
            '"A+ $excellent عالی • ${bestLatency}ms • $bestScore/100"',
            '"$stable پایدار • امتیاز برتر $bestScore/100"',
            "gradientRounded(palette.accent, 0xFF725BFF.toInt(), 3)",
        ]:
            self.assertIn(token, src)

    def test_locations_quality_ui_is_compact_throttled_and_has_tcp_fallback(self):
        src = self.source()
        util = (ROOT / "android-source/BlueVpnLocationUtil.kt").read_text(
            encoding="utf-8"
        )
        self.assertIn("dp(112)", src)
        self.assertNotIn("column.addView(stats", src)
        self.assertIn("setItemViewCacheSize(10)", src)
        self.assertIn("renderHandler.postDelayed(healthRefreshRunnable, 260L)", src)
        self.assertIn("expandedLocationKeys.clear()", src)
        self.assertIn("startFallbackQualitySweep", src)
        self.assertIn("usesFallbackProbe", src)
        self.assertIn("پیش‌تست شبکه", src)
        self.assertIn("fun advisoryTcpLatency", util)
        self.assertNotIn("requestHealthSweepIfNeeded(loaded, force = requestedForce)", src)

    def test_quality_score_is_part_of_diffable_row_state(self):
        row = self.row_source()
        diff = self.diff_source()
        self.assertIn("val qualityScore: Int", row)
        self.assertIn("qualityScore.toString()", row)
        self.assertIn("oldItem.qualityScore != newItem.qualityScore", diff)
        self.assertIn("qualityScore = serverQualityScore(candidate)", self.source())


if __name__ == "__main__":
    unittest.main()
