from pathlib import Path
import json
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]


def text(rel):
    return (ROOT / rel).read_text(encoding="utf-8")


def block(source: str, start: str, end: str) -> str:
    i = source.index(start)
    j = source.index(end, i + len(start))
    return source[i:j]



class TestCrossComponentReleaseAudit(unittest.TestCase):
    """Cross-component guards for app + Manager + site release integrity."""

    def test_android_overlay_is_complete(self):
        root = ROOT
        prepare = (root / "scripts/prepare_android.py").read_text(encoding="utf-8")
        missing = []
        for source in sorted((root / "android-source").glob("*.kt")):
            if source.name not in prepare:
                missing.append(source.name)
        self.assertEqual(missing, [], f"Android overlay files not copied by prepare_android.py: {missing}")

    def test_runtime_audit_event_references_exist(self):
        root = ROOT
        audit = (root / "android-source/BlueVpnRuntimeAudit.kt").read_text(encoding="utf-8")
        enum_match = re.search(r"enum class Event\s*\{(.*?)\n\s*\}", audit, re.S)
        self.assertIsNotNone(enum_match, "BlueVpnRuntimeAudit.Event enum not found")
        enum_names = set(re.findall(r"\b([A-Z][A-Z0-9_]+)\s*,?", enum_match.group(1)))
        used = set()
        for source in (root / "android-source").glob("*.kt"):
            text = source.read_text(encoding="utf-8")
            used.update(re.findall(r"BlueVpnRuntimeAudit\.Event\.([A-Z][A-Z0-9_]+)", text))
        unknown = sorted(used - enum_names)
        self.assertEqual(unknown, [], f"Unknown BlueVpnRuntimeAudit.Event references: {unknown}")

    def test_network_recovery_api_contract_is_complete(self):
        root = ROOT
        manager = (root / "android-source/BlueVpnNetworkRecoveryManager.kt").read_text(encoding="utf-8")
        keepalive = (root / "android-source/BlueVpnWarpKeepAliveService.kt").read_text(encoding="utf-8")
        home = (root / "android-source/BlueVpnHomeActivity.kt").read_text(encoding="utf-8")
        prepare = (root / "scripts/prepare_android.py").read_text(encoding="utf-8")

        if "BlueVpnWarpKeepAliveService.requestNetworkRecovery(" in manager:
            self.assertIn("fun requestNetworkRecovery(context: Context)", keepalive)

        self.assertIn(
            "import com.v2ray.ang.bluevpn.BlueVpnNetworkRecoveryManager",
            home,
        )
        self.assertIn(
            'BlueVpnNetworkRecoveryManager.kt": ROOT / "android-source/BlueVpnNetworkRecoveryManager.kt"',
            prepare,
        )

    def test_release_metadata_is_current_and_synchronized(self):
        root = ROOT
        app = json.loads((root / "branding/app.json").read_text(encoding="utf-8"))
        release = json.loads((root / "release.json").read_text(encoding="utf-8"))
        manager_php = (root / "bluevpn-manager/bluevpn-manager.php").read_text(encoding="utf-8")
        manager_readme = (root / "bluevpn-manager/readme.txt").read_text(encoding="utf-8")

        self.assertEqual(app["version_name"], release["version"])
        self.assertEqual(app["version_code"], release["version_code"])
        self.assertEqual(app.get("version_source"), "source_declared_release_version")
        self.assertEqual(release.get("version_source"), "source_declared_release_version")

        parts = [int(x) for x in app["version_name"].split(".")]
        self.assertEqual(app["version_code"], parts[0] * 10000 + parts[1] * 100 + parts[2])
        self.assertIn(f"Version: {app['version_name']}", manager_php)
        self.assertIn(f"Version: {app['version_name']}", manager_readme)
        self.assertIn(f"Stable tag: {app['version_name']}", manager_readme)

    def test_site_version_header_and_runtime_constant_match(self):
        root = ROOT
        style = (root / "bluevpn-site/style.css").read_text(encoding="utf-8")
        functions = (root / "bluevpn-site/functions.php").read_text(encoding="utf-8")
        header = re.search(r"(?mi)^Version:\s*([0-9.]+)\s*$", style)
        runtime = re.search(r"BLUEVPN_SITE_VERSION'\s*,\s*'([0-9.]+)'", functions)
        self.assertIsNotNone(header)
        self.assertIsNotNone(runtime)
        self.assertEqual(header.group(1), runtime.group(1))


class CurrentReleaseTests(unittest.TestCase):
    def test_00_tapsell_distributed_surfaces_and_premium_boundary(self):
        home = text("android-source/BlueVpnHomeActivity.kt")
        carousel = text("android-source/BlueVpnAdsCarouselView.kt")
        servers = text("android-source/BlueVpnServersActivity.kt")
        subscriptions = text("android-source/BlueVpnSubscriptionsActivity.kt")
        support = text("android-source/BlueVpnSupportActivity.kt")
        manager = text("android-source/BlueVpnTapsellManager.kt")
        prepare = text("scripts/prepare_android.py")

        self.assertNotIn("BlueVpnTapsellFreeHub", home)
        self.assertNotIn("BlueVpnTapsellFreeHub.kt", prepare)
        self.assertIn("BlueVpnTapsellManager.showRewarded(", home)
        self.assertIn("زمان باقی‌مانده 🎁", home)

        self.assertIn("tapsellHost", carousel)
        self.assertIn("BlueVpnTapsellManager.attachStandardBanner(", carousel)
        self.assertIn("standard_banner", carousel)

        self.assertIn('type = "native_banner"', servers)
        create_screen = servers.split("private fun createScreen()", 1)[1].split(
            "private fun createHeader()", 1
        )[0]
        self.assertLess(
            create_screen.index("nativeBannerHost"),
            create_screen.index("listContainer ="),
        )

        self.assertIn('type="native_video"', subscriptions)
        self.assertIn('type = "pre_roll_video"', support)
        self.assertIn("BlueVpnEntitlement.resolveUi(this).isFree", support)

        self.assertIn("fun placementEligible(", manager)
        self.assertIn('policy.type != "standard_banner"', manager)

    def test_00_reward_claim_is_server_authoritative_and_idempotent(self):
        api = text("bluevpn-manager/includes/class-bluevpn-api.php")
        db = text("bluevpn-manager/includes/class-bluevpn-db.php")
        account = text("android-source/BlueVpnAccountManager.kt")
        manager = text("android-source/BlueVpnTapsellManager.kt")

        self.assertIn("free/reward/claim", api)
        self.assertIn("free_reward_claims", db)
        self.assertIn("UNIQUE KEY uq_free_reward_event (event_id)", db)
        self.assertIn("$minutes = max(1, min(180", api)
        self.assertNotIn("$body['rewarded_bonus_minutes']", api)
        self.assertIn("'granted_minutes' => $minutes", api)

        self.assertIn("fun claimRewardedBonus(", account)
        self.assertIn('response.optInt("granted_minutes"', account)
        self.assertIn("reward_applied_events", account)
        self.assertIn("UUID.randomUUID()", manager)
        self.assertIn("claimRewardedBonus(", manager)
        self.assertNotIn("rewardMinutes: Int", manager)

    def test_00_all_tapsell_placements_have_independent_panel_switches(self):
        ads = text("bluevpn-manager/includes/class-bluevpn-ads.php")
        db = text("bluevpn-manager/includes/class-bluevpn-db.php")
        for type_name in (
            "rewarded_video",
            "interstitial_video",
            "pre_roll_video",
            "native_video",
            "standard_banner",
            "interstitial_banner",
            "native_banner",
        ):
            self.assertIn("tapsell_" + type_name + "_enabled", ads)
            self.assertIn("tapsell_" + type_name + "_min_interval_seconds", ads)
            self.assertIn("tapsell_" + type_name + "_daily_cap", ads)
            self.assertIn("'tapsell_" + type_name + "_enabled' => true", db)

    def test_00_standard_banner_uses_existing_bluevpn_carousel_slot(self):
        home = text("android-source/BlueVpnHomeActivity.kt")
        carousel = text("android-source/BlueVpnAdsCarouselView.kt")
        self.assertEqual(home.count("BlueVpnAdsCarouselView(this)"), 1)
        self.assertIn("private val tapsellHost = FrameLayout(context)", carousel)
        self.assertIn("showTapsellBanner(activity)", carousel)
        self.assertIn("hideTapsellBanner()", carousel)
        self.assertNotIn("attachStandardBanner(", home)

    def test_00_tapsell_manual_initialize_matches_alpha03(self):
        manager = text("android-source/BlueVpnTapsellManager.kt")
        prepare = text("scripts/prepare_android.py")
        self.assertIn("Tapsell.initialize(context.applicationContext)", manager)
        self.assertIn('TAPSELL_MEDIATION_VERSION = "1.4.0-alpha03"', prepare)
        self.assertIn("ir.tapsell.mediation.AUTO_INIT", prepare)
        self.assertNotIn('TAPSELL_MEDIATION_VERSION = "1.4.0-alpha02"', prepare)

    def test_00_tapsell_has_all_seven_independent_zone_slots(self):
        ads = text("bluevpn-manager/includes/class-bluevpn-ads.php")
        db = text("bluevpn-manager/includes/class-bluevpn-db.php")
        android = text("android-source/BlueVpnTapsellManager.kt")

        expected = {
            "rewarded_video": "tapsell_rewarded_video_zone_id",
            "interstitial_video": "tapsell_interstitial_video_zone_id",
            "pre_roll_video": "tapsell_pre_roll_video_zone_id",
            "native_video": "tapsell_native_video_zone_id",
            "standard_banner": "tapsell_standard_banner_zone_id",
            "interstitial_banner": "tapsell_interstitial_banner_zone_id",
            "native_banner": "tapsell_native_banner_zone_id",
        }

        for api_key, setting_key in expected.items():
            self.assertIn("'" + api_key + "'", ads)
            self.assertIn("'" + setting_key + "'", ads)
            self.assertIn("'" + setting_key + "' => ''", db)
            self.assertIn('"' + api_key + '"', android)

        self.assertIn("'zones' => $zones", ads)
        self.assertIn("'post_connect_type' => $postConnectType", ads)
        self.assertIn("'post_connect_zone_id' => $enabled ? $postConnectZone", ads)
        self.assertIn('placement("interstitial_video")', android)
        self.assertIn('"interstitial_video"', android)
        self.assertIn('"interstitial_banner"', android)

    def test_00_tapsell_is_primary_and_story_is_fallback(self):
        home = text("android-source/BlueVpnHomeActivity.kt")
        flow = home.split("private fun beginFreeStoryGate(", 1)[1].split(
            "private fun maybePromptBackgroundReliability()", 1
        )[0]
        self.assertIn("finalizeSuccessfulConnection(", flow)
        self.assertIn("BlueVpnTapsellManager.onVerifiedConnection(", flow)
        self.assertIn("onUnavailable = {", flow)
        self.assertIn("showFirstPartyFreeStory()", flow)
        self.assertLess(
            flow.index("BlueVpnTapsellManager.onVerifiedConnection("),
            flow.index("showFirstPartyFreeStory()"),
        )

        finalize = home.split("private fun finalizeSuccessfulConnection(", 1)[1].split(
            "private fun refreshVerifiedExitLocation()", 1
        )[0]
        self.assertNotIn("BlueVpnTapsellManager.onVerifiedConnection(", finalize)

    def test_00_tapsell_init_timeout_cannot_silently_block_ads(self):
        manager = text("android-source/BlueVpnTapsellManager.kt")
        self.assertIn("INIT_REQUEST_FALLBACK_MS", manager)
        self.assertIn("initialization_timeout_requesting", manager)
        self.assertIn("continueOnce()", manager)
        self.assertIn("onUnavailable?.invoke()", manager)
        self.assertIn(
            "requestPostConnectWaterfall(activity = current, loaded = loaded, onUnavailable = onUnavailable)",
            manager,
        )

    def test_00_tapsell_mediation_migration_contract(self):
        manager = text("android-source/BlueVpnTapsellManager.kt")
        prepare = text("scripts/prepare_android.py")
        ads = text("bluevpn-manager/includes/class-bluevpn-ads.php")
        bot = text("bluevpn-manager/includes/class-bluevpn-telegram-bot.php")

        self.assertIn("import ir.tapsell.mediation.Tapsell", manager)
        self.assertIn("Tapsell.requestInterstitialAd(", manager)
        self.assertIn("Tapsell.showInterstitialAd(", manager)
        self.assertIn("BuildConfig.BLUEVPN_TAPSELL_APP_ID", manager)
        self.assertNotIn("Class.forName(", manager)
        self.assertNotIn("TapsellPlus", manager)

        self.assertIn("https://maven.tapsell.ir", prepare)
        self.assertIn("ir.tapsell:tapsell:", prepare)
        self.assertIn("ir.tapsell.mediation.adapter:legacy:", prepare)
        self.assertNotIn("ir.tapsell.plus:tapsell-plus-sdk-android:2.3.3", prepare)
        self.assertIn('implementation("androidx.work:work-runtime:2.10.0")', prepare)
        self.assertIn('implementation("com.google.guava:guava:33.6.0-android")', prepare)
        self.assertIn("TapsellMediationAppKey", prepare)
        self.assertIn("com.google.android.gms.permission.AD_ID", prepare)
        self.assertIn("ir.tapsell.mediation.AUTO_INIT", prepare)

        self.assertIn("'sdk' => 'mediation'", ads)
        self.assertIn("'app_id' => $appId", ads)
        self.assertIn("tapsell_app_id", ads)
        self.assertIn("stamp_tapsell_build_config", bot)
        self.assertIn("$raw['tapsell_app_id'] = $appId;", bot)

    def test_00_public_profile_names_never_expose_provider_remarks(self):
        helper = text("android-source/BlueVpnPublicProfileName.kt")
        self.assertIn('private const val BRAND = "BlueVPN"', helper)
        self.assertIn('"ویژه"', helper)
        self.assertIn('"رایگان"', helper)
        self.assertIn('"اتصال هوشمند"', helper)
        self.assertIn("BlueVpnLocationUtil.detect(", helper)
        self.assertNotIn("return profile.remarks", helper)

        prepare = text("scripts/prepare_android.py")
        self.assertIn(
            'bluevpn_dir / "BlueVpnPublicProfileName.kt": ROOT / "android-source/BlueVpnPublicProfileName.kt"',
            prepare,
        )
        self.assertIn(
            ".setContentTitle(BlueVpnPublicProfileName.forProfile(service, currentConfig))",
            prepare,
        )
        self.assertIn("Unsupported v2rayNG NotificationManager title contract", prepare)
        self.assertIn(
            r"\.setContentTitle\(\s*currentConfig\?\.remarks\s*\)",
            prepare,
        )

    def test_00_locations_never_structurally_redraw_on_runtime_broadcasts(self):
        source = text("android-source/BlueVpnServersActivity.kt")

        list_observer = source.split(
            "mainViewModel.updateListAction.observe(this)", 1
        )[1].split("mainViewModel.updateTestResultAction.observe(this)", 1)[0]
        self.assertIn("delayMs = 2_000L", list_observer)
        self.assertNotIn("renderLocations()", list_observer)

        test_observer = source.split(
            "mainViewModel.updateTestResultAction.observe(this)", 1
        )[1].split("renderLocations()", 1)[0]
        self.assertNotIn("invalidateResolvedCache()", test_observer)

        load = source.split("private fun loadCandidates(", 1)[1].split(
            "private fun createScreen()", 1
        )[0]
        self.assertIn("if (listContainer.childCount == 0", load)

        fingerprint = source.split(
            "private fun locationStructureFingerprint(", 1
        )[1].split("private fun renderLocations()", 1)[0]
        self.assertNotIn("isSessionInactive", fingerprint)
        self.assertNotIn("getSelectServer", fingerprint)
        self.assertNotIn("preferredLocation", fingerprint)

        self.assertIn("appendChunkRunnable?.let { renderHandler.removeCallbacks(it) }", source)

    def test_00_network_observer_is_crash_safe(self):
        source = text("android-source/BlueVpnNetworkRecoveryManager.kt")
        self.assertIn("try {", source)
        self.assertIn("cm.registerDefaultNetworkCallback(cb)", source)
        self.assertIn("catch (_: Throwable)", source)
        self.assertGreaterEqual(source.count("runCatching {"), 2)

    def test_00_location_scroll_and_network_recovery_regressions(self):
        servers = text("android-source/BlueVpnServersActivity.kt")
        self.assertIn("lastRenderedStructureFingerprint", servers)
        self.assertIn("locationStructureFingerprint", servers)
        self.assertIn("locationsScrollView.scrollY", servers)
        self.assertIn("locationsScrollView.scrollTo(0, preservedScrollY)", servers)
        self.assertIn("if (nextFingerprint != lastRenderedStructureFingerprint)", servers)

        recovery = text("android-source/BlueVpnNetworkRecoveryManager.kt")
        self.assertNotIn("BlueVpnWarpKeepAliveService.requestNetworkRecovery(", recovery)

        keepalive = text("android-source/BlueVpnWarpKeepAliveService.kt")
        recovery_api = keepalive.split("fun requestNetworkRecovery", 1)[1].split("private val handler", 1)[0]
        self.assertNotIn("BlueVpnSystemController.restart(app)", recovery_api)

    def test_00_repository_hygiene_bot_contract(self):
        bot = text("bluevpn-manager/includes/class-bluevpn-telegram-bot.php")
        self.assertIn("private static function repository_junk_path", bot)
        self.assertIn("private static function repository_authoritative_files", bot)
        self.assertIn("self::is_full_platform_root($root)", bot)
        self.assertIn("!isset($authoritative[$remotePath])", bot)
        root_names = {p.name for p in ROOT.iterdir()}
        self.assertIn("README.md", root_names)
        self.assertFalse(any(name.startswith("BUILD-AND-TEST-") for name in root_names))
        self.assertFalse(any(name.startswith("CHANGED-FILES-") for name in root_names))

        # GitHub Actions legitimately creates reports/, *.log and cache folders
        # after checkout. Repository hygiene is a tracking policy, not a ban on
        # runtime CI artifacts, so assert the ignore contract instead.
        gitignore = text(".gitignore")
        for ignored in (
            "reports/",
            ".pytest_cache/",
            "**/__pycache__/",
            "*.log",
            "BUILD-AND-TEST-*.md",
            "CHANGED-FILES-*.md",
            "ROOT-CAUSE-*.md",
        ):
            self.assertIn(ignored, gitignore)

    def test_00_locations_ping_updates_do_not_rebuild_list(self):
        source = text("android-source/BlueVpnServersActivity.kt")
        observer = source.split("mainViewModel.updateTestResultAction.observe(this)", 1)[1].split("renderLocations()", 1)[0]
        self.assertIn("healthRefreshRunnable", observer)
        self.assertNotIn("listContainer.removeAllViews()", observer)
        self.assertIn("private fun refreshVisibleHealthPresentation()", source)
        self.assertIn("healthStatusViews[group.location.key] = availabilityView", source)

    @classmethod
    def setUpClass(cls):
        cls.app = json.loads(text("branding/app.json"))
        cls.release = json.loads(text("release.json"))
        cls.home = text("android-source/BlueVpnHomeActivity.kt")
        cls.account = text("android-source/BlueVpnAccountManager.kt")
        cls.location = text("android-source/BlueVpnLocationUtil.kt")
        cls.subscription = text("android-source/BlueVpnSubscriptionIntelligence.kt")
        cls.prepare = text("scripts/prepare_android.py")
        cls.workflow = text(".github/workflows/build-apk.yml")
        cls.profile = text("android-source/BlueVpnProfileManager.kt")
        cls.warp = text("android-source/BlueVpnWarpEngine.kt")
        cls.aether_build = text("scripts/build_aether_android.py")

    def test_01_version(self):
        version = self.app["version_name"]
        match = re.fullmatch(r"(\d+)\.(\d+)\.(\d+)", version)
        self.assertIsNotNone(match)
        major, minor, patch = map(int, match.groups())
        self.assertLessEqual(patch, 99)
        self.assertEqual(self.app["version_code"], major * 10000 + minor * 100 + patch)

    def test_02_release_version(self):
        self.assertEqual(self.release["version"], self.app["version_name"])
        self.assertEqual(self.release["version_code"], self.app["version_code"])
        self.assertEqual(self.release["android_version"], self.app["version_name"])
        self.assertEqual(self.release["android_version_code"], self.app["version_code"])

    def test_03_official_pairing(self):
        self.assertEqual(self.app["upstream_ref"], "2.3.5")
        self.assertEqual(self.app["android_lib_xray_ref"], "v26.7.28")
        self.assertEqual(self.app["xray_core_release_label"], "v26.7.28")
        self.assertNotIn("xray_ref", self.app)
        self.assertNotIn("sing_box_ref", self.app)
        self.assertEqual(self.app.get("free_engine"), "aether-warp-primary")
        self.assertEqual(self.app.get("aether_ref"), "a26159b82a70048b459e0128213c71767abecb8a")

    def test_04_direct_stock_start_stop(self):
        self.assertIn("LauncherManager.startService(this, guid)", self.home)
        self.assertIn("LauncherManager.stopService", self.home)
        self.assertNotIn("BlueVpnEngineManager", self.home + self.account)

    def test_05_alternate_engine_files_removed(self):
        for rel in (
            "android-source/BlueVpnEngineManager.kt",
            "android-source/BlueVpnSingBoxProcess.kt",
            "android-source/BlueVpnSingBoxProfileCompiler.kt",
            "android-source/BlueVpnAiActivity.kt",
        ):
            self.assertFalse((ROOT / rel).exists(), rel)

    def test_05b_free_aether_warp_engine_is_pinned_and_isolated(self):
        self.assertIn("beginWarpFreeConnection()", self.home)
        self.assertIn("BlueVpnWarpEngine.isBridgeGuid", self.home)
        self.assertIn("import com.v2ray.ang.bluevpn.BlueVpnWarpEngine", self.home)
        self.assertIn('BRIDGE_SUBSCRIPTION_ID = "bluevpn_free_warp_aether"', self.warp)
        self.assertIn("No free loopback port in", self.warp)
        self.assertIn("--quick-reconnect", self.warp)
        self.assertIn("--no-quick-reconnect", self.warp)
        self.assertIn("MASQUE_H2_FRAGMENT", self.warp)
        self.assertIn("socksGreetingAndRemoteConnect", self.warp)
        self.assertIn("warp=plus", self.warp)
        self.assertIn("warpFallbackGeneration", self.home)
        self.assertNotIn("warpFallbackUntilElapsed", self.home)
        self.assertIn('AETHER_COMMIT = "a26159b82a70048b459e0128213c71767abecb8a"', self.aether_build)
        self.assertIn("Build pinned Aether WARP runtime", self.workflow)
        self.assertNotIn("dtolnay/rust-toolchain@stable", self.workflow)
        self.assertIn('rustup target add "$target"', self.workflow)
        self.assertIn("libbluevpn_aether.so", self.aether_build)

    def test_05c_premium_runtime_stays_stock_v2rayng(self):
        self.assertIn("LauncherManager.startService(this, guid)", self.home)
        self.assertNotIn("BlueVpnEngineManager", self.home + self.account)
        self.assertNotIn("BlueVpnSingBox", self.home + self.account)


    def test_05d_warp_free_entitlement_is_independent_of_legacy_pool(self):
        entitlement = text("android-source/BlueVpnEntitlement.kt")
        ads = text("bluevpn-manager/includes/class-bluevpn-ads.php")
        db = text("bluevpn-manager/includes/class-bluevpn-db.php")
        api = text("bluevpn-manager/includes/class-bluevpn-api.php")
        self.assertIn('val warpEligible = free.warpEnabled', entitlement)
        self.assertIn('warpReadyByPolicy = snapshot.warpEnabled', self.account)
        self.assertIn("'free_warp_enabled' => true", db)
        self.assertIn("'warp_fallback_pool'", ads)
        self.assertIn("'primary'=>'aether_warp'", api)
        self.assertIn('warpFreeEnabled(this)', self.home)

    def test_06_no_alternate_core_ci(self):
        self.assertNotIn("SagerNet/sing-box", self.workflow)
        self.assertNotIn("GFW-knocker/AndroidLibXrayLite", self.workflow)
        self.assertNotIn("mahsa-canary", self.workflow)
        self.assertNotIn("actions/setup-go", self.workflow)

    def test_07_no_runtime_or_parser_patch_functions(self):
        self.assertNotIn("patch_v2rayng_runtime_lifecycle", self.prepare)
        self.assertNotIn("patch_shadowsocks_transport_queries", self.prepare)

    def test_08_runtime_hash_guard_exists(self):
        self.assertIn("UPSTREAM_RUNTIME_GUARD", self.prepare)
        for rel in (
            "core/CoreServiceManager.kt",
            "core/CoreConfigManager.kt",
            "service/CoreVpnService.kt",
            "viewmodel/MainViewModel.kt",
            "handler/AngConfigManager.kt",
        ):
            self.assertIn(rel, self.prepare)
        self.assertIn("runtime_snapshot = snapshot_upstream_runtime()", self.prepare)
        self.assertIn("assert_upstream_runtime_unchanged(runtime_snapshot)", self.prepare)

    def test_09_overlay_does_not_replace_protected_runtime(self):
        overrides = block(self.prepare, "plain_overrides = {", "    for target, source in plain_overrides.items()")
        for filename in (
            "CoreServiceManager.kt",
            "CoreConfigManager.kt",
            "CoreVpnService.kt",
            "MainViewModel.kt",
            "AngConfigManager.kt",
        ):
            self.assertNotIn(filename, overrides)

    def test_10_official_checkout(self):
        self.assertIn("repository: 2dust/v2rayNG", self.workflow)
        self.assertIn("ref: ${{ steps.config.outputs.upstream_ref }}", self.workflow)
        self.assertIn("Overlay BlueVPN UI and control plane on v2rayNG", self.workflow)

    def test_11_core_submodule_not_moved(self):
        self.assertNotIn('git checkout --force "$XRAY_REF"', self.workflow)
        self.assertIn('CURRENT_COMMIT="$(git rev-parse HEAD)"', self.workflow)
        self.assertIn('CURRENT_TAG="$(git describe --tags --abbrev=0', self.workflow)
        self.assertIn('DOCUMENTED_TAG="${{ steps.config.outputs.android_lib_xray_ref }}"', self.workflow)
        self.assertIn('XRAY_RELEASE_LABEL="${{ steps.config.outputs.xray_core_release_label }}"', self.workflow)
        self.assertNotIn('PINNED_COMMIT="$(git rev-list -n 1', self.workflow)
        self.assertNotIn('AndroidLibXrayLite release tag mismatch', self.workflow)
        self.assertIn('Building with the upstream-resolved tag', self.workflow)

    def test_12_immediate_stock_receiver_and_assets(self):
        listen = self.home.index("mainViewModel.startListenBroadcast()")
        assets = self.home.index("mainViewModel.initAssets(assets)")
        pipeline = self.home.index("scheduleStartupPipeline()")
        ads = self.home.index("BlueVpnTapsellManager.warmUp(this)")
        self.assertLess(listen, pipeline)
        self.assertLess(assets, pipeline)
        self.assertLess(listen, ads)
        self.assertLess(assets, ads)

    def test_13_real_start_failure_is_consumed(self):
        self.assertIn("AppConfig.MSG_STATE_START_FAILURE", self.home)
        self.assertIn('intent.getStringExtra("content")', self.home)
        self.assertIn("failCurrentAndTryNext(reason)", self.home)

    def test_14_no_authoritative_preflight(self):
        start = block(self.home, "private fun startCurrentCandidate", "private fun startExactCandidateCore")
        self.assertNotIn("preflightCandidate(", start)
        self.assertNotIn("validateExactConfig", self.home)

    def test_15_exact_handoff_has_no_duplicate_select_write(self):
        exact = block(self.home, "private fun startExactCandidateCore", "private fun scheduleConnectionVerification")
        self.assertIn("LauncherManager.startService(this, guid)", exact)
        self.assertNotIn("MmkvManager.setSelectServer(guid)", exact)
        self.assertIn(
            "BlueVpnNetworkRecoveryManager.policy(this).candidateStartTimeoutMs",
            exact,
        )
        recovery = (ROOT / "android-source/BlueVpnNetworkRecoveryManager.kt").read_text(encoding="utf-8")
        self.assertIn("coerceIn(6_000L, 20_000L)", recovery)

    def test_16_location_util_does_not_reject_v2rayng_profiles(self):
        usable = block(self.location, "fun isUsable(", "fun invalidateCache()")
        self.assertIn("return true", usable)
        self.assertNotIn("server.isBlank", usable)
        self.assertNotIn("127.0.0.1", usable)

    def test_17_candidate_catalog_has_no_semantic_dedupe(self):
        self.assertNotIn("BlueVpnProfileManager.fingerprint", self.location)
        self.assertIn("Preserve every imported GUID", self.location)

    def test_18_stock_subscription_update_path(self):
        self.assertIn("AngConfigManager.updateConfigViaSub(row)", self.subscription)
        self.assertNotIn("compatibilityUserAgents", self.subscription)
        self.assertNotIn("Clash.Meta", self.subscription)
        self.assertNotIn('BlueVPN/${BuildConfig.VERSION_NAME}', self.subscription)

    def test_19_managed_subscription_uses_stock_ua(self):
        self.assertIn("userAgent = null", self.account)

    def test_20_location_mode_and_reserve_pool_preserved(self):
        self.assertIn("BlueVpnSelectionMode.MANUAL_LOCATION", self.home)
        self.assertIn("failoverReserveQueue", self.home)
        self.assertIn("val initialBatchSize = 8", self.home)

    def test_21_no_ai_runtime_controller(self):
        self.assertNotIn("private fun runSmartSelection()", self.home)
        self.assertNotIn("private fun monitorBlueAiHealth()", self.home)

    def test_22_only_product_mode_setting_is_forced(self):
        self.assertEqual(len(re.findall(r"MmkvManager\.encodeSettings\(", self.home)), 1)
        self.assertIn('MmkvManager.encodeSettings(AppConfig.PREF_MODE, "VPN")', self.home)

    def test_23_profile_no_engine_route(self):
        self.assertNotIn("EngineRoute", self.profile)
        self.assertNotIn("SING_BOX_JSON", self.profile)

    def test_24_profile_fingerprint_still_supports_selection_restore(self):
        self.assertIn('append("rawjson=")', self.profile)

    def test_25_plugin_version(self):
        version = re.escape(self.app["version_name"])
        self.assertRegex(text("bluevpn-manager/bluevpn-manager.php"), rf"Version:\s*{version}")
        self.assertRegex(text("bluevpn-manager/readme.txt"), rf"Stable tag:\s*{version}")

    def test_26_short_semver(self):
        self.assertLessEqual(int(self.app["version_name"].split(".")[2]), 99)

    def test_27_no_old_release_number(self):
        for rel in ("branding/app.json", "release.json", "README.md", "NOTICE.md"):
            self.assertNotIn("4.1.7", text(rel))

    def test_28_first_resume_is_local_only(self):
        self.assertIn("private var firstHomeResume = true", self.home)
        resume = block(self.home, "override fun onResume()", "private fun scheduleStartupPipeline")
        self.assertIn("val initialResume = firstHomeResume", resume)
        self.assertIn("if (!initialResume)", resume)

    def test_29_premium_startup_does_not_scan_free_pool(self):
        pipeline = block(self.home, "private fun scheduleStartupPipeline", "private fun scheduleIdleCandidateWarmup")
        self.assertIn("val premiumAtLaunch = BlueVpnAccountManager.premiumEntitlementActive", pipeline)
        self.assertIn("val needsFreeBootstrap = !premiumAtLaunch", pipeline)
        self.assertIn("val preparedFree = if (needsFreeBootstrap)", pipeline)
        self.assertNotIn("val hasFreeServer =", pipeline)

    def test_30_dashboard_uses_ui_safe_entitlement_check(self):
        dashboard = block(self.home, "private fun refreshDashboard", "private fun readTunnelTrafficBytes")
        self.assertIn("selectedServerAllowedUi", dashboard)
        self.assertNotIn("selectedServerAllowed(this)", dashboard)
        self.assertIn("fun selectedServerAllowedUi(", self.account)

    def test_31_hidden_compatibility_ui_does_no_ai_work(self):
        experience = block(self.home, "private fun refreshExperienceDashboard", "private fun recordCurrentConnection")
        self.assertIn("compatibilityParentVisible", experience)
        self.assertIn("qualityValue.visibility != View.VISIBLE", experience)

    def test_32_subscription_render_has_no_disk_clear(self):
        info = block(self.home, "private fun refreshSubscriptionInfo", "private fun formatAccountRemainingTime")
        self.assertNotIn('getSharedPreferences("bluevpn_subscription_info"', info)


    def test_33_sms_otp_timeout_budget_returns_provider_error_before_android_timeout(self):
        self.assertIn('val otpRequest = method == "POST"', self.account)
        self.assertIn('otpRequest -> 30_000', self.account)
        sms = text("bluevpn-manager/includes/class-bluevpn-sms-otp.php")
        self.assertIn("'timeout' => 10", sms)
        self.assertIn("provider_transport_failure", sms)
        self.assertIn("record_provider_health", sms)

    def test_34_sms_otp_api_never_falls_back_to_html_fatal(self):
        api = text("bluevpn-manager/includes/class-bluevpn-api.php")
        self.assertIn("private static function unexpected(Throwable $e,string $scope)", api)
        self.assertIn("catch(Throwable $e){return self::unexpected($e,'otp_request');}", api)
        self.assertIn("catch(Throwable $e){ return self::unexpected($e,'bind_phone_otp_request'); }", api)

    def test_35_sms_notification_provider_budget_is_bounded(self):
        sms = text("bluevpn-manager/includes/class-bluevpn-sms-notifications.php")
        self.assertIn("'timeout'=>10", sms)
        self.assertIn("record_provider_health", sms)

    def test_36_sms_pattern_sync_uses_official_endpoint_and_api_key_header(self):
        sms = text("bluevpn-manager/includes/class-bluevpn-sms-otp.php")
        self.assertIn("provider_pattern_page_url", sms)
        self.assertIn("'/patterns?page='", sms)
        self.assertIn("'method' => 'GET'", sms)
        self.assertIn("'Api-Key' => $apiKey", sms)
        self.assertIn("PATTERN_CACHE_OPTION", sms)

    def test_37_sms_pattern_sync_is_php84_safe_and_handles_nested_payloads(self):
        sms = text("bluevpn-manager/includes/class-bluevpn-sms-otp.php")
        refresh = block(sms, "public static function refresh_patterns", "public static function active_pattern_codes")
        self.assertIn("provider_pattern_page_url($base, $page, $limit)", refresh)
        self.assertIn("'method' => 'GET'", refresh)
        self.assertNotIn("add_query_arg(", refresh)
        self.assertNotIn("'share' => 1", refresh)
        self.assertNotIn("'body' =>", refresh)
        self.assertIn("/patterns/", refresh)
        self.assertIn("rawurlencode($configuredCode)", refresh)
        self.assertIn("provider_pattern_candidates", sms)
        self.assertIn("json_decode($trimmed, true)", sms)

    def test_38_sms_provider_string_error_status_is_rejected(self):
        sms = text("bluevpn-manager/includes/class-bluevpn-sms-otp.php")
        self.assertIn("['error','failed','fail','rejected']", sms)
        self.assertIn("PATTERN_SYNC_PROVIDER_REJECTED", sms)

    def test_39_sms_admin_uses_synced_pattern_dropdowns(self):
        cc = text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        self.assertIn("bluevpn_cc_refresh_sms_patterns", cc)
        self.assertIn("sms_pattern_select", cc)
        self.assertIn("تازه‌سازی پترن‌ها", cc)
        self.assertNotIn("placeholder=\"Pattern UID\"", cc)

    def test_40_stale_patterns_are_reconciled_and_otp_variable_is_mapped(self):
        sms = text("bluevpn-manager/includes/class-bluevpn-sms-otp.php")
        cc = text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        self.assertIn("preferred_otp_parameter", sms)
        self.assertIn("SMS_PATTERN_INACTIVE", sms)
        self.assertIn("reconcile_sms_pattern_selections", cc)
        self.assertIn("active_pattern_codes", cc)

    def test_41_runtime_freeze_survives_sms_pattern_release(self):
        self.assertIn("LauncherManager.startService(this, guid)", self.home)
        self.assertNotIn("BlueVpnEngineManager", self.home + self.account)
        self.assertEqual(self.app["upstream_ref"], "2.3.5")


    def test_42_site_theme_version_and_updater_are_synchronized(self):
        style = text("bluevpn-site/style.css")
        functions = text("bluevpn-site/functions.php")
        updater = text("bluevpn-site/inc/class-bluevpn-site-updater.php")
        m = re.search(r"(?m)^Version:\s*(\d+\.\d+\.\d+)\s*$", style)
        self.assertIsNotNone(m)
        version = m.group(1)
        self.assertRegex(version, r"^\d+\.\d+\.\d+$")
        self.assertIn(f"BLUEVPN_SITE_VERSION', '{version}", functions)
        self.assertIn("BlueVPN_Site_Updater::init();", functions)
        self.assertIn("pre_set_site_transient_update_themes", updater)
        self.assertIn("Theme_Upgrader", updater)

    def test_43_site_theme_uses_independent_release_asset_contract(self):
        updater = text("bluevpn-site/inc/class-bluevpn-site-updater.php")
        self.assertIn("bluevpn-site-theme-v", updater)
        self.assertIn("releases?per_page=100", updater)
        self.assertIn("BlueVPN_GitHub_Updater", updater)
        self.assertIn("BlueVPN_Telegram_Bot", updater)

    def test_44_site_theme_background_auto_update_is_enabled(self):
        updater = text("bluevpn-site/inc/class-bluevpn-site-updater.php")
        self.assertIn("bluevpn_two_minutes", updater)
        self.assertIn("background_update_check", updater)
        self.assertIn("maybe_kick_background_check", updater)
        self.assertIn("auto_update_theme", updater)

    def test_45_site_theme_release_workflow_is_decoupled_from_android(self):
        workflow = text(".github/workflows/bluevpn-site-theme-release.yml")
        self.assertIn("Release BlueVPN Site Theme", workflow)
        self.assertIn("bluevpn-site-theme-v${THEME_VERSION}.zip", workflow)
        self.assertIn("bluevpn-site-v${THEME_VERSION}", workflow)
        self.assertNotIn("gradlew", workflow)
        self.assertNotIn("v2rayNG", workflow)

    def test_46_site_theme_release_requires_version_bump(self):
        workflow = text(".github/workflows/bluevpn-site-theme-release.yml")
        self.assertIn("Enforce theme version bump on source changes", workflow)
        self.assertIn("changed without a theme version bump", workflow)
        self.assertIn("patch must stay within x.y.0..x.y.10", workflow)


    def test_47_site_theme_redesign_contract(self):
        front = text("bluevpn-site/front-page.php")
        home = text("bluevpn-site/inc/home-v2.php")
        css = text("bluevpn-site/assets/css/site.css")
        self.assertIn("inc/home-v2.php", front)
        self.assertIn("bv5-hero", home)
        self.assertIn("bv5-feature-row", home)
        self.assertIn("bv5-locations-layout", home)
        self.assertIn("bv5-plan-cards", home)
        self.assertIn("bv5-faq", home)
        self.assertNotIn("BlueAI", home)
        self.assertNotIn("بخش AI", home)
        self.assertIn(".bv5-hero", css)
        self.assertIn(".bv5-locations-layout", css)
        self.assertIn(".bv5-plan-cards", css)
        self.assertIn(".bv-account-page", css)

    def test_repository_cleanup_handles_overlay_stale_files(self):
        cleanup = (ROOT / "scripts/cleanup_repository.py").read_text(encoding="utf-8")
        workflow = (ROOT / ".github/workflows/build-apk.yml").read_text(encoding="utf-8")
        for token in (
            "BlueVpnEngineManager.kt",
            "BlueVpnSingBoxProcess.kt",
            "BlueVpnSingBoxProfileCompiler.kt",
            "BlueVpnAiActivity.kt",
            "android-source/generated",
        ):
            self.assertIn(token, cleanup)
        self.assertIn("Remove retired BlueVPN runtime files", workflow)
        self.assertIn("python scripts/cleanup_repository.py", workflow)
        self.assertIn("release_test_manifest.json", cleanup)
        self.assertIn("glob(\"test_*.py\")", cleanup)
        self.assertIn("path.name not in approved", cleanup)


    def test_49_release_validator_has_no_hardcoded_app_version(self):
        validator = text("scripts/validate_release.py")
        self.assertNotRegex(validator, r'app\["version_name"\]\s*==\s*"\d+\.\d+\.\d+"')
        self.assertNotRegex(validator, r'app\["version_code"\]\s*==\s*\d+')
        self.assertIn('expected_version_code = major * 10000 + minor * 100 + patch', validator)
        self.assertIn('plugin_header.group(1) == version', validator)
        self.assertIn('stable_tag.group(1) == version', validator)


    def test_web_login_does_not_consume_vpn_device_slot(self):
        auth = text("bluevpn-manager/includes/class-bluevpn-auth.php")
        db = text("bluevpn-manager/includes/class-bluevpn-db.php")
        self.assertIn("client_type", auth)
        self.assertIn("client_type='app'", auth)
        self.assertIn("$client_type === 'app'", auth)
        self.assertIn("device_id LIKE 'web-%'", db)
        self.assertIn("client_type varchar(16)", db)

    def test_website_otp_advances_optimistically(self):
        js = text("bluevpn-site/assets/js/site.js")
        self.assertIn("در حال ارسال کد تأیید", js)
        self.assertIn("otpReady=false", js)
        self.assertIn("verifyBtn.disabled=true", js)
        self.assertIn("otpRequestSeq", js)

    def test_campaign_banner_is_cache_first_and_not_delayed_by_ad_sdk(self):
        ads = text("android-source/BlueVpnAdsCarouselView.kt")
        theme = text("android-source/BlueVpnTheme.kt")
        home = text("android-source/BlueVpnHomeActivity.kt")
        self.assertIn('"bluevpn_ads_carousel_cache"', ads)
        self.assertIn('File(context.applicationContext.cacheDir, "bluevpn_ads")', ads)
        self.assertIn("hydrateCachedConfig()", ads)
        self.assertIn("readDiskBitmap(url)", ads)
        self.assertIn("writeDiskBytes(url, downloaded.bytes)", ads)
        self.assertIn("prefetchUpcomingImages()", ads)
        self.assertIn("downloadBitmapWithRetry(url)", ads)
        self.assertIn('connection.setRequestProperty("Accept-Encoding", "identity")', ads)
        self.assertIn("connection.useCaches = !forceNetwork", ads)
        self.assertNotIn("showPlaceholder()", ads)
        self.assertIn("fun bannerDelayMs", theme)
        self.assertIn("fun adSdkWarmupDelayMs", theme)
        self.assertIn("BlueVpnPerformance.bannerDelayMs(this)", home)
        self.assertIn("BlueVpnPerformance.adSdkWarmupDelayMs(this)", home)


    def test_campaign_assets_prefer_static_upload_urls_and_decode_healthcheck(self):
        ads_php = text("bluevpn-manager/includes/class-bluevpn-ads.php")
        admin_php = text("bluevpn-manager/includes/class-bluevpn-admin.php")
        self.assertIn("static_asset_url_for_row", ads_php)
        self.assertIn("/bluevpn-ads/", ads_php)
        self.assertIn("wp_upload_dir", ads_php)
        self.assertIn("Do not send Content-Length from PHP", ads_php)
        self.assertIn("getimagesizefromstring", admin_php)

    def test_campaign_banner_does_not_touch_stock_v2rayng_runtime(self):
        ads = text("android-source/BlueVpnAdsCarouselView.kt")
        home = text("android-source/BlueVpnHomeActivity.kt")
        self.assertNotIn("CoreServiceManager", ads)
        self.assertIn("LauncherManager.startService(this, guid)", home)

    def test_public_theme_hides_internal_project_implementation_copy(self):
        public = "\n".join(text(p) for p in [
            "bluevpn-site/front-page.php",
            "bluevpn-site/page-plans.php",
            "bluevpn-site/page-download.php",
            "bluevpn-site/page-account.php",
            "bluevpn-site/page-support.php",
            "bluevpn-site/footer.php",
            "bluevpn-site/header.php",
        ])
        for term in ("GitHub", "hazhanhasani", "BlueVPN Manager", "v2rayNG Runtime", "Xray", "BluePay", "Build #", "WordPress"):
            self.assertNotIn(term, public)


    def test_web_account_uses_canonical_subscription_contract(self):
        js = text("bluevpn-site/assets/js/site.js")
        auth = text("bluevpn-manager/includes/class-bluevpn-auth.php")
        page = text("bluevpn-site/page-account.php")
        self.assertIn("function subscriptionOf(account)", js)
        self.assertIn("sub.entitlement_plan_id||sub.plan_id", js)
        self.assertIn("a.phone_display||a.display_identity||a.phone", js)
        self.assertNotIn("a.entitlement?.active", js)
        self.assertIn("'plan_title' => $planTitle", auth)
        self.assertIn("'current_plan' => $currentPlan", auth)
        self.assertIn("data-current-plan-title", page)

    def test_authenticated_account_hides_login_marketing_and_uses_unified_dashboard(self):
        js = text("bluevpn-site/assets/js/site.js")
        css = text("bluevpn-site/assets/css/site.css")
        page = text("bluevpn-site/page-account.php")
        self.assertIn("classList.add('is-authenticated')", js)
        self.assertIn(".bv-account-layout.is-authenticated .bv-account-intro{display:none}", css)
        self.assertIn("bv-current-subscription", page)
        self.assertIn("bv-dashboard-plans", page)

    def test_site_uses_real_mobile_responsive_viewport(self):
        header = text("bluevpn-site/header.php")
        self.assertIn('name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"', header)

    def test_logout_cannot_resurrect_premium_session(self):
        self.assertIn("private val authSessionEpoch = AtomicLong(0L)", self.account)
        logout = block(self.account, "fun logout(c: Context)", "private fun invalidateSession")
        self.assertIn("authSessionEpoch.incrementAndGet()", logout)
        self.assertIn("enforceFreeBoundaryTransition(appContext)", logout)
        boundary = block(self.account, "private fun enforceFreeBoundaryTransition", "fun logout")
        self.assertIn("reconcileSubscriptionMode(", boundary)
        self.assertIn("premiumActive = false", boundary)
        self.assertIn("prepareFreeAccess(appContext, force = true)", boundary)
        refresh = block(self.account, "private fun refreshSession", "private fun authenticatedRequest")
        self.assertIn("expectedAuthEpoch", refresh)
        self.assertIn("expectedEpoch = expectedAuthEpoch", refresh)
        self.assertIn("!persisted", refresh)
        sync = block(self.account, "fun sync(", "fun plans(")
        self.assertIn("AUTH_SESSION_CHANGED", sync)
        self.assertIn("expectedAuthEpoch = expectedAuthEpoch", sync)

    def test_premium_requires_live_session_and_free_pool_is_reenabled(self):
        entitlement = text("android-source/BlueVpnEntitlement.kt")
        self.assertIn("BlueVpnAccountManager.premiumEntitlementActive(context)", entitlement)
        self.assertIn("fun premiumEntitlementActive(c: Context)", self.account)
        self.assertIn("hasSession(c) && active(c)", self.account)
        free_ready = block(self.account, "fun hasInstalledFreeServers", "fun prepareFreeAccess")
        self.assertIn("it.subscription.enabled", free_ready)
        self.assertIn("it.subscription.remarks.startsWith(FREE_SUB)", free_ready)
        self.assertIn("cached-but-disabled pool as not ready", free_ready)
        self.assertIn("!premiumEntitlementActive(c) && freeAccessEnabled(c)", self.account)

    def test_logout_invalidates_inflight_premium_candidate_generation(self):
        launcher = block(self.home, "private val accountLauncher", "private enum class OrbVisualState")
        self.assertIn("connectionPreparationGeneration += 1", launcher)
        self.assertIn("cancelFailover()", launcher)

    def test_mobile_config_uses_real_ads_and_free_payload_methods(self):
        api = text("bluevpn-manager/includes/class-bluevpn-api.php")
        ads = text("bluevpn-manager/includes/class-bluevpn-ads.php")
        self.assertIn("BlueVPN_Ads::advertising_payload($s, $r)", api)
        self.assertIn("BlueVPN_Ads::free_access_payload($s)", api)
        self.assertIn("public static function public_config", ads)
        self.assertIn("public static function free_public_config", ads)

    def test_free_session_policy_refreshes_independent_of_pool_readiness(self):
        update = text("android-source/BlueVpnUpdateManager.kt")
        self.assertIn("fun applyRemoteMobileConfig(c: Context, config: JSONObject): Boolean", self.account)
        self.assertIn("fun refreshFreePolicy(c: Context, force: Boolean = false)", self.account)
        self.assertIn("newMinutes < oldMinutes", self.account)
        self.assertIn('.putLong("session_ends_at", allowedEnd)', self.account)
        self.assertIn("refreshFreePolicy(", self.home)
        self.assertIn("force = true", self.home)
        self.assertIn("BlueVpnAccountManager.mobileConfig(", update)
        mobile = block(self.account, "fun mobileConfig", "/**\n     * Apply server-authored Free policy")
        self.assertIn("applyRemoteMobileConfig(appContext, response)", mobile)


class BlueVPNElementorThemeTests(unittest.TestCase):
    def test_elementor_native_theme_contract(self):
        integration = text("bluevpn-site/inc/class-bluevpn-elementor.php")
        widgets = text("bluevpn-site/inc/elementor/widgets.php")
        functions = text("bluevpn-site/functions.php")
        self.assertIn("elementor/widgets/register", integration)
        self.assertIn("elementor/theme/register_locations", integration)
        self.assertIn("register_all_core_location", integration)
        self.assertIn("_elementor_data", integration)
        self.assertIn("elementor_library", integration)
        self.assertIn("BlueVPN_Elementor_Integration::init();", integration)
        self.assertIn("class-bluevpn-elementor.php", functions)
        self.assertIn("has_meaningful_output", integration)
        self.assertIn("ob_start()", integration)
        self.assertIn("get_builder_content_for_display($post_id, true)", integration)
        for widget in (
            "bluevpn-header", "bluevpn-footer", "bluevpn-hero", "bluevpn-features",
            "bluevpn-network", "bluevpn-how", "bluevpn-premium", "bluevpn-faq",
            "bluevpn-plans", "bluevpn-download", "bluevpn-account", "bluevpn-support",
        ):
            self.assertIn(widget, widgets)

    def test_elementor_pages_keep_legacy_fallback_and_runtime_contract(self):
        for rel in ("bluevpn-site/front-page.php", "bluevpn-site/page-plans.php", "bluevpn-site/page-download.php", "bluevpn-site/page-account.php", "bluevpn-site/page-support.php"):
            src = text(rel)
            self.assertIn("BlueVPN_Elementor_Integration::render_page", src)
            self.assertIn("get_header()", src)
            self.assertIn("get_footer()", src)
        header = text("bluevpn-site/header.php")
        footer = text("bluevpn-site/footer.php")
        self.assertIn("render_location('header')", header)
        self.assertIn("render_location('footer')", footer)
        self.assertEqual(json.loads(text("branding/app.json"))["upstream_ref"], "2.3.5")


class BlueVPNSiteSEOTests(unittest.TestCase):
    def test_seo_hardening_is_loaded_and_versioned(self):
        functions = text("bluevpn-site/functions.php")
        style = text("bluevpn-site/style.css")
        version = json.loads(text("release.json"))["version"]
        self.assertIn("class-bluevpn-seo.php", functions)
        self.assertIn(f"BLUEVPN_SITE_VERSION', '{version}", functions)
        self.assertRegex(style, rf"(?m)^Version:\s*{re.escape(version)}\s*$")

    def test_private_account_is_noindex_and_excluded_from_sitemaps(self):
        seo = text("bluevpn-site/inc/class-bluevpn-seo.php")
        self.assertIn("'account' => [", seo)
        self.assertIn("'index' => false", seo)
        self.assertIn("wp_sitemaps_posts_query_args", seo)
        self.assertIn("wpseo_exclude_from_sitemap_by_post_ids", seo)
        self.assertIn("$robots['noindex'] = true", seo)

    def test_seo_meta_social_and_schema_contract(self):
        seo = text("bluevpn-site/inc/class-bluevpn-seo.php")
        for token in (
            'meta name="description"', 'rel="canonical"', 'og:title', 'twitter:card',
            'SoftwareApplication', 'FAQPage', 'Organization', 'WebSite', 'BreadcrumbList',
        ):
            self.assertIn(token, seo)
        self.assertTrue((ROOT / "bluevpn-site/assets/images/bluevpn-social.png").exists())
        self.assertTrue((ROOT / "bluevpn-site/assets/images/bluevpn-icon.png").exists())

    def test_robots_and_llms_endpoints_are_managed(self):
        seo = text("bluevpn-site/inc/class-bluevpn-seo.php")
        self.assertIn("Disallow: /account/", seo)
        self.assertIn("Sitemap: ", seo)
        self.assertIn("/llms.txt", seo)
        self.assertIn("Important public pages", seo)
        self.assertIn("Private areas", seo)

    def test_default_yoast_metadata_and_sample_cleanup_exist(self):
        seo = text("bluevpn-site/inc/class-bluevpn-seo.php")
        self.assertIn("_yoast_wpseo_title", seo)
        self.assertIn("_yoast_wpseo_metadesc", seo)
        self.assertIn("_yoast_wpseo_meta-robots-noindex", seo)
        self.assertIn("hello-world", seo)
        self.assertIn("sample-page", seo)
        self.assertIn("wp_trash_post", seo)

    def test_seo_hardening_does_not_touch_android_runtime(self):
        app = json.loads(text("branding/app.json"))
        home = text("android-source/BlueVpnHomeActivity.kt")
        self.assertEqual(app["upstream_ref"], "2.3.5")
        self.assertIn("LauncherManager.startService(this, guid)", home)
        self.assertNotIn("BlueVpnEngineManager", home)


    def test_source_version_is_authoritative_and_build_does_not_auto_increment(self):
        workflow = text(".github/workflows/build-apk.yml")
        self.assertIn("resolved = project_version", workflow)
        self.assertIn("source_declared_release_version", workflow)
        self.assertIn("Project version is behind the latest published Android release", workflow)
        self.assertNotIn("project_release_or_latest_github_release_increment", workflow)
        self.assertNotIn("patch += 1", workflow)

    def test_telegram_rest_push_verification_tolerates_head_advancing(self):
        bot = text("bluevpn-manager/includes/class-bluevpn-telegram-bot.php")
        self.assertIn("$updatedRef = self::gh('PATCH'", bot)
        self.assertIn("verify_commit_on_branch", bot)
        self.assertIn("'/compare/'", bot)
        self.assertIn("['ahead', 'identical']", bot)
        self.assertIn("usleep(250000", bot)
        self.assertNotIn("SHA شاخه پس از Push تأیید نشد.", bot)
    def test_71_logged_in_nonpremium_account_bootstraps_free_plan(self):
        home = text("android-source/BlueVpnHomeActivity.kt")
        entitlement = text("android-source/BlueVpnEntitlement.kt")
        account = text("android-source/BlueVpnAccountManager.kt")
        self.assertIn("val premiumAtLaunch = BlueVpnAccountManager.premiumEntitlementActive", home)
        self.assertIn("val needsFreeBootstrap = !premiumAtLaunch", home)
        self.assertIn("private fun prepareFreePlanAccess", home)
        helper = block(home, "private fun prepareFreePlanAccess", "private fun reconcileDeferredEntitlementIfIdle")
        self.assertIn("BlueVpnAccountManager.premiumEntitlementActive(this)", helper)
        self.assertNotIn("BlueVpnAccountManager.hasSession(this) || freePreparationInProgress", helper)
        self.assertIn("fun freeAccessConfigured", account)
        self.assertIn("val warpEligible = free.warpEnabled", entitlement)
        self.assertIn("val legacyFreeEligible = free.subscriptions.isNotEmpty()", entitlement)
        self.assertIn('"پلن رایگان • ${account.email}"', entitlement)

    def test_72_free_entitlement_is_not_conflated_with_pool_readiness(self):
        entitlement = text("android-source/BlueVpnEntitlement.kt")
        self.assertNotIn("val freeReady = !premiumReady && free.enabled && free.subscriptions.isNotEmpty()", entitlement)
        free_block = block(entitlement, "BlueVpnPlanTier.FREE -> BlueVpnEntitlementSnapshot", "BlueVpnPlanTier.UNAVAILABLE ->")
        self.assertIn("canConnect = true", free_block)
        self.assertIn("poolReady = guids.isNotEmpty()", free_block)


    def test_release_channels_beta_stable_contract(self):
        db = text("bluevpn-manager/includes/class-bluevpn-db.php")
        self.assertIn("app_releases", db)
        self.assertIn("beta_tester", db)
        manager = text("bluevpn-manager/includes/class-bluevpn-app-release-manager.php")
        self.assertIn("'state' => $state", manager)
        self.assertIn("promote_to_stable", manager)
        self.assertIn("release_for_customer", manager)
        self.assertIn("normal users", manager.lower())

    def test_android_update_check_uses_authenticated_account_pipeline(self):
        update = text("android-source/BlueVpnUpdateManager.kt")
        account = text("android-source/BlueVpnAccountManager.kt")
        self.assertIn("BlueVpnAccountManager.mobileConfig", update)
        mobile = block(account, "fun mobileConfig", "/**\n     * Apply server-authored Free policy")
        self.assertIn("authenticatedRequest(appContext, \"GET\", path, null)", mobile)
        self.assertIn("if (hasSession(appContext))", mobile)
        self.assertIn("if (error.status == 401 && !hasSession(appContext))", mobile)

    def test_mobile_config_exposes_release_channel(self):
        api = text("bluevpn-manager/includes/class-bluevpn-api.php")
        self.assertIn("'release_channel'=>$channel", api)
        self.assertIn("'beta_tester'=>(bool)", api)

    def test_77_blueai_learning_is_tier_isolated(self):
        db = text("bluevpn-manager/includes/class-bluevpn-db.php")
        ai = text("bluevpn-manager/includes/class-bluevpn-ai.php")
        self.assertIn("plan_tier varchar(16)", db)
        self.assertIn("uq_ai_route_context_tier", db)
        self.assertIn("plan_tier=%s AND operator=%s", ai)
        self.assertIn("learning_source", ai)

    def test_78_free_guest_live_reporter_does_not_require_login(self):
        reporter = text("android-source/BlueVpnLiveReporter.kt")
        report_once = block(reporter, "private fun reportOnce", "private fun nextDelaySeconds")
        self.assertNotIn("BlueVpnAccountManager.hasSession", report_once)
        self.assertIn("BlueVpnAi.hasActiveSession", report_once)
        self.assertIn("ACTIVE_DELAY_SECONDS = 10L", reporter)

    def test_79_android_blueai_sends_tier_and_schema_capability(self):
        ai = text("android-source/BlueVpnAi.kt")
        account = text("android-source/BlueVpnAccountManager.kt")
        self.assertIn("AI_SCHEMA_VERSION = 6", ai)
        self.assertIn('.put("plan_tier", planTier(context))', ai)
        self.assertIn('.put("ai_schema_version", AI_SCHEMA_VERSION)', ai)
        self.assertIn('"&plan_tier=" + java.net.URLEncoder.encode(planTier', account)

    def test_80_wordpress_blueai_has_live_dashboard_and_version_health(self):
        ai = text("bluevpn-manager/includes/class-bluevpn-ai.php")
        api = text("bluevpn-manager/includes/class-bluevpn-api.php")
        self.assertIn("wp_ajax_bluevpn_ai_live_snapshot", ai)
        self.assertIn("public static function live_snapshot", ai)
        self.assertIn("public static function version_health", ai)
        self.assertIn("پایش هوشمند همزمان Free + Premium", ai)
        self.assertIn("'engine_version'=>BlueVPN_AI::ENGINE_VERSION", api)
        self.assertIn("'capabilities'=>BlueVPN_AI::capabilities()", api)

    def test_81_mobile_update_config_is_cache_first_and_nonblocking(self):
        api = text("bluevpn-manager/includes/class-bluevpn-api.php")
        manager = text("bluevpn-manager/includes/class-bluevpn-app-release-manager.php")
        mobile = block(api, "public static function mobile_config", "public static function ad_asset")
        self.assertIn("maybe_kick($forced)", mobile)
        self.assertNotIn("sync_now(true, 'android_forced_refresh')", mobile)
        self.assertIn("release_refresh_mode'=>'background_cache_first'", mobile)
        self.assertIn("maybe_kick(bool $force = false)", manager)

    def test_82_mobile_config_release_channel_failure_falls_back_to_stable_settings(self):
        api = text("bluevpn-manager/includes/class-bluevpn-api.php")
        mobile = block(api, "public static function mobile_config", "public static function ad_asset")
        self.assertIn("release selection fallback", mobile)
        self.assertIn("$selection = ['release'=>null,'channel'=>'stable','beta_tester'=>false]", mobile)

    def test_83_blueai_admin_explains_legacy_schema_without_fake_live_count(self):
        ai = text("bluevpn-manager/includes/class-bluevpn-ai.php")
        self.assertIn("کلاینت قدیمی شناسایی شد", ai)
        self.assertIn("AI Schema v1", ai)
        self.assertIn("Android 4.3.2+", ai)

    def test_84_beta_and_stable_have_independent_auto_update_policy(self):
        db = text("bluevpn-manager/includes/class-bluevpn-db.php")
        api = text("bluevpn-manager/includes/class-bluevpn-api.php")
        cc = text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        self.assertIn("'auto_update_stable' => true", db)
        self.assertIn("'auto_update_beta' => true", db)
        self.assertIn("$autoUpdate = $channel === 'beta' ? $betaAutoUpdate : $stableAutoUpdate", api)
        self.assertIn("'auto_update'=>$autoUpdate", api)
        self.assertIn('name="auto_update_beta"', cc)
        self.assertIn('name="auto_update_stable"', cc)

    def test_85_beta_android_update_pipeline_is_same_as_stable(self):
        update = text("android-source/BlueVpnUpdateManager.kt")
        self.assertIn('KEY_RELEASE_CHANNEL = "remote_release_channel"', update)
        self.assertIn('.putString(KEY_RELEASE_CHANNEL, releaseChannel)', update)
        self.assertIn('if (updateChannel(activity) == "beta") "BlueVPN Beta $version آماده است"', update)
        apply = block(update, "private fun applyRemoteConfig", "private fun showStoredBlockIfNeeded")
        self.assertIn("updateAvailable && autoUpdate", apply)
        self.assertIn("startAutomaticDownload", apply)
        self.assertIn("forced ->", apply)
        self.assertIn("showForcedUpdateDialog", apply)

    def test_86_advertising_mobile_config_contract_survives_release_channel_changes(self):
        api = text("bluevpn-manager/includes/class-bluevpn-api.php")
        ads_android = text("android-source/BlueVpnAdsCarouselView.kt")
        mobile = block(api, "public static function mobile_config", "public static function ad_asset")
        self.assertIn("$advertising = BlueVPN_Ads::advertising_payload($s, $r);", mobile)
        self.assertIn("$tapsell = BlueVPN_Ads::tapsell_payload($s);", mobile)
        self.assertIn("'advertising'=>$advertising", mobile)
        self.assertIn("'ads'=>$advertising", mobile)
        self.assertIn("'tapsell'=>$tapsell", mobile)
        self.assertIn('root.optJSONObject("advertising") ?: root.optJSONObject("ads")', ads_android)

    def test_87_free_story_ads_are_exposed_only_as_free_connection_gate(self):
        api = text("bluevpn-manager/includes/class-bluevpn-api.php")
        ads = text("bluevpn-manager/includes/class-bluevpn-ads.php")
        mobile = block(api, "public static function mobile_config", "public static function ad_asset")
        self.assertIn("$freeStoryAds = BlueVPN_Ads::free_story_payload($s);", mobile)
        self.assertIn("'free_story_ads'=>$freeStoryAds", mobile)
        self.assertIn("'free_only' => true", ads)
        self.assertIn("'random' => true", ads)
        self.assertIn("'every_connection' => true", ads)

    def test_88_free_story_is_post_connect_and_never_owns_connection_state(self):
        home = text("android-source/BlueVpnHomeActivity.kt")
        fn = home.split("private fun showFirstPartyFreeStory()", 1)[1].split(
            "private fun maybePromptBackgroundReliability()", 1
        )[0]
        self.assertNotIn("connectionVerified = false", fn)
        self.assertNotIn("BlueVpnPreferences.clearConnected(this)", fn)
        self.assertNotIn("stopConnectionImmediately()", fn)

    def test_89_story_background_abort_does_not_stop_vpn(self):
        home = text("android-source/BlueVpnHomeActivity.kt")
        on_stop = home.split("override fun onStop()", 1)[1].split(
            "override fun onTrimMemory", 1
        )[0]
        self.assertIn("freeStoryGate?.abort()", on_stop)
        fn = home.split("private fun beginFreeStoryGate(", 1)[1].split(
            "private fun maybePromptBackgroundReliability()", 1
        )[0]
        aborted = fn.split("Outcome.ABORTED", 1)[1].split(
            "Outcome.ACTION_OPENED", 1
        )[0]
        self.assertNotIn("stopConnectionImmediately()", aborted)

    def test_90_tapsell_failure_falls_back_to_story_without_touching_vpn(self):
        home = text("android-source/BlueVpnHomeActivity.kt")
        flow = home.split("private fun beginFreeStoryGate(", 1)[1].split(
            "private fun maybePromptBackgroundReliability()", 1
        )[0]
        self.assertIn("onUnavailable = {", flow)
        self.assertIn("showFirstPartyFreeStory()", flow)
        self.assertNotIn("stopConnectionImmediately()", flow)

    def test_91_wordpress_convergence_accepts_newer_manager_and_schema(self):
        workflow = text(".github/workflows/build-apk.yml")
        wait = block(workflow, "- name: Wait for WordPress control-plane auto-update", "- name: Create GitHub Release metadata and checksums")
        self.assertIn("cv >= ev", wait)
        self.assertIn("cs >= es and ready", wait)
        self.assertIn("installed=${LAST_VERSION} (minimum=${VERSION})", wait)
        self.assertIn('DETAILS_COMPATIBLE="true"', wait)
        self.assertNotIn('[ "$LAST_VERSION" = "$VERSION" ] && [ "$LAST_SCHEMA" = "$EXPECTED_SCHEMA" ]', wait)


    def test_92_ad_destinations_are_allow_listed_and_structured(self):
        ads = text("bluevpn-manager/includes/class-bluevpn-ads.php")
        self.assertIn("private const TARGET_ACTIONS", ads)
        for action in ("auth", "plans", "purchase", "account", "renew", "settings", "external"):
            self.assertIn(f"'{action}'", ads)
        self.assertIn("'target_action' => $targetAction", ads)
        self.assertIn("'target_plan_id' => $targetPlanId", ads)
        self.assertIn("'deep_link' => self::deep_link", ads)
        self.assertIn("wp_http_validate_url", ads)

    def test_93_android_ad_router_never_launches_arbitrary_components(self):
        router = text("android-source/BlueVpnAdActionRouter.kt")
        self.assertIn("private val allowed = setOf", router)
        self.assertIn("BlueVpnSubscriptionsActivity::class.java", router)
        self.assertIn("BlueVpnSettingsActivity::class.java", router)
        self.assertIn('scheme == "https" || scheme == "http"', router)
        self.assertNotIn("Class.forName", router)
        self.assertNotIn("setClassName", router)
        self.assertNotIn("Intent.parseUri", router)
        self.assertIn('bluevpn://$normalized', router)

    def test_94_banner_and_story_ctas_use_same_internal_router(self):
        banner = text("android-source/BlueVpnAdsCarouselView.kt")
        story = text("android-source/BlueVpnFreeStoryAdGate.kt")
        self.assertIn("BlueVpnAdActionRouter.open", banner)
        self.assertIn('targetAction = row.optString("target_action")', banner)
        self.assertIn("BlueVpnAdActionRouter.open", story)
        self.assertIn("Outcome.ACTION_OPENED", story)
        self.assertIn("stopConnectionImmediately()", text("android-source/BlueVpnHomeActivity.kt"))
        self.assertIn('bluevpn_dir / "BlueVpnAdActionRouter.kt"', text("scripts/prepare_android.py"))

    def test_95_ad_purchase_continues_after_auth_without_auto_charging(self):
        subs = text("android-source/BlueVpnSubscriptionsActivity.kt")
        self.assertIn("EXTRA_ENTRY_ROUTE", subs)
        self.assertIn("EXTRA_PLAN_ID", subs)
        self.assertIn("برای ادامه خرید یا تمدید", subs)
        self.assertIn("selectedByAd", subs)
        self.assertIn("پیشنهاد این تبلیغ", subs)
        # A deep-linked plan is highlighted, but payment still requires the explicit plan CTA.
        self.assertIn('BlueVpnUiGuard.bind(this){\n    buy(p.optInt("id"))', subs)



    def test_95_sms_pattern_sync_walks_all_pages_and_deduplicates(self):
        sms = text("bluevpn-manager/includes/class-bluevpn-sms-otp.php")
        refresh = block(sms, "public static function refresh_patterns", "public static function active_pattern_codes")
        self.assertIn("provider_pattern_page_url", sms)
        self.assertIn("$maxPages = 50", refresh)
        self.assertIn("$newProviderCodes === 0", refresh)
        self.assertIn("$all[$code] = $row", refresh)
        self.assertIn("'pages_fetched'=>$pagesFetched", refresh)
        self.assertNotIn("'body' =>", refresh)

    def test_96_telegram_bot_publishes_and_installs_manager_before_android(self):
        bot = text("bluevpn-manager/includes/class-bluevpn-telegram-bot.php")
        updater = text("bluevpn-manager/includes/class-bluevpn-github-updater.php")
        workflow = text(".github/workflows/bluevpn-manager-release.yml")
        self.assertIn("🧩 بروزرسانی Manager", bot)
        self.assertIn("MANAGER_WORKFLOW", bot)
        self.assertIn("dispatch_manager_release", bot)
        self.assertIn("waiting_manager", bot)
        self.assertIn("install_latest_now", bot)
        self.assertIn("start_android_build_for_job", bot)
        self.assertIn("public static function install_latest_now", updater)
        self.assertIn("target_sha:", workflow)
        self.assertIn("request_id:", workflow)
        self.assertIn("github.event.client_payload.target_sha || inputs.target_sha || 'main'", workflow)
        self.assertIn("github.event.client_payload.request_id || inputs.request_id || github.run_id", workflow)
        self.assertIn("request_id' => $requestId", bot)
        self.assertIn("display_title", bot)

    def test_97_manager_release_uses_contents_write_repository_dispatch_first(self):
        bot = text("bluevpn-manager/includes/class-bluevpn-telegram-bot.php")
        workflow = text(".github/workflows/bluevpn-manager-release.yml")
        dispatch = block(bot, "private static function dispatch_manager_release", "private static function start_android_build_for_job")
        self.assertIn("MANAGER_REPOSITORY_EVENT", bot)
        self.assertIn("self::repo_path($s) . '/dispatches'", dispatch)
        self.assertIn("repository_dispatch (نیازمند Contents:write)", dispatch)
        self.assertIn("workflow_dispatch (نیازمند Actions:write)", dispatch)
        self.assertLess(dispatch.index("'/dispatches'"), dispatch.index("'/actions/workflows/'"))
        self.assertIn("repository_dispatch:", workflow)
        self.assertIn("types: [bluevpn_manager_release]", workflow)
        self.assertIn("github.event.client_payload.target_sha", workflow)
        self.assertIn("github.event.client_payload.request_id", workflow)
        self.assertIn("&per_page=50", bot)
        self.assertNotIn("&event=workflow_dispatch&per_page=30", bot)

    def test_97_sms_patterns_are_smart_assigned_with_safe_contract_gate(self):
        sms = text("bluevpn-manager/includes/class-bluevpn-sms-notifications.php")
        cc = text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        self.assertIn("public static function smart_assign_patterns", sms)
        self.assertIn("smart_pattern_score", sms)
        self.assertIn("متغیر ناسازگار", sms)
        self.assertIn("bluevpn_sms_smart_map_report_v1", sms)
        self.assertIn("bluevpn_cc_smart_assign_sms_patterns", cc)
        self.assertIn("تازه‌سازی + جایگذاری هوشمند", cc)
        self.assertIn("بازچینی کامل", cc)
        self.assertIn("smart_assign_patterns((array)($r['patterns']??[]),false)", cc)
        self.assertIn("تطبیق هوشمند", cc)


    def test_backup_cron_self_heals_before_48_hour_health_warning(self):
        production = text("bluevpn-manager/includes/class-bluevpn-production.php")
        self.assertIn("BACKUP_RECOVERY_STALE_AFTER = 108000", production)
        self.assertIn("BACKUP_RECOVERY_THROTTLE = 21600", production)
        self.assertIn("private static function ensure_backup_recovery", production)
        self.assertIn("wp_schedule_single_event(time() + 60, self::BACKUP_HOOK)", production)
        self.assertIn("BlueVPN_Utils::kick_wp_cron()", production)
        self.assertIn("self::ensure_backup_recovery();", production)
        self.assertIn("delete_option(self::BACKUP_RECOVERY_OPTION)", production)

    def test_98_missing_provider_repair_does_not_renew_entitlement(self):
        providers = text("bluevpn-manager/includes/class-bluevpn-providers.php")
        repair = block(providers, "public static function repair_customer_missing_providers", "public static function repairable_customer_count")
        self.assertIn("subscription_status']!=='active'", repair)
        self.assertIn("$expire=!empty($c['subscription_expire'])?(string)$c['subscription_expire']:null", repair)
        self.assertIn("data_limit_bytes", repair)
        self.assertNotIn("target_expiry", repair)
        self.assertNotIn("provision_customer", repair)
        self.assertIn("BlueVPN repair; customer", repair)

    def test_99_missing_provider_repair_covers_pasarguard_and_marzban(self):
        providers = text("bluevpn-manager/includes/class-bluevpn-providers.php")
        repair = block(providers, "public static function repair_customer_missing_providers", "public static function repairable_customer_count")
        self.assertIn("self::pg_user($p,$u,10)", repair)
        self.assertIn("self::mz_user($p,$u,10)", repair)
        self.assertIn("$details['pasarguard']='created'", repair)
        self.assertIn("$details['marzban']='created'", repair)
        self.assertIn("pasarguard_subscription_url", repair)
        self.assertIn("marzban_subscription_url", repair)

    def test_100_provider_repair_has_batched_admin_ui_and_single_customer_action(self):
        cc = text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        self.assertIn("همگام‌سازی اشتراک‌های گمشده Provider", cc)
        self.assertIn("wp_ajax_bluevpn_cc_repair_missing_provider_subscriptions", cc)
        self.assertIn("repair_candidate_ids_after($cursor,1)", cc)
        self.assertIn("bluevpn_cc_repair_customer_providers", cc)
        self.assertIn("این عملیات تاریخ اشتراک را تمدید نمی‌کند", cc)
        self.assertIn("bluevpn_provider_repair_last_result", cc)

    def test_101_pasarguard_auto_all_active_groups_and_proxy_settings_422_hardening(self):
        providers = text("bluevpn-manager/includes/class-bluevpn-providers.php")
        self.assertIn("private static function pg_active_group_ids", providers)
        self.assertIn("'/api/groups'", providers)
        self.assertIn("'/api/groups/simple'", providers)
        self.assertIn("is_disabled", providers)
        self.assertIn("$groupIds=self::pg_active_group_ids", providers)
        self.assertIn("private static function pg_proxy_settings", providers)
        self.assertIn("Input should be a valid dictionary or object", providers)
        self.assertIn("if($proxySettings)$payload['proxy_settings']=$proxySettings", providers)

    def test_102_marzban_always_uses_live_all_active_inbounds_and_normalized_proxy_objects(self):
        providers = text("bluevpn-manager/includes/class-bluevpn-providers.php")
        access = block(providers, "private static function mz_access", "private static function username")
        self.assertIn("Always prefer the live inbound catalog", access)
        self.assertIn("'/api/inbounds'", access)
        self.assertIn("new stdClass()", access)
        self.assertIn("cachedInbounds", access)
        self.assertIn("هیچ Inbound فعال", access)

    def test_103_provider_repair_resyncs_access_for_existing_users(self):
        providers = text("bluevpn-manager/includes/class-bluevpn-providers.php")
        repair = block(providers, "public static function repair_customer_missing_providers", "public static function repairable_customer_count")
        self.assertIn("همگام‌سازی گروه‌های PasarGuard", repair)
        self.assertIn("['group_ids'=>$groupIds]", repair)
        self.assertIn("groups_synced", repair)
        self.assertIn("همگام‌سازی Inboundهای Marzban", repair)
        self.assertIn("['proxies'=>$proxies,'inbounds'=>$inbounds]", repair)
        self.assertIn("inbounds_synced", repair)
        self.assertNotIn("target_expiry", repair)

    def test_104_provider_ui_explains_live_group_and_inbound_selection(self):
        cc = text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        self.assertIn("گروه‌های PasarGuard", cc)
        self.assertIn("Inboundهای Marzban", cc)
        self.assertIn("دریافت لیست", cc)
        self.assertIn("اگر هیچ موردی انتخاب نشود", cc)


    def test_105_home_shows_live_speed_and_plan_aware_timer_in_reserved_area(self):
        home = text("android-source/BlueVpnHomeActivity.kt")
        self.assertIn("private fun createLiveConnectionMetrics", home)
        self.assertIn("R.id.bluevpn_download_speed", home)
        self.assertIn("R.id.bluevpn_upload_speed", home)
        self.assertIn("R.id.bluevpn_duration_value", home)
        self.assertIn('durationMetricLabel.text = "زمان باقی‌مانده 🎁"', home)
        self.assertIn('durationMetricLabel.text = "مدت اتصال"', home)
        header = block(home, "private fun createHeader", "private fun createConnectingOverlay")
        self.assertNotIn('"رایگان 60:00"', header)

    def test_106_blueai_live_reporter_measures_real_multi_sample_tunnel_rtt(self):
        ai = text("android-source/BlueVpnAi.kt")
        reporter = text("android-source/BlueVpnLiveReporter.kt")
        self.assertIn("fun measureLiveTunnelLatency", ai)
        self.assertIn("requestTunnelProof(target, httpPort)", ai)
        self.assertIn("packetLossX100", ai)
        self.assertIn("jitterMs", ai)
        self.assertIn("BlueVpnAi.measureLiveTunnelLatency", reporter)
        self.assertIn("requestedSamples = if (BlueVpnPerformance.isLowEnd(app)) 2 else 3", reporter)

    def test_107_blueai_live_database_and_dashboard_expose_real_ping_statistics(self):
        db = text("bluevpn-manager/includes/class-bluevpn-db.php")
        ai = text("bluevpn-manager/includes/class-bluevpn-ai.php")
        for token in ["ping_min_ms int", "ping_max_ms int", "jitter_ms int", "packet_loss_x100 int", "ping_samples int"]:
            self.assertIn(token, db)
        self.assertIn("minimum_live_ping_ms", ai)
        self.assertIn("maximum_live_ping_ms", ai)
        self.assertIn("average_live_jitter_ms", ai)
        self.assertIn("average_live_loss_pct", ai)
        self.assertIn("Ping واقعی", ai)
        self.assertIn("در انتظار نمونه واقعی", ai)

    def test_108_live_heartbeat_latency_updates_route_ping_without_fake_success_samples(self):
        ai = text("bluevpn-manager/includes/class-bluevpn-ai.php")
        helper = block(ai, "private static function update_route_live_latency", "public static function submit_event")
        self.assertIn("total_ping_ms", helper)
        self.assertIn("ping_samples", helper)
        self.assertNotIn("sample_count", helper)
        self.assertIn("if($accepted)self::update_route_live_latency", ai)

    def test_113_beta_manual_check_retries_cache_first_release_snapshot(self):
        update = text("android-source/BlueVpnUpdateManager.kt")
        self.assertIn('config.optString("release_refresh_mode") == "background_cache_first"', update)
        self.assertIn('repeat(2)', update)
        self.assertIn('Thread.sleep(3_000L)', update)
        self.assertIn('BlueVpnAccountManager.mobileConfig(', update)

    def test_114_mobile_config_cache_is_reset_on_auth_boundaries(self):
        account = text("android-source/BlueVpnAccountManager.kt")
        self.assertIn("private fun invalidateMobileConfigCache()", account)
        self.assertGreaterEqual(account.count("invalidateMobileConfigCache()"), 4)

    def test_115_wordpress_exposes_release_auth_diagnostics(self):
        api = text("bluevpn-manager/includes/class-bluevpn-api.php")
        self.assertIn("'release_auth_state'=>$releaseAuthState", api)
        self.assertIn("'release_auth_error'=>$releaseAuthError", api)

    def test_116_build_waits_for_cache_first_wordpress_release_ingest(self):
        workflow = text(".github/workflows/build-apk.yml")
        self.assertIn("sleep 5", workflow)
        self.assertIn("bluevpn-wordpress-mobile-config-after-sync.json", workflow)
        self.assertIn("release_refresh_mode", workflow)

    def test_117_home_live_metrics_are_subsecond_local_not_network_polled(self):
        theme = text("android-source/BlueVpnTheme.kt")
        home = text("android-source/BlueVpnHomeActivity.kt")
        self.assertIn("if (isLowEnd(context)) 400L else 250L", theme)
        self.assertIn("readTunnelTrafficBytes()", home)
        self.assertIn("updateLiveStats()", home)

    def test_118_blueai_gets_immediate_and_frequent_real_rtt_heartbeat(self):
        reporter = text("android-source/BlueVpnLiveReporter.kt")
        ai = text("android-source/BlueVpnAi.kt")
        home = text("android-source/BlueVpnHomeActivity.kt")
        self.assertIn("INITIAL_DELAY_SECONDS = 2L", reporter)
        self.assertIn("ACTIVE_DELAY_SECONDS = 10L", reporter)
        self.assertIn("fun kick(context: Context", reporter)
        self.assertIn("HEARTBEAT_INTERVAL = 8 * 1000L", ai)
        self.assertIn("BlueVpnLiveReporter.kick(this)", home)

    def test_119_logout_blocks_connect_until_free_pool_reconciled_and_clears_selected_guid(self):
        account = text("android-source/BlueVpnAccountManager.kt")
        self.assertIn("setEntitlementReconcilePending(appContext, true)", account)
        self.assertIn('MmkvManager.setSelectServer("")', account)
        self.assertIn("prepareFreeAccess(appContext, force = true)", account)
        home = text("android-source/BlueVpnHomeActivity.kt")
        self.assertIn("entitlementReconcilePending(this)", home)

    def test_120_free_pool_quarantines_semantically_same_premium_endpoints_permanently(self):
        account = text("android-source/BlueVpnAccountManager.kt")
        self.assertIn("rememberPremiumBoundaryFingerprints", account)
        self.assertIn("KEY_EVER_PREMIUM_FINGERPRINTS", account)
        self.assertIn("hardIsolationAllowed", account)
        self.assertNotIn("PREMIUM_BOUNDARY_TTL_MS", account)
        self.assertIn("entitlementIdentityAtStart", text("android-source/BlueVpnHomeActivity.kt"))

    def test_121_profile_ownership_registry_is_tier_and_source_aware(self):
        account = text("android-source/BlueVpnAccountManager.kt")
        self.assertIn('OWNERSHIP_PREFS = "bluevpn_profile_ownership"', account)
        self.assertIn('"PREMIUM:$it"', account)
        self.assertIn('"FREE:${sourceId.trim()}"', account)
        self.assertIn("KEY_OWNER_MAP_JSON", account)
        self.assertIn("registerFreePoolOwnership", account)
        self.assertIn("registerPremiumPoolOwnership", account)

    def test_122_hard_isolation_rejects_cross_tier_semantic_collisions(self):
        account = text("android-source/BlueVpnAccountManager.kt")
        gate = block(account, "private fun hardIsolationAllowed", "private fun rememberPremiumBoundaryFingerprints")
        self.assertIn("BlueVpnPoolOrchestrator.allowed", gate)
        self.assertIn("BlueVpnPoolOrchestrator.Tier.PREMIUM", gate)
        self.assertIn("BlueVpnPoolOrchestrator.Tier.FREE", gate)
        preferred = block(account, "fun preferredServerGuids", "fun entitlementPoolFingerprint")
        self.assertGreaterEqual(preferred.count("hardIsolationAllowed"), 3)
        candidate = block(account, "fun candidateAllowed(", "fun awaitEntitlementServers")
        self.assertIn("hardIsolationAllowed(c, guid)", candidate)

    def test_123_automatic_session_invalidation_crosses_same_free_boundary_as_logout(self):
        account = text("android-source/BlueVpnAccountManager.kt")
        invalid = block(account, "private fun invalidateSession", "fun requestOtp")
        self.assertIn("rememberPremiumBoundaryFingerprints(appContext)", invalid)
        self.assertIn("enforceFreeBoundaryTransition(appContext)", invalid)
        boundary = block(account, "private fun enforceFreeBoundaryTransition", "fun logout")
        self.assertIn('MmkvManager.setSelectServer("")', boundary)
        self.assertIn("prepareFreeAccess(appContext, force = true)", boundary)

    def test_124_blueai_inventory_reads_actual_imported_profiles_from_each_subscription(self):
        orchestrator = text("android-source/BlueVpnPoolOrchestrator.kt")
        self.assertIn("MmkvManager.decodeSubscriptions()", orchestrator)
        self.assertIn("MmkvManager.decodeServerList(row.guid)", orchestrator)
        self.assertIn("BlueVpnProfileManager.fingerprintGuid", orchestrator)
        self.assertIn('FREE_REMARK = "BlueVPN Free"', orchestrator)
        self.assertIn('PREMIUM_REMARK = "BlueVPN Account"', orchestrator)

    def test_125_subscription_refresh_rebuilds_ai_pool_inventory_after_upstream_import(self):
        subscription = text("android-source/BlueVpnSubscriptionIntelligence.kt")
        self.assertIn("AngConfigManager.updateConfigViaSub(row)", subscription)
        self.assertIn("BlueVpnPoolOrchestrator.reconcile(context)", subscription)

    def test_126_exact_cross_tier_configs_block_free_copy_without_losing_premium(self):
        orchestrator = text("android-source/BlueVpnPoolOrchestrator.kt")
        self.assertIn("Tier.FREE in it && Tier.PREMIUM in it", orchestrator)
        self.assertIn("KEY_BLOCKED_FREE_GUIDS", orchestrator)
        self.assertIn("desiredTier == Tier.FREE", orchestrator)
        self.assertIn("Premium copy remains usable", orchestrator)

    def test_127_endpoint_overlap_is_diagnostic_while_subscription_source_is_security_boundary(self):
        profiles = text("android-source/BlueVpnProfileManager.kt")
        orchestrator = text("android-source/BlueVpnPoolOrchestrator.kt")
        self.assertIn("fun endpointFingerprintGuid", profiles)
        self.assertIn("endpointOverlapWarnings", orchestrator)
        self.assertIn("producing subscription row is the authority", orchestrator)

    def test_live_reporter_uses_activity_context_inside_with_context(self):
        home = (ROOT / "android-source" / "BlueVpnHomeActivity.kt").read_text(encoding="utf-8")
        self.assertIn("BlueVpnLiveReporter.kick(this@BlueVpnHomeActivity)", home)
        self.assertNotIn("BlueVpnLiveReporter.kick(this)\n                    BlueVpnPreferences.markConnected(\n                        this@BlueVpnHomeActivity,", home)
        self.assertNotIn("BlueVpnLiveReporter.kick(this)\n                    renderConnectionState(true)", home)

    def test_129_pool_owner_is_current_subscription_guid_not_semantic_history(self):
        orchestrator = text("android-source/BlueVpnPoolOrchestrator.kt")
        self.assertIn("KEY_GUID_OWNERS", orchestrator)
        self.assertIn("guidOwner[guid]", orchestrator)
        self.assertIn("memoryOwners[guid] != desiredTier", orchestrator)
        self.assertIn("producing subscription row is the authority", orchestrator)

    def test_130_exact_collision_blocks_free_copy_but_preserves_premium_copy(self):
        orchestrator = text("android-source/BlueVpnPoolOrchestrator.kt")
        self.assertIn("KEY_BLOCKED_FREE_GUIDS", orchestrator)
        self.assertIn("desiredTier == Tier.FREE", orchestrator)
        self.assertIn("Premium copy remains usable", orchestrator)
        self.assertNotIn("owners.size == 1", orchestrator)

    def test_131_location_stale_cache_cannot_show_routes_outside_current_entitlement(self):
        locations = text("android-source/BlueVpnLocationUtil.kt")
        stale = block(locations, "if (resolved.isEmpty() && previous.isNotEmpty())", "synchronized(this) {\n            contextCandidateCache = resolved")
        self.assertIn("stillOwned", stale)
        self.assertIn("BlueVpnAccountManager.candidateAllowed", stale)
        self.assertNotIn("return previous", stale)

    def test_132_free_empty_pool_bypasses_recent_refresh_ttl(self):
        account = text("android-source/BlueVpnAccountManager.kt")
        install = block(account, "private fun installFreeSubscriptions", "private fun configuredFreeSubscriptionGuids")
        self.assertIn("emptyOrBrokenRows", install)
        self.assertIn("sourceRowsMissing", install)
        self.assertIn("emptyOrBrokenRows.isNotEmpty()", install)
        self.assertIn("aggressiveRepair = existing.isEmpty() || emptyOrBrokenRows.isNotEmpty() || sourceRowsMissing", install)

    def test_133_subscription_aggressive_repair_is_not_ignored_and_retries_empty_import(self):
        subscription = text("android-source/BlueVpnSubscriptionIntelligence.kt")
        self.assertIn("aggressiveRepair = aggressiveRepair", subscription)
        self.assertNotIn("val maxAttempts = if (aggressiveRepair || beforeCount == 0) 2 else 1", subscription)
        self.assertNotIn("for (attempt in 0 until maxAttempts)", subscription)
        self.assertIn("One authoritative import is", subscription)
        self.assertIn("afterCount > 0", subscription)

    def test_134_free_pool_ready_requires_real_decodable_profiles(self):
        account = text("android-source/BlueVpnAccountManager.kt")
        install = block(account, "private fun installFreeSubscriptions", "private fun configuredFreeSubscriptionGuids")
        self.assertIn("return installedGuids.isNotEmpty()", install)
        self.assertIn("MmkvManager.decodeServerList(subscriptionGuid).isNotEmpty()", install)
        self.assertIn("BlueVpnPoolOrchestrator.reconcile(c)", install)

    def test_135_premium_readiness_ignores_lkg_cache(self):
        account = text("android-source/BlueVpnAccountManager.kt")
        self.assertIn("private fun currentPremiumServerGuids", account)
        self.assertIn("val currentPoolReady = !premiumActive || currentPremiumServerGuids(c).isNotEmpty()", account)
        self.assertIn("forceRefresh = currentPremiumServerGuids(appContext).isEmpty()", account)

    def test_136_premium_lkg_is_bound_to_current_source(self):
        account = text("android-source/BlueVpnAccountManager.kt")
        self.assertIn('putString("url_$owner", account.subscriptionUrl.trim())', account)
        self.assertIn('if (savedUrl.isBlank() || savedUrl != currentUrl) return emptyList()', account)
        self.assertIn('currentPoolIdentity != savedPoolIdentity', account)

    def test_137_auto_connection_sweeps_exact_entitlement_guids_with_v2rayng_test_service(self):
        home = text("android-source/BlueVpnHomeActivity.kt")
        self.assertIn("serverGuids = networkSweepGuids", home)
        self.assertIn("AppConfig.MSG_MEASURE_CONFIG_START", home)
        self.assertIn("اسکن سریع $tested از ${guids.size} مسیر", home)

    def test_138_running_core_does_not_bypass_end_to_end_verification(self):
        home = text("android-source/BlueVpnHomeActivity.kt")
        block = home[home.index("active && failoverActive ->"):home.index("active -> {", home.index("active && failoverActive ->"))]
        self.assertIn("scheduleConnectionVerification()", block)
        self.assertNotIn("completeFailover(null)", block)

    def test_139_connecting_orb_animates_on_low_end_and_has_no_outer_arc(self):
        home = text("android-source/BlueVpnHomeActivity.kt")
        globe = home[home.index("private class ConnectingGlobeView"):home.index("private class HeaderGlyphView")]
        self.assertIn("duration = if (lowEnd) 2_800L else 2_000L", globe)
        self.assertNotIn("if (lowEnd || animator?.isRunning", globe)
        self.assertNotIn("canvas.drawArc", globe)

    def test_140_live_speed_uses_ewma_and_zero_hold(self):
        home = text("android-source/BlueVpnHomeActivity.kt")
        self.assertIn("smoothedDownloadBps", home)
        self.assertIn("lastNonZeroDownloadElapsed", home)
        self.assertIn("now - lastNonZeroDownloadElapsed > 2_200L", home)

    def test_141_network_sweep_ticker_has_explicit_runnable_type(self):
        home = text("android-source/BlueVpnHomeActivity.kt")
        self.assertIn("private val networkSweepTicker: Runnable = object : Runnable", home)

    def test_142_auto_mode_is_cache_first_and_never_forces_full_scan(self):
        home = text("android-source/BlueVpnHomeActivity.kt")
        self.assertIn("if (selectionMode == BlueVpnSelectionMode.AUTO)", home)
        self.assertIn("cached.take(12)", home)
        auto = block(home, "// AUTO is cache-first", "if (!BlueVpnLocationUtil.hasCandidateCache(this))")
        self.assertNotIn("forceRefresh = true", auto)
        self.assertIn("scheduleIdleCandidateWarmup()", auto)

    def test_143_connection_status_text_is_two_line_and_track_is_bottom_anchored(self):
        home = text("android-source/BlueVpnHomeActivity.kt")
        self.assertIn("statusCaption", home)
        self.assertIn("maxLines = 2", home)
        self.assertIn("palette.textSecondary", home)
        self.assertIn("Gravity.BOTTOM or Gravity.CENTER_HORIZONTAL", home)
        self.assertIn("waitedMs >= 25_000L", home)


class TestIrcfIntelligenceSuite(unittest.TestCase):
    def test_ircf_intelligence_source_is_persisted(self):
        src = text("android-source/BlueVpnIrcfIntelligence.kt")
        self.assertIn("object BlueVpnIrcfIntelligence", src)
        self.assertIn("ircfspace/testUrl", src)
        self.assertIn("ircfspace/cf-ip-ranges", src)
        self.assertIn("ircfspace/endpoint", src)
        self.assertIn("auditSubscription", src)
        self.assertIn("fragment_scoring", src)

    def test_ircf_does_not_rewrite_configs(self):
        src = text("android-source/BlueVpnIrcfIntelligence.kt")
        self.assertNotIn("setServer", src)
        self.assertNotIn("setPassword", src)
        self.assertNotIn("setPublicKey", src)

    def test_adaptive_probe_pool_is_wired(self):
        ai = text("android-source/BlueVpnAi.kt")
        self.assertIn("BlueVpnIrcfIntelligence.adaptiveProbeUrls", ai)
        manager = text("bluevpn-manager/includes/class-bluevpn-ai.php")
        self.assertIn("ircf-testurl-", manager)

    def test_subscription_audit_runs_after_upstream_parser(self):
        sub = text("android-source/BlueVpnSubscriptionIntelligence.kt")
        self.assertIn("AngConfigManager.updateConfigViaSub", sub)
        self.assertIn("BlueVpnIrcfIntelligence.auditSubscription", sub)

    def test_manager_exposes_ircf_controls(self):
        api = text("bluevpn-manager/includes/class-bluevpn-api.php")
        ai = text("bluevpn-manager/includes/class-bluevpn-ai.php")
        self.assertIn("'ircf_intelligence'=>", api)
        self.assertIn("blueai_ircf_refiner", ai)
        self.assertIn("blueai_ircf_test_urls", ai)
        self.assertIn("blueai_ircf_cloudflare", ai)
        self.assertIn("blueai_ircf_fragment", ai)
        self.assertIn("blueai_ircf_endpoints", ai)


    def test_pool_sync_has_single_import_owner_and_locations_do_not_block_on_mmkv_mutation(self):
        account = (ROOT / "android-source" / "BlueVpnAccountManager.kt").read_text(encoding="utf-8")
        servers = (ROOT / "android-source" / "BlueVpnServersActivity.kt").read_text(encoding="utf-8")
        subscription = (ROOT / "android-source" / "BlueVpnSubscriptionIntelligence.kt").read_text(encoding="utf-8")
        self.assertIn("deferEntitlementWork: Boolean = false", account)
        self.assertIn("deferEntitlementWork = deferEntitlementWork", account)
        self.assertIn("force = true,\n                    deferEntitlementWork = true", servers)
        self.assertIn("BlueVpnRuntimeGate.subscriptionMutationActive()", servers)
        self.assertEqual(subscription.count("AngConfigManager.updateConfigViaSub(row)"), 1)
        self.assertNotIn("Thread.sleep(180L)", subscription)

    def test_117_wordpress_convergence_accepts_minimal_public_health(self):
        workflow = text(".github/workflows/build-apk.yml")
        wait = block(workflow, "- name: Wait for WordPress control-plane auto-update", "- name: Create GitHub Release metadata and checksums")
        self.assertIn('LAST_STATUS="unknown"', wait)
        self.assertIn("d.get('status','unknown')", wait)
        self.assertIn('[ "$VERSION_COMPATIBLE" = "true" ] && [ "$LAST_STATUS" = "ok" ] && [ "$DETAILS_COMPATIBLE" = "true" ]', wait)
        self.assertIn('Detailed schema/updater diagnostics are intentionally admin-only', wait)
        self.assertNotIn('[ "$COMPATIBLE" = "true" ] && [ "$LAST_READY" = "true" ]', wait)

    def test_118_wordpress_convergence_does_not_hide_degraded_health(self):
        workflow = text(".github/workflows/build-apk.yml")
        wait = block(workflow, "- name: Wait for WordPress control-plane auto-update", "- name: Create GitHub Release metadata and checksums")
        self.assertIn('WORDPRESS_HEALTH_DEGRADED', wait)
        self.assertIn('[ "$VERSION_COMPATIBLE" != "true" ] && [ "$LAST_UPDATER_AUTH" = "unknown" ]', wait)
        self.assertIn('Installed manager version is below the release target', wait)

if __name__ == "__main__":
    unittest.main(verbosity=2)


def test_auth_does_not_block_on_entitlement_bootstrap():
    account = (ROOT / "android-source" / "BlueVpnAccountManager.kt").read_text(encoding="utf-8")
    assert "deferEntitlementWork: Boolean = false" in account
    assert "backgroundExecutor.execute { runCatching { entitlementWork() } }" in account
    assert "deferEntitlementWork = !bindToCurrentAccount" in account
    assert "deferEntitlementWork = true" in account

def test_auth_ui_returns_immediately_after_session():
    activity = (ROOT / "android-source" / "BlueVpnSubscriptionsActivity.kt").read_text(encoding="utf-8")
    assert "completeAuthFast" in activity
    assert "finish()},90L" in activity
    assert "hideSoftInputFromWindow" in activity


def test_progressive_scanner_does_not_block_on_full_pool():
    home = (ROOT / "android-source" / "BlueVpnHomeActivity.kt").read_text(encoding="utf-8")
    assert ".take(8)" in home
    assert "cached.take(12)" in home
    assert "val earlyQuorum" in home
    assert "elapsed >= 2_200L" in home
    assert "اتصال فوری به بهترین گزینه" in home

def test_ready_exact_pool_bypasses_background_reconcile_wait():
    home = (ROOT / "android-source" / "BlueVpnHomeActivity.kt").read_text(encoding="utf-8")
    account = (ROOT / "android-source" / "BlueVpnAccountManager.kt").read_text(encoding="utf-8")
    assert "hasUsableCurrentEntitlementPool" in account
    assert "if (!BlueVpnAccountManager.hasUsableCurrentEntitlementPool(this))" in home
    assert "Pool فعلی آماده است • همگام‌سازی تکمیلی در پس‌زمینه" in home
