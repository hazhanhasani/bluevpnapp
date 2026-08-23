package com.v2ray.ang.bluevpn

import android.app.Activity
import android.content.Context
import android.os.Handler
import android.os.Looper
import android.util.Log
import android.view.View
import android.view.ViewGroup
import ir.tapsell.mediation.ad.request.BannerSize
import ir.tapsell.mediation.ad.views.banner.BannerContainer
import java.lang.reflect.Proxy
import com.v2ray.ang.BuildConfig
import ir.tapsell.mediation.Tapsell
import ir.tapsell.mediation.ad.AdStateListener
import ir.tapsell.mediation.ad.request.RequestResultListener
import ir.tapsell.mediation.ad.show.AdShowCompletionState
import org.json.JSONObject
import java.lang.ref.WeakReference
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.UUID
import java.util.concurrent.Executors
import java.util.concurrent.atomic.AtomicBoolean

/**
 * Free-plan Tapsell Mediation interstitial controller.
 *
 * Advertising is strictly presentation-only:
 * - VPN/session state is finalized before this manager runs.
 * - SDK/config/no-fill/show failures never stop or restart VPN.
 * - Premium users never request/show the Free placement.
 * - One verified VPN session records at most one impression.
 */
object BlueVpnTapsellManager {
    private const val TAG = "BlueVpnTapsell"
    private const val PREFS = "bluevpn_tapsell_runtime"
    private const val KEY_LAST_SESSION = "last_shown_session"
    private const val KEY_LAST_SHOWN_AT = "last_shown_at"
    private const val KEY_DAY = "shown_day"
    private const val KEY_DAY_COUNT = "shown_day_count"
    private const val KEY_STATUS = "status"
    private const val KEY_LAST_ERROR = "last_error"
    private const val CONFIG_CACHE_MS = 60_000L
    private const val INIT_REQUEST_FALLBACK_MS = 4_500L

    data class PlacementPolicy(
        val type: String,
        val enabled: Boolean,
        val zoneId: String,
        val minIntervalSeconds: Int,
        val dailyCap: Int,
    )

    private data class Config(
        val enabled: Boolean,
        val appId: String,
        val placements: Map<String, PlacementPolicy>,
        val showAfterConnect: Boolean,
        val rewardedBonusMinutes: Int,
        val standardBannerSize: String,
        val standardBannerEverySlides: Int,
        val rewardFullscreenSuppressionSeconds: Int,
    ) {
        fun placement(type: String): PlacementPolicy =
            placements[type] ?: PlacementPolicy(type, false, "", 0, 0)

        val hasAnyPlacement: Boolean
            get() = enabled && placements.values.any { it.enabled }
    }

    data class SurfaceConfig(
        val enabled: Boolean,
        val placements: Map<String, PlacementPolicy>,
        val rewardedBonusMinutes: Int,
        val standardBannerSize: String,
        val standardBannerEverySlides: Int,
    )

    private val main = Handler(Looper.getMainLooper())
    private val io = Executors.newSingleThreadExecutor { task ->
        Thread(task, "bluevpn-tapsell").apply { isDaemon = true }
    }
    private val configLoading = AtomicBoolean(false)
    private val adRequesting = AtomicBoolean(false)

    @Volatile private var config: Config? = null
    @Volatile private var configLoadedAt = 0L
    @Volatile private var initialized = false
    @Volatile private var initializing = false
    @Volatile private var readyAdId = ""
    @Volatile private var readyPlacementType = ""
    @Volatile private var pendingSessionId = 0L
    @Volatile private var pendingActivity: WeakReference<Activity>? = null

    fun warmUp(context: Context) {
        val app = context.applicationContext
        if (!BlueVpnEntitlement.resolveUi(app).isFree) {
            cancelPending()
            return
        }

        loadConfig(app, force = false) { loaded ->
            if (!loaded.hasAnyPlacement) return@loadConfig
            if (!BlueVpnEntitlement.resolveUi(app).isFree) return@loadConfig
            if (!buildAppIdMatches(app, loaded)) return@loadConfig
            ensureInitialized(app, loaded)
        }
    }

    fun onVerifiedConnection(
        activity: Activity,
        sessionId: Long,
        onUnavailable: (() -> Unit)? = null,
    ) {
        if (activity.isFinishing || activity.isDestroyed || sessionId <= 0L) {
            onUnavailable?.invoke()
            return
        }

        val app = activity.applicationContext
        if (!BlueVpnEntitlement.resolveUi(app).isFree) {
            cancelPending()
            return
        }

        pendingSessionId = sessionId
        pendingActivity = WeakReference(activity)

        loadConfig(app, force = false) { loaded ->
            val hasPostConnectPlacement = loaded.showAfterConnect && listOf(
                loaded.placement("interstitial_video"),
                loaded.placement("interstitial_banner"),
            ).any { it.enabled && it.zoneId.isNotBlank() }
            if (!hasPostConnectPlacement || !BlueVpnEntitlement.resolveUi(app).isFree) {
                cancelPending()
                onUnavailable?.invoke()
                return@loadConfig
            }

            if (!buildAppIdMatches(app, loaded)) {
                cancelPending()
                onUnavailable?.invoke()
                return@loadConfig
            }

            main.post {
                val target = pendingActivity?.get()
                if (target == null || target.isFinishing || target.isDestroyed) {
                    cancelPending()
                    onUnavailable?.invoke()
                    return@post
                }

                if (readyAdId.isNotBlank()) {
                    showReadyAd(target, loaded, onUnavailable)
                    return@post
                }

                ensureInitialized(
                    context = app,
                    loaded = loaded,
                    after = {
                        val current = pendingActivity?.get()
                        if (
                            current != null &&
                            !current.isFinishing &&
                            !current.isDestroyed
                        ) {
                            requestPostConnectWaterfall(activity = current, loaded = loaded, onUnavailable = onUnavailable)
                        } else {
                            cancelPending()
                            onUnavailable?.invoke()
                        }
                    },
                    onUnavailable = onUnavailable,
                )
            }
        }
    }

    fun onEntitlementChanged(context: Context) {
        if (!BlueVpnEntitlement.resolveUi(context).isFree) {
            cancelPending()
        }
    }

    fun surfaceConfig(
        context: Context,
        callback: (SurfaceConfig) -> Unit,
    ) {
        val app = context.applicationContext
        if (!BlueVpnEntitlement.resolveUi(app).isFree) {
            callback(
                SurfaceConfig(
                    false,
                    emptyMap(),
                    15,
                    "BANNER_320_50",
                    3,
                ),
            )
            return
        }

        loadConfig(app, force = false) { loaded ->
            if (!BlueVpnEntitlement.resolveUi(app).isFree) {
                callback(
                    SurfaceConfig(
                        false,
                        emptyMap(),
                        15,
                        "BANNER_320_50",
                        3,
                    ),
                )
                return@loadConfig
            }
            callback(
                SurfaceConfig(
                    enabled = loaded.enabled && buildAppIdMatches(app, loaded),
                    placements = loaded.placements.toMap(),
                    rewardedBonusMinutes = loaded.rewardedBonusMinutes,
                    standardBannerSize = loaded.standardBannerSize,
                    standardBannerEverySlides = loaded.standardBannerEverySlides,
                ),
            )
        }
    }

    fun diagnostics(context: Context): JSONObject {
        val storage = context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        return JSONObject()
            .put("sdk", "mediation")
            .put("build_app_id_present", BuildConfig.BLUEVPN_TAPSELL_APP_ID.isNotBlank())
            .put("build_uses_test_fallback", BuildConfig.BLUEVPN_TAPSELL_TEST_FALLBACK)
            .put("initialized", initialized)
            .put("initializing", initializing)
            .put("requesting", adRequesting.get())
            .put("ready", readyAdId.isNotBlank())
            .put("status", storage.getString(KEY_STATUS, "idle").orEmpty())
            .put("last_error", storage.getString(KEY_LAST_ERROR, "").orEmpty())
            .put("configured_placements", JSONObject().apply {
                config?.placements?.forEach { (type, policy) ->
                    put(type, JSONObject()
                        .put("enabled", policy.enabled)
                        .put("zone_configured", policy.zoneId.isNotBlank())
                        .put("min_interval_seconds", policy.minIntervalSeconds)
                        .put("daily_cap", policy.dailyCap)
                    )
                }
            })
    }

    private fun loadConfig(
        context: Context,
        force: Boolean,
        callback: (Config) -> Unit,
    ) {
        val now = android.os.SystemClock.elapsedRealtime()
        val cached = config
        if (!force && cached != null && now - configLoadedAt < CONFIG_CACHE_MS) {
            callback(cached)
            return
        }

        if (!configLoading.compareAndSet(false, true)) {
            main.postDelayed({ loadConfig(context, false, callback) }, 250L)
            return
        }

        io.execute {
            val loaded = runCatching {
                val root = BlueVpnAccountManager.mobileConfig(context, force).getOrThrow()
                parseConfig(root.optJSONObject("tapsell") ?: JSONObject())
            }.getOrElse {
                recordStatus(context, "config_error", it.message.orEmpty())
                Log.w(TAG, "Could not load Tapsell config", it)
                Config(
                    enabled = false,
                    appId = "",
                    placements = emptyMap(),
                    showAfterConnect = false,
                    rewardedBonusMinutes = 15,
                    standardBannerSize = "BANNER_320_50",
                    standardBannerEverySlides = 3,
                    rewardFullscreenSuppressionSeconds = 300,
                )
            }

            config = loaded
            configLoadedAt = android.os.SystemClock.elapsedRealtime()
            configLoading.set(false)
            main.post { callback(loaded) }
        }
    }

    private fun parseConfig(value: JSONObject): Config {
        val zonesObject = value.optJSONObject("zones") ?: JSONObject()
        val placementsObject = value.optJSONObject("placements") ?: JSONObject()
        val types = listOf(
            "rewarded_video",
            "interstitial_video",
            "pre_roll_video",
            "native_video",
            "standard_banner",
            "interstitial_banner",
            "native_banner",
        )
        val fallbackDefaults = mapOf(
            "rewarded_video" to (300 to 5),
            "interstitial_video" to (1200 to 3),
            "pre_roll_video" to (1800 to 2),
            "native_video" to (900 to 4),
            "standard_banner" to (120 to 0),
            "interstitial_banner" to (1200 to 3),
            "native_banner" to (600 to 6),
        )

        val masterEnabled = value.optBoolean("enabled", false)
        val placements = linkedMapOf<String, PlacementPolicy>()
        types.forEach { type ->
            val row = placementsObject.optJSONObject(type)
            val compatibilityZone = zonesObject.optString(type, "").trim()
            val zoneId = row?.optString("zone_id", compatibilityZone)?.trim()
                .orEmpty()
            val defaults = fallbackDefaults[type] ?: (0 to 0)
            val enabled = if (row != null) {
                masterEnabled && row.optBoolean("enabled", false) && zoneId.isNotBlank()
            } else {
                masterEnabled && zoneId.isNotBlank()
            }
            placements[type] = PlacementPolicy(
                type = type,
                enabled = enabled,
                zoneId = zoneId,
                minIntervalSeconds = (
                    row?.optInt("min_interval_seconds", defaults.first)
                        ?: defaults.first
                    ).coerceIn(0, 86_400),
                dailyCap = (
                    row?.optInt("daily_cap", defaults.second)
                        ?: defaults.second
                    ).coerceIn(0, 1_000),
            )
        }

        // Compatibility with early single-zone clients/config.
        if (
            placements["interstitial_video"]?.zoneId.isNullOrBlank() &&
            value.optString("interstitial_zone_id", "").isNotBlank()
        ) {
            val old = value.optString("interstitial_zone_id", "").trim()
            placements["interstitial_video"] = PlacementPolicy(
                "interstitial_video",
                masterEnabled && value.optBoolean("show_after_connect", true),
                old,
                value.optInt("min_interval_seconds", 1200).coerceIn(0, 86_400),
                value.optInt("daily_cap", 3).coerceIn(0, 1_000),
            )
        }

        return Config(
            enabled = masterEnabled,
            appId = value.optString(
                "app_id",
                value.optString("app_key", ""),
            ).trim(),
            placements = placements,
            showAfterConnect = value.optBoolean("show_after_connect", true),
            rewardedBonusMinutes = value
                .optInt("rewarded_bonus_minutes", 15)
                .coerceIn(1, 180),
            standardBannerSize = value
                .optString("standard_banner_size", "BANNER_320_50")
                .trim()
                .ifBlank { "BANNER_320_50" },
            standardBannerEverySlides = value
                .optInt("standard_banner_every_slides", 3)
                .coerceIn(1, 10),
            rewardFullscreenSuppressionSeconds = value
                .optInt("reward_fullscreen_suppression_seconds", 300)
                .coerceIn(0, 3600),
        )
    }

    /**
     * Tapsell Mediation App ID is part of the Android manifest at build time.
     * Refuse a request when WordPress and the installed APK disagree; otherwise
     * an apparently-correct dashboard setting silently targets another app.
     */
    private fun buildAppIdMatches(
        context: Context,
        loaded: Config,
    ): Boolean {
        val embedded = BuildConfig.BLUEVPN_TAPSELL_APP_ID.trim()
        val matches =
            embedded.isNotBlank() &&
            loaded.appId.isNotBlank() &&
            embedded == loaded.appId &&
            !BuildConfig.BLUEVPN_TAPSELL_TEST_FALLBACK

        if (!matches) {
            val reason = when {
                BuildConfig.BLUEVPN_TAPSELL_TEST_FALLBACK ->
                    "Tapsell Mediation App ID has not been embedded in this APK yet."
                embedded.isBlank() ->
                    "Tapsell Mediation App ID is missing from this APK."
                loaded.appId.isBlank() ->
                    "Tapsell Mediation App ID is missing from BlueVPN Manager."
                else ->
                    "Tapsell Mediation App ID in APK does not match BlueVPN Manager."
            }
            recordStatus(context, "app_id_mismatch", reason)
            Log.w(TAG, reason)
        }

        return matches
    }

    private fun ensureInitialized(
        context: Context,
        loaded: Config,
        after: (() -> Unit)? = null,
        onUnavailable: (() -> Unit)? = null,
    ) {
        if (!BlueVpnStorePolicy.allowThirdPartyAds()) {
            recordStatus(context, "store_ads_disabled", "Third-party advertising is disabled in the Google Play build.")
            onUnavailable?.invoke()
            return
        }
        if (!loaded.hasAnyPlacement || !BlueVpnEntitlement.resolveUi(context).isFree) {
            onUnavailable?.invoke()
            return
        }
        if (!buildAppIdMatches(context, loaded)) {
            onUnavailable?.invoke()
            return
        }

        if (initialized) {
            after?.invoke()
            return
        }

        if (initializing) {
            // Do not wait forever for the aggregate adapter callback. Some
            // devices/adapters report initialization late even though requests
            // can already be issued.
            main.postDelayed({
                if (initialized) {
                    after?.invoke()
                } else {
                    recordStatus(context, "initialization_wait_timeout")
                    after?.invoke()
                }
            }, INIT_REQUEST_FALLBACK_MS)
            return
        }

        initializing = true
        recordStatus(context, "initializing")
        val delivered = AtomicBoolean(false)

        fun continueOnce() {
            if (delivered.compareAndSet(false, true)) {
                after?.invoke()
            }
        }

        runCatching {
            Tapsell.setInitializationListener {
                main.post {
                    initialized = true
                    initializing = false
                    recordStatus(context, "initialized")
                    continueOnce()
                }
            }
            Tapsell.initialize(context.applicationContext)

            // Fail-open timeout for SDK initialization callback. This does not
            // mark the SDK initialized; it only allows one real ad request to
            // determine availability instead of silently doing nothing forever.
            main.postDelayed({
                if (!delivered.get()) {
                    initializing = false
                    recordStatus(context, "initialization_timeout_requesting")
                    continueOnce()
                }
            }, INIT_REQUEST_FALLBACK_MS)
        }.onFailure {
            initializing = false
            recordStatus(context, "init_error", it.message.orEmpty())
            Log.w(TAG, "Tapsell Mediation initialization failed", it)
            if (delivered.compareAndSet(false, true)) {
                onUnavailable?.invoke()
            }
        }
    }

    private fun postConnectWaterfall(
        loaded: Config,
    ): List<PlacementPolicy> {
        if (!loaded.showAfterConnect) return emptyList()

        return listOf(
            loaded.placement("interstitial_video"),
            loaded.placement("interstitial_banner"),
        ).filter { it.enabled && it.zoneId.isNotBlank() }
    }


    private fun requestPostConnectWaterfall(
        activity: Activity,
        loaded: Config,
        index: Int = 0,
        failureDriven: Boolean = false,
        onUnavailable: (() -> Unit)? = null,
    ) {
        val storage = activity.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)

        // A Rewarded impression intentionally suppresses automatic fullscreen
        // ads. Do not replace the suppressed Tapsell with a BlueVPN Story.
        if (
            storage.getLong("fullscreen_suppressed_until", 0L) >
            System.currentTimeMillis()
        ) {
            recordStatus(activity, "post_connect_suppressed_after_reward")
            return
        }

        val waterfall = postConnectWaterfall(loaded)
        if (waterfall.isEmpty()) {
            // Tapsell is not configured for post-connect: first-party Story may
            // be used as the normal fallback.
            onUnavailable?.invoke()
            return
        }

        if (index !in waterfall.indices) {
            // Only an actual Tapsell request/show failure is allowed to cascade
            // into the first-party Story. Cooldown/cap is a deliberate silence.
            if (failureDriven) onUnavailable?.invoke()
            return
        }

        val policy = waterfall[index]
        if (!placementEligible(activity, policy)) {
            requestPostConnectWaterfall(
                activity = activity,
                loaded = loaded,
                index = index + 1,
                failureDriven = failureDriven,
                onUnavailable = onUnavailable,
            )
            return
        }

        requestInterstitial(
            activity = activity,
            loaded = loaded,
            policy = policy,
            onUnavailable = {
                requestPostConnectWaterfall(
                    activity = activity,
                    loaded = loaded,
                    index = index + 1,
                    failureDriven = true,
                    onUnavailable = onUnavailable,
                )
            },
        )
    }

    private fun requestInterstitial(
        activity: Activity,
        loaded: Config,
        policy: PlacementPolicy,
        onUnavailable: (() -> Unit)? = null,
    ) {
        if (activity.isFinishing || activity.isDestroyed) return
        if (!loaded.enabled || !policy.enabled || policy.zoneId.isBlank() || readyAdId.isNotBlank()) return
        if (!BlueVpnEntitlement.resolveUi(activity).isFree) return
        if (!buildAppIdMatches(activity, loaded)) return
        if (!adRequesting.compareAndSet(false, true)) return

        recordStatus(activity, "requesting_${policy.type}")

        runCatching {
            // Activity overload supports mediated networks that need Activity
            // context during load, while still working for Tapsell's own network.
            Tapsell.requestInterstitialAd(
                policy.zoneId,
                object : RequestResultListener {
                    override fun onSuccess(adId: String) {
                        main.post {
                            adRequesting.set(false)
                            readyAdId = adId.trim()
                            readyPlacementType = policy.type
                            if (readyAdId.isBlank()) {
                                readyPlacementType = ""
                                recordStatus(activity, "request_empty_ad_id")
                                onUnavailable?.invoke()
                                return@post
                            }

                            recordStatus(activity, "ready")
                            val target = pendingActivity?.get()
                            if (
                                target != null &&
                                !target.isFinishing &&
                                !target.isDestroyed
                            ) {
                                showReadyAd(target, loaded, onUnavailable)
                            } else {
                                readyAdId = ""
                                readyPlacementType = ""
                                onUnavailable?.invoke()
                            }
                        }
                    }

                    override fun onFailure(message: String) {
                        main.post {
                            adRequesting.set(false)
                            recordStatus(
                                activity,
                                "no_fill_or_request_error",
                                message,
                            )
                            Log.w(TAG, "Interstitial request failed: $message")
                            pendingSessionId = 0L
                            pendingActivity = null
                            onUnavailable?.invoke()
                        }
                    }
                },
            )
        }.onFailure {
            adRequesting.set(false)
            recordStatus(activity, "request_exception", it.message.orEmpty())
            Log.w(TAG, "Interstitial request exception", it)
            pendingSessionId = 0L
            pendingActivity = null
            onUnavailable?.invoke()
        }
    }

    private fun showReadyAd(
        activity: Activity,
        loaded: Config,
        onUnavailable: (() -> Unit)? = null,
    ) {
        if (Looper.myLooper() != Looper.getMainLooper()) {
            main.post { showReadyAd(activity, loaded, onUnavailable) }
            return
        }

        val adId = readyAdId
        val sessionId = pendingSessionId

        if (adId.isBlank() || sessionId <= 0L) return
        if (activity.isFinishing || activity.isDestroyed) return

        if (!BlueVpnEntitlement.resolveUi(activity).isFree) {
            cancelPending()
            return
        }

        val policy = loaded.placement(readyPlacementType)
        if (
            !eligibleForSession(activity, sessionId) ||
            !policy.enabled ||
            !placementEligible(activity, policy)
        ) {
            pendingSessionId = 0L
            pendingActivity = null
            readyPlacementType = ""
            onUnavailable?.invoke()
            return
        }

        val impressionRecorded = AtomicBoolean(false)

        runCatching {
            Tapsell.showInterstitialAd(
                adId,
                activity,
                object : AdStateListener.Interstitial {
                    override fun onAdImpression() {
                        if (impressionRecorded.compareAndSet(false, true)) {
                            markShown(activity, sessionId)
                            markPlacementShown(activity, policy.type)
                        }
                        recordStatus(activity, "shown")
                    }

                    override fun onAdClicked() {
                        recordStatus(activity, "clicked")
                    }

                    override fun onAdClosed(
                        adShowCompletionState: AdShowCompletionState,
                    ) {
                        readyAdId = ""
                        readyPlacementType = ""
                        recordStatus(
                            activity,
                            "closed_${adShowCompletionState.name.lowercase(Locale.US)}",
                        )
                    }

                    override fun onAdFailed(message: String) {
                        readyAdId = ""
                        readyPlacementType = ""
                        recordStatus(activity, "show_error", message)
                        Log.w(TAG, "Interstitial show failed: $message")
                        onUnavailable?.invoke()
                    }
                },
            )

            // Showing an ad is not a VPN state transition.
            readyAdId = ""
            readyPlacementType = ""
            pendingSessionId = 0L
            pendingActivity = null
        }.onFailure {
            readyAdId = ""
            readyPlacementType = ""
            pendingSessionId = 0L
            pendingActivity = null
            recordStatus(activity, "show_exception", it.message.orEmpty())
            Log.w(TAG, "Interstitial show exception", it)
            onUnavailable?.invoke()
        }
    }

    fun showRewarded(
        activity: Activity,
        onRewarded: (Int) -> Unit,
        onUnavailable: (() -> Unit)? = null,
    ) {
        if (
            activity.isFinishing ||
            activity.isDestroyed ||
            !BlueVpnEntitlement.resolveUi(activity).isFree
        ) {
            onUnavailable?.invoke()
            return
        }

        loadConfig(activity.applicationContext, force = false) { loaded ->
            val policy = loaded.placement("rewarded_video")
            if (
                !loaded.enabled ||
                !policy.enabled ||
                !placementEligible(activity, policy) ||
                !BlueVpnEntitlement.resolveUi(activity).isFree ||
                !buildAppIdMatches(activity, loaded)
            ) {
                onUnavailable?.invoke()
                return@loadConfig
            }

            ensureInitialized(
                context = activity.applicationContext,
                loaded = loaded,
                after = {
                    if (!BlueVpnEntitlement.resolveUi(activity).isFree) {
                        onUnavailable?.invoke()
                        return@ensureInitialized
                    }

                    runCatching {
                        Tapsell.requestRewardedAd(
                            policy.zoneId,
                            object : RequestResultListener {
                                override fun onSuccess(adId: String) {
                                    main.post {
                                        if (
                                            activity.isFinishing ||
                                            activity.isDestroyed ||
                                            !BlueVpnEntitlement.resolveUi(activity).isFree
                                        ) {
                                            onUnavailable?.invoke()
                                            return@post
                                        }

                                        val delivered = AtomicBoolean(false)
                                        Tapsell.showRewardedAd(
                                            adId,
                                            activity,
                                            object : AdStateListener.Rewarded {
                                                override fun onAdImpression() {
                                                    recordStatus(activity, "rewarded_shown")
                                                    markPlacementShown(activity, "rewarded_video")
                                                }

                                                override fun onAdClicked() {
                                                    recordStatus(activity, "rewarded_clicked")
                                                }

                                                override fun onAdClosed(
                                                    adShowCompletionState: AdShowCompletionState,
                                                ) {
                                                    recordStatus(
                                                        activity,
                                                        "rewarded_closed_${adShowCompletionState.name.lowercase(Locale.US)}",
                                                    )
                                                }

                                                override fun onRewarded() {
                                                    if (!delivered.compareAndSet(false, true)) return
                                                    val eventId = UUID.randomUUID()
                                                        .toString()
                                                        .lowercase(Locale.US)

                                                    io.execute {
                                                        val result = BlueVpnAccountManager
                                                            .claimRewardedBonus(
                                                                activity.applicationContext,
                                                                eventId,
                                                            )
                                                        main.post {
                                                            result.onSuccess { granted ->
                                                                if (granted > 0) {
                                                                    val suppressMs =
                                                                        loaded.rewardFullscreenSuppressionSeconds *
                                                                            1000L
                                                                    activity.applicationContext
                                                                        .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
                                                                        .edit()
                                                                        .putLong(
                                                                            "fullscreen_suppressed_until",
                                                                            System.currentTimeMillis() + suppressMs,
                                                                        )
                                                                        .apply()
                                                                    recordStatus(
                                                                        activity,
                                                                        "rewarded_granted_${granted}m",
                                                                    )
                                                                    onRewarded(granted)
                                                                }
                                                            }.onFailure {
                                                                recordStatus(
                                                                    activity,
                                                                    "reward_claim_error",
                                                                    it.message.orEmpty(),
                                                                )
                                                                onUnavailable?.invoke()
                                                            }
                                                        }
                                                    }
                                                }

                                                override fun onAdFailed(message: String) {
                                                    recordStatus(
                                                        activity,
                                                        "rewarded_show_error",
                                                        message,
                                                    )
                                                    onUnavailable?.invoke()
                                                }
                                            },
                                        )
                                    }
                                }

                                override fun onFailure(message: String) {
                                    recordStatus(
                                        activity,
                                        "rewarded_request_error",
                                        message,
                                    )
                                    onUnavailable?.invoke()
                                }
                            },
                        )
                    }.onFailure {
                        recordStatus(
                            activity,
                            "rewarded_exception",
                            it.message.orEmpty(),
                        )
                        onUnavailable?.invoke()
                    }
                },
                onUnavailable = onUnavailable,
            )
        }
    }

    fun attachStandardBanner(
        activity: Activity,
        host: ViewGroup,
        onShown: (() -> Unit)? = null,
        onUnavailable: (() -> Unit)? = null,
        onCleanup: ((() -> Unit) -> Unit)? = null,
    ) {
        if (
            activity.isFinishing ||
            activity.isDestroyed ||
            !BlueVpnEntitlement.resolveUi(activity).isFree
        ) {
            host.visibility = View.GONE
            onUnavailable?.invoke()
            return
        }

        loadConfig(activity.applicationContext, force = false) { loaded ->
            val policy = loaded.placement("standard_banner")
            if (
                !loaded.enabled ||
                !policy.enabled ||
                !placementEligible(activity, policy) ||
                !BlueVpnEntitlement.resolveUi(activity).isFree ||
                !buildAppIdMatches(activity, loaded)
            ) {
                host.visibility = View.GONE
                onUnavailable?.invoke()
                return@loadConfig
            }

            ensureInitialized(
                context = activity.applicationContext,
                loaded = loaded,
                after = {
                    if (!BlueVpnEntitlement.resolveUi(activity).isFree) {
                        host.visibility = View.GONE
                        onUnavailable?.invoke()
                        return@ensureInitialized
                    }

                    val container = BannerContainer(activity)
                    host.removeAllViews()
                    host.addView(container)

                    runCatching {
                        val bannerSize = runCatching {
                            BannerSize.valueOf(loaded.standardBannerSize)
                        }.getOrDefault(BannerSize.BANNER_320_50)

                        Tapsell.requestBannerAd(
                            policy.zoneId,
                            bannerSize,
                            object : RequestResultListener {
                                override fun onSuccess(adId: String) {
                                    main.post {
                                        if (
                                            activity.isFinishing ||
                                            activity.isDestroyed ||
                                            !BlueVpnEntitlement.resolveUi(activity).isFree
                                        ) {
                                            host.visibility = View.GONE
                                            runCatching { Tapsell.destroyBannerAd(adId) }
                                            onUnavailable?.invoke()
                                            return@post
                                        }

                                        Tapsell.showBannerAd(
                                            adId,
                                            container,
                                            activity,
                                            object : AdStateListener.Banner {
                                                override fun onAdImpression() {
                                                    host.visibility = View.VISIBLE
                                                    markPlacementShown(
                                                        activity,
                                                        "standard_banner",
                                                    )
                                                    recordStatus(
                                                        activity,
                                                        "standard_banner_shown",
                                                    )
                                                    onShown?.invoke()
                                                }

                                                override fun onAdClicked() {
                                                    recordStatus(
                                                        activity,
                                                        "standard_banner_clicked",
                                                    )
                                                }

                                                override fun onAdFailed(message: String) {
                                                    host.visibility = View.GONE
                                                    recordStatus(
                                                        activity,
                                                        "standard_banner_show_error",
                                                        message,
                                                    )
                                                    onUnavailable?.invoke()
                                                }
                                            },
                                        )
                                        onCleanup?.invoke {
                                            runCatching {
                                                Tapsell.destroyBannerAd(adId)
                                            }
                                        }
                                    }
                                }

                                override fun onFailure(message: String) {
                                    host.visibility = View.GONE
                                    recordStatus(
                                        activity,
                                        "standard_banner_request_error",
                                        message,
                                    )
                                    onUnavailable?.invoke()
                                }
                            },
                        )
                    }.onFailure {
                        host.visibility = View.GONE
                        recordStatus(
                            activity,
                            "standard_banner_exception",
                            it.message.orEmpty(),
                        )
                        onUnavailable?.invoke()
                    }
                },
                onUnavailable = onUnavailable,
            )
        }
    }

    /**
     * Native Banner/Native Video/PreRoll have moved signatures across the
     * Mediation 1.x line/adapters. This Free-only bridge discovers the current
     * SDK method at runtime and hides the slot on unsupported signatures.
     */
    fun attachPlacement(
        activity: Activity,
        host: ViewGroup,
        type: String,
        loadingView: View? = null,
        onUnavailable: (() -> Unit)? = null,
    ) {
        if (
            activity.isFinishing ||
            activity.isDestroyed ||
            !BlueVpnEntitlement.resolveUi(activity).isFree
        ) {
            host.visibility = View.GONE
            onUnavailable?.invoke()
            return
        }

        loadConfig(activity.applicationContext, force = false) { loaded ->
            val policy = loaded.placement(type)
            if (
                !loaded.enabled ||
                !policy.enabled ||
                !placementEligible(activity, policy) ||
                !buildAppIdMatches(activity, loaded)
            ) {
                host.visibility = View.GONE
                onUnavailable?.invoke()
                return@loadConfig
            }

            showReflectiveFormat(
                activity = activity,
                host = host,
                zoneId = policy.zoneId,
                format = type,
                loadingView = loadingView,
                onShown = {
                    markPlacementShown(activity, type)
                },
                onUnavailable = onUnavailable,
            )
        }
    }

    fun showReflectiveFormat(
        activity: Activity,
        host: ViewGroup,
        zoneId: String,
        format: String,
        loadingView: View? = null,
        onShown: (() -> Unit)? = null,
        onUnavailable: (() -> Unit)? = null,
    ) {
        if (
            activity.isFinishing ||
            activity.isDestroyed ||
            zoneId.isBlank() ||
            !BlueVpnEntitlement.resolveUi(activity).isFree
        ) {
            host.visibility = View.GONE
            onUnavailable?.invoke()
            return
        }

        loadConfig(activity.applicationContext, force = false) { loaded ->
            if (
                !loaded.enabled ||
                !BlueVpnEntitlement.resolveUi(activity).isFree ||
                !buildAppIdMatches(activity, loaded)
            ) {
                host.visibility = View.GONE
                onUnavailable?.invoke()
                return@loadConfig
            }

            ensureInitialized(
                context = activity.applicationContext,
                loaded = loaded,
                after = {
                    val ok = runCatching {
                        invokeReflectiveFormat(
                            activity,
                            host,
                            zoneId,
                            format,
                            loadingView,
                            onShown,
                            onUnavailable,
                        )
                    }.getOrDefault(false)
                    if (!ok) {
                        host.visibility = View.GONE
                        recordStatus(activity, "${format}_unsupported")
                        onUnavailable?.invoke()
                    }
                },
                onUnavailable = onUnavailable,
            )
        }
    }

    private fun invokeReflectiveFormat(
        activity: Activity,
        host: ViewGroup,
        zoneId: String,
        format: String,
        loadingView: View?,
        onShown: (() -> Unit)?,
        onUnavailable: (() -> Unit)?,
    ): Boolean {
        val requestNames = when (format) {
            "native_banner" -> listOf("requestNativeBannerAd", "requestNativeAd")
            "native_video" -> listOf("requestNativeVideoAd", "requestNativeAd")
            "pre_roll_video" -> listOf("requestPreRollAd", "requestPrerollAd")
            else -> emptyList()
        }
        val showNames = when (format) {
            "native_banner" -> listOf("showNativeBannerAd", "showNativeAd")
            "native_video" -> listOf("showNativeVideoAd", "showNativeAd")
            "pre_roll_video" -> listOf("showPreRollAd", "showPrerollAd")
            else -> emptyList()
        }
        val request = Tapsell::class.java.methods.firstOrNull { method ->
            method.name in requestNames &&
                method.parameterTypes.any { it == String::class.java } &&
                method.parameterTypes.any { it.isInterface }
        } ?: return false

        val listenerType = request.parameterTypes.lastOrNull { it.isInterface } ?: return false
        val completed = AtomicBoolean(false)
        val listener = Proxy.newProxyInstance(
            listenerType.classLoader,
            arrayOf(listenerType),
        ) { _, method, args ->
            when (method.name.lowercase(Locale.US)) {
                "onsuccess", "onresponse", "onloaded" -> {
                    val payload = args?.firstOrNull()
                    main.post {
                        if (completed.compareAndSet(false, true)) {
                            loadingView?.visibility = View.GONE
                            if (!invokeReflectiveShow(
                                    activity,
                                    host,
                                    payload,
                                    showNames,
                                    format,
                                    onShown,
                                    onUnavailable,
                                )
                            ) {
                                host.visibility = View.GONE
                                onUnavailable?.invoke()
                            }
                        }
                    }
                }
                "onfailure", "onerror", "onfailed" -> {
                    main.post {
                        if (completed.compareAndSet(false, true)) {
                            host.visibility = View.GONE
                            recordStatus(
                                activity,
                                "${format}_request_error",
                                args?.joinToString().orEmpty(),
                            )
                            onUnavailable?.invoke()
                        }
                    }
                }
            }
            null
        }

        val args = buildReflectiveArgs(
            activity,
            host,
            zoneId,
            null,
            request.parameterTypes,
            listener,
            format,
        ) ?: return false

        request.invoke(null, *args)
        return true
    }

    private fun invokeReflectiveShow(
        activity: Activity,
        host: ViewGroup,
        payload: Any?,
        showNames: List<String>,
        format: String,
        onShown: (() -> Unit)?,
        onUnavailable: (() -> Unit)?,
    ): Boolean {
        val show = Tapsell::class.java.methods.firstOrNull { it.name in showNames }
            ?: return false
        val listenerType = show.parameterTypes.lastOrNull { it.isInterface }
        val listener = listenerType?.let { type ->
            Proxy.newProxyInstance(type.classLoader, arrayOf(type)) { _, method, args ->
                when (method.name.lowercase(Locale.US)) {
                    "onadimpression", "onimpression" -> {
                        host.visibility = View.VISIBLE
                        recordStatus(activity, "${format}_shown")
                        onShown?.invoke()
                    }
                    "onadfailed", "onfailure", "onerror" -> {
                        host.visibility = View.GONE
                        recordStatus(
                            activity,
                            "${format}_show_error",
                            args?.joinToString().orEmpty(),
                        )
                        onUnavailable?.invoke()
                    }
                }
                null
            }
        }
        val args = buildReflectiveArgs(
            activity,
            host,
            "",
            payload,
            show.parameterTypes,
            listener,
            format,
        ) ?: return false
        host.visibility = View.VISIBLE
        show.invoke(null, *args)
        return true
    }

    private fun buildReflectiveArgs(
        activity: Activity,
        host: ViewGroup,
        zoneId: String,
        payload: Any?,
        parameterTypes: Array<Class<*>>,
        listener: Any?,
        format: String,
    ): Array<Any?>? {
        val args = arrayOfNulls<Any>(parameterTypes.size)
        for ((index, type) in parameterTypes.withIndex()) {
            args[index] = when {
                type == String::class.java ->
                    if (payload is String && zoneId.isBlank()) payload else zoneId
                Activity::class.java.isAssignableFrom(type) -> activity
                Context::class.java.isAssignableFrom(type) -> activity
                ViewGroup::class.java.isAssignableFrom(type) -> host
                View::class.java.isAssignableFrom(type) -> host
                type.isInterface -> listener
                payload != null && type.isInstance(payload) -> payload
                type.isEnum -> {
                    val values = type.enumConstants ?: return null
                    val tokens = when (format) {
                        "native_video", "pre_roll_video" ->
                            listOf("VIDEO", "LANDSCAPE", "MEDIUM")
                        "native_banner" ->
                            listOf("BANNER", "SMALL", "MEDIUM")
                        else -> emptyList()
                    }
                    values.firstOrNull { value ->
                        tokens.any {
                            value.toString().uppercase(Locale.US).contains(it)
                        }
                    } ?: values.firstOrNull()
                }
                type == Boolean::class.javaPrimitiveType ||
                    type == Boolean::class.java -> false
                type == Int::class.javaPrimitiveType ||
                    type == Int::class.java -> 0
                else -> return null
            }
        }
        return args
    }

    private fun eligibleForSession(
        context: Context,
        sessionId: Long,
    ): Boolean {
        if (BlueVpnPreferences.connectedAt(context) != sessionId) return false
        val storage = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        return storage.getLong(KEY_LAST_SESSION, 0L) != sessionId
    }

    private fun placementEligible(
        context: Context,
        policy: PlacementPolicy,
    ): Boolean {
        if (!policy.enabled || policy.zoneId.isBlank()) return false
        if (!BlueVpnEntitlement.resolveUi(context).isFree) return false

        val storage = context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val now = System.currentTimeMillis()
        val last = storage.getLong("placement_${policy.type}_last_at", 0L)
        if (
            policy.minIntervalSeconds > 0 &&
            now - last < policy.minIntervalSeconds * 1_000L
        ) return false

        if (policy.dailyCap <= 0) return true
        val today = SimpleDateFormat("yyyy-MM-dd", Locale.US).format(Date(now))
        val storedDay = storage.getString("placement_${policy.type}_day", "").orEmpty()
        val count = if (storedDay == today) {
            storage.getInt("placement_${policy.type}_count", 0)
        } else {
            0
        }
        return count < policy.dailyCap
    }

    private fun markPlacementShown(
        context: Context,
        type: String,
    ) {
        val storage = context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val now = System.currentTimeMillis()
        val today = SimpleDateFormat("yyyy-MM-dd", Locale.US).format(Date(now))
        val dayKey = "placement_${type}_day"
        val countKey = "placement_${type}_count"
        val count = if (storage.getString(dayKey, "") == today) {
            storage.getInt(countKey, 0)
        } else {
            0
        }
        storage.edit()
            .putLong("placement_${type}_last_at", now)
            .putString(dayKey, today)
            .putInt(countKey, count + 1)
            .apply()
    }

    private fun markShown(
        context: Context,
        sessionId: Long,
    ) {
        context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit()
            .putLong(KEY_LAST_SESSION, sessionId)
            .putLong(KEY_LAST_SHOWN_AT, System.currentTimeMillis())
            .apply()
    }

    private fun recordStatus(
        context: Context,
        status: String,
        error: String = "",
    ) {
        context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit()
            .putString(KEY_STATUS, status.take(80))
            .putString(KEY_LAST_ERROR, error.take(500))
            .apply()
    }

    private fun cancelPending() {
        pendingSessionId = 0L
        pendingActivity = null
        readyAdId = ""
        readyPlacementType = ""
        adRequesting.set(false)
    }
}
