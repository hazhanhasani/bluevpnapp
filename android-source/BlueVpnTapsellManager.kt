package com.v2ray.ang.bluevpn

import android.app.Activity
import android.content.Context
import android.os.Handler
import android.os.Looper
import android.util.Log
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

    private data class Config(
        val enabled: Boolean,
        val appId: String,
        val zoneId: String,
        val showAfterConnect: Boolean,
        val minIntervalSeconds: Int,
        val dailyCap: Int,
    ) {
        val valid: Boolean
            get() = enabled &&
                showAfterConnect &&
                appId.isNotBlank() &&
                zoneId.isNotBlank()
    }

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
    @Volatile private var pendingSessionId = 0L
    @Volatile private var pendingActivity: WeakReference<Activity>? = null

    fun warmUp(context: Context) {
        val app = context.applicationContext
        if (!BlueVpnEntitlement.resolveUi(app).isFree) {
            cancelPending()
            return
        }

        loadConfig(app, force = false) { loaded ->
            if (!loaded.valid) return@loadConfig
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
            if (!loaded.valid || !BlueVpnEntitlement.resolveUi(app).isFree) {
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
                            requestInterstitial(current, loaded, onUnavailable)
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
                Config(false, "", "", true, 0, 0)
            }

            config = loaded
            configLoadedAt = android.os.SystemClock.elapsedRealtime()
            configLoading.set(false)
            main.post { callback(loaded) }
        }
    }

    private fun parseConfig(value: JSONObject): Config = Config(
        enabled = value.optBoolean("enabled", false),
        appId = value.optString(
            "app_id",
            value.optString("app_key", ""),
        ).trim(),
        zoneId = value.optString("interstitial_zone_id", "").trim(),
        showAfterConnect = value.optBoolean("show_after_connect", true),
        minIntervalSeconds = value
            .optInt("min_interval_seconds", 0)
            .coerceIn(0, 86_400),
        dailyCap = value
            .optInt("daily_cap", 0)
            .coerceIn(0, 1_000),
    )

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
        if (!loaded.valid || !BlueVpnEntitlement.resolveUi(context).isFree) {
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

    private fun requestInterstitial(
        activity: Activity,
        loaded: Config,
        onUnavailable: (() -> Unit)? = null,
    ) {
        if (activity.isFinishing || activity.isDestroyed) return
        if (!loaded.valid || readyAdId.isNotBlank()) return
        if (!BlueVpnEntitlement.resolveUi(activity).isFree) return
        if (!buildAppIdMatches(activity, loaded)) return
        if (!adRequesting.compareAndSet(false, true)) return

        recordStatus(activity, "requesting")

        runCatching {
            // Activity overload supports mediated networks that need Activity
            // context during load, while still working for Tapsell's own network.
            Tapsell.requestInterstitialAd(
                loaded.zoneId,
                activity,
                object : RequestResultListener {
                    override fun onSuccess(adId: String) {
                        main.post {
                            adRequesting.set(false)
                            readyAdId = adId.trim()
                            if (readyAdId.isBlank()) {
                                recordStatus(activity, "request_empty_ad_id")
                                return@post
                            }

                            recordStatus(activity, "ready")
                            val target = pendingActivity?.get()
                            if (
                                target != null &&
                                !target.isFinishing &&
                                !target.isDestroyed
                            ) {
                                showReadyAd(target, loaded)
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

        if (!eligibleForSession(activity, loaded, sessionId)) {
            pendingSessionId = 0L
            pendingActivity = null
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
                        recordStatus(
                            activity,
                            "closed_${adShowCompletionState.name.lowercase(Locale.US)}",
                        )
                    }

                    override fun onAdFailed(message: String) {
                        readyAdId = ""
                        recordStatus(activity, "show_error", message)
                        Log.w(TAG, "Interstitial show failed: $message")
                        onUnavailable?.invoke()
                    }
                },
            )

            // Showing an ad is not a VPN state transition.
            readyAdId = ""
            pendingSessionId = 0L
            pendingActivity = null
        }.onFailure {
            readyAdId = ""
            pendingSessionId = 0L
            pendingActivity = null
            recordStatus(activity, "show_exception", it.message.orEmpty())
            Log.w(TAG, "Interstitial show exception", it)
            onUnavailable?.invoke()
        }
    }

    private fun eligibleForSession(
        context: Context,
        loaded: Config,
        sessionId: Long,
    ): Boolean {
        if (BlueVpnPreferences.connectedAt(context) != sessionId) return false

        val storage = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        if (storage.getLong(KEY_LAST_SESSION, 0L) == sessionId) return false

        val now = System.currentTimeMillis()
        val last = storage.getLong(KEY_LAST_SHOWN_AT, 0L)
        if (
            loaded.minIntervalSeconds > 0 &&
            now - last < loaded.minIntervalSeconds * 1_000L
        ) {
            return false
        }

        val today = SimpleDateFormat("yyyy-MM-dd", Locale.US).format(Date())
        val count = if (storage.getString(KEY_DAY, "") == today) {
            storage.getInt(KEY_DAY_COUNT, 0)
        } else {
            0
        }

        return loaded.dailyCap <= 0 || count < loaded.dailyCap
    }

    private fun markShown(
        context: Context,
        sessionId: Long,
    ) {
        val storage = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val today = SimpleDateFormat("yyyy-MM-dd", Locale.US).format(Date())
        val oldCount = if (storage.getString(KEY_DAY, "") == today) {
            storage.getInt(KEY_DAY_COUNT, 0)
        } else {
            0
        }

        storage.edit()
            .putLong(KEY_LAST_SESSION, sessionId)
            .putLong(KEY_LAST_SHOWN_AT, System.currentTimeMillis())
            .putString(KEY_DAY, today)
            .putInt(KEY_DAY_COUNT, oldCount + 1)
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
        adRequesting.set(false)
    }
}
