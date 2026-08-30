from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[1]


class AndroidLocationsQualityDashboard612Test(unittest.TestCase):
    def source(self) -> str:
        return (ROOT / "android-source/BlueVpnServersActivity.kt").read_text(
            encoding="utf-8"
        )

    def test_customer_screen_does_not_mount_quality_dashboard(self):
        src = self.source()
        start = src.index("private fun createScreen")
        end = src.index("private fun attachFreeNativeBanner", start)
        screen = src[start:end]
        self.assertNotIn("createQualityDashboard()", screen)
        self.assertIn(
            "Customer UI stays instant: quality diagnostics are background-only.",
            screen,
        )

    def test_ping_events_are_background_only(self):
        src = self.source()
        start = src.index("mainViewModel.updateTestResultAction.observe")
        end = src.index("renderLocations()", start)
        block = src[start:end]
        self.assertIn("recordPublishedLatencySamples(event)", block)
        self.assertNotIn("startFallbackQualitySweep", block)
        self.assertNotIn("refreshVisibleHealthPresentation()", block)
        self.assertIn("No-op by design", src)

    def test_runtime_broadcasts_do_not_dirty_locations_cache(self):
        src = self.source()
        start = src.index("mainViewModel.updateListAction.observe")
        end = src.index("mainViewModel.updateTestResultAction.observe", start)
        block = src[start:end]
        self.assertIn(
            "scheduleCandidateReload(force = false, delayMs = 1_200L)",
            block,
        )
        self.assertNotIn("invalidateResolvedCache()", block)

    def test_server_rows_are_latency_independent(self):
        src = self.source()
        row = (ROOT / "android-source/BlueVpnLocationListRow.kt").read_text(
            encoding="utf-8"
        )
        start = src.index("private fun createServerRow")
        end = src.index("private fun createLocationSection", start)
        body = src[start:end]
        self.assertIn('textView("سرور " + ordinal', body)
        self.assertNotIn("latencySnapshot(candidate)", body)
        self.assertNotIn("serverQualityScore(candidate)", body)
        self.assertNotIn("qualityBadge(", body)

        server_model = row[row.index("data class Server"):]
        content = server_model[server_model.index("override val contentVersion"):]
        self.assertNotIn("latencyPhase.name", content)
        self.assertNotIn("qualityScore.toString()", content)

    def test_country_rows_are_stable_and_count_only(self):
        src = self.source()
        render_start = src.index("private fun renderLocationsNow")
        render_end = src.index("private fun availabilityLabel", render_start)
        render = src[render_start:render_end]
        self.assertIn("val visibleServers = servers", render)
        self.assertNotIn("servers.filter(::candidateMatchesQuality)", render)
        self.assertIn("healthScore = 0", render)
        self.assertIn(
            'availability = group.servers.size.toString() + " سرور"',
            render,
        )

    def test_locations_render_and_warmup_are_near_immediate(self):
        src = self.source()
        theme = (ROOT / "android-source/BlueVpnTheme.kt").read_text(
            encoding="utf-8"
        )
        self.assertIn("setItemViewCacheSize(18)", src)
        self.assertIn(
            "if (BlueVpnPerformance.isLowEnd(this@BlueVpnServersActivity)) 120L else 70L",
            src,
        )
        self.assertIn(
            "if (isLowEnd(context)) 3_000L else 1_500L",
            theme,
        )
        self.assertIn(
            "if (isLowEnd(context)) 32L else 16L",
            theme,
        )

    def test_candidate_cache_and_entitlement_checks_are_batched(self):
        util = (ROOT / "android-source/BlueVpnLocationUtil.kt").read_text(
            encoding="utf-8"
        )
        account = (ROOT / "android-source/BlueVpnAccountManager.kt").read_text(
            encoding="utf-8"
        )
        pool = (ROOT / "android-source/BlueVpnPoolOrchestrator.kt").read_text(
            encoding="utf-8"
        )
        self.assertIn("CANDIDATE_CACHE_TTL_MS = 5 * 60_000L", util)
        self.assertIn("MAX_SCAN_AGE_MS = 2 * 60_000L", pool)
        self.assertIn("private fun filterHardIsolation(", account)
        self.assertIn("BlueVpnPoolOrchestrator.filterAllowed", account)

        start = util.index("fun allCandidates(\n        context: Context")
        end = util.index("fun orderedCandidates(", start)
        block = util[start:end]
        self.assertIn("if (guid !in entitlementServerGuids)", block)
        self.assertNotIn("BlueVpnAccountManager.candidateAllowed(", block)
        self.assertNotIn("reportVerifiedCountry(", block)


if __name__ == "__main__":
    unittest.main()
