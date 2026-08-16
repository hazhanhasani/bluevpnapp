package com.v2ray.ang.bluevpn

import android.app.Activity
import android.content.Context
import android.os.Handler
import android.os.Looper
import android.util.Log
import org.json.JSONObject
import java.lang.ref.WeakReference
import java.lang.reflect.Method
import java.lang.reflect.Proxy
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.concurrent.Executors
import java.util.concurrent.atomic.AtomicBoolean

/**
 * Free-plan interstitial controller.
 *
 * Design rules:
 * - Premium/Unavailable users never request or see an ad.
 * - The VPN connection is never blocked by the ad SDK.
 * - One verified VPN session can show at most one ad.
 * - A live server switch does not count as a new session.
 * - Tapsell is accessed defensively so a vendor API/runtime failure cannot
 *   crash the VPN app or break connection state.
 */
object BlueVpnTapsellManager {
    private const val TAG = "BlueVpnTapsell"
    private const val PREFS = "bluevpn_tapsell_runtime"
    private const val KEY_LAST_SESSION = "last_shown_session"
    private const val KEY_LAST_SHOWN_AT = "last_shown_at"
    private const val KEY_DAY = "shown_day"
    private const val KEY_DAY_COUNT = "shown_day_count"
    private const val CONFIG_CACHE_MS = 60_000L

    private data class Config(
        val enabled: Boolean,
        val appKey: String,
        val zoneId: String,
        val showAfterConnect: Boolean,
        val minIntervalSeconds: Int,
        val dailyCap: Int,
    ) {
        val valid: Boolean
            get() = enabled && showAfterConnect && appKey.isNotBlank() && zoneId.isNotBlank()
    }

    private val main = Handler(Looper.getMainLooper())
    private val io = Executors.newSingleThreadExecutor { task ->
        Thread(task, "bluevpn-tapsell").apply { isDaemon = true }
    }
    private val configLoading = AtomicBoolean(false)
    private val adRequesting = AtomicBoolean(false)

    @Volatile private var config: Config? = null
    @Volatile private var configLoadedAt = 0L
    @Volatile private var initializedKey = ""
    @Volatile private var initializing = false
    @Volatile private var readyResponseId = ""
    @Volatile private var pendingSessionId = 0L
    @Volatile private var pendingActivity: WeakReference<Activity>? = null

    fun warmUp(context: Context) {
        val app = context.applicationContext
        if (!BlueVpnEntitlement.resolveUi(app).isFree) {
            cancelPending()
            return
        }
        loadConfig(app, force = false) { loaded ->
            if (loaded.valid && BlueVpnEntitlement.resolveUi(app).isFree) {
                ensureInitialized(app, loaded)
            }
        }
    }

    fun onVerifiedConnection(activity: Activity, sessionId: Long) {
        if (activity.isFinishing || activity.isDestroyed || sessionId <= 0L) return
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
                return@loadConfig
            }
            main.post {
                val target = pendingActivity?.get()
                if (target == null || target.isFinishing || target.isDestroyed) {
                    cancelPending()
                    return@post
                }
                if (readyResponseId.isNotBlank()) {
                    showReadyAd(target, loaded)
                } else {
                    ensureInitialized(app, loaded)
                    requestInterstitial(app, loaded)
                }
            }
        }
    }

    fun onEntitlementChanged(context: Context) {
        if (!BlueVpnEntitlement.resolveUi(context).isFree) cancelPending()
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
        appKey = value.optString("app_key", "").trim(),
        zoneId = value.optString("interstitial_zone_id", "").trim(),
        showAfterConnect = value.optBoolean("show_after_connect", true),
        minIntervalSeconds = value.optInt("min_interval_seconds", 0).coerceIn(0, 86_400),
        dailyCap = value.optInt("daily_cap", 0).coerceIn(0, 1_000),
    )

    private fun ensureInitialized(context: Context, loaded: Config) {
        if (!loaded.valid || !BlueVpnEntitlement.resolveUi(context).isFree) return
        if (initializedKey == loaded.appKey && !initializing) {
            requestInterstitial(context, loaded)
            return
        }
        if (initializing && initializedKey == loaded.appKey) return
        initializedKey = loaded.appKey
        initializing = true
        readyResponseId = ""

        runCatching {
            val sdk = Class.forName("ir.tapsell.plus.TapsellPlus")
            val method = sdk.methods.firstOrNull {
                it.name == "initialize" && it.parameterTypes.size in 2..3
            } ?: error("TapsellPlus.initialize not found")
            val args = mutableListOf<Any?>(context, loaded.appKey)
            if (method.parameterTypes.size == 3) {
                args += callbackProxy(method.parameterTypes[2]) { name, callbackArgs ->
                    if (isFailureCallback(name)) {
                        initializing = false
                        Log.w(TAG, "Tapsell initialization failed: ${describeArgs(callbackArgs)}")
                    } else if (isSuccessCallback(name)) {
                        initializing = false
                        requestInterstitial(context, loaded)
                    }
                }
            }
            method.invoke(null, *args.toTypedArray())
            if (method.parameterTypes.size == 2) {
                initializing = false
                requestInterstitial(context, loaded)
            } else {
                // Some SDK/network combinations do not invoke the aggregate
                // callback quickly. A guarded retry is safe and non-blocking.
                main.postDelayed({
                    if (initializing && initializedKey == loaded.appKey) {
                        initializing = false
                        requestInterstitial(context, loaded)
                    }
                }, 2_500L)
            }
        }.onFailure {
            initializing = false
            Log.w(TAG, "Tapsell initialization unavailable", it)
        }
    }

    private fun requestInterstitial(context: Context, loaded: Config) {
        if (!loaded.valid || readyResponseId.isNotBlank()) return
        if (!BlueVpnEntitlement.resolveUi(context).isFree) return
        if (!adRequesting.compareAndSet(false, true)) return

        runCatching {
            val sdk = Class.forName("ir.tapsell.plus.TapsellPlus")
            val method = sdk.methods.firstOrNull {
                it.name == "requestInterstitialAd" && it.parameterTypes.size >= 3
            } ?: error("TapsellPlus.requestInterstitialAd not found")
            val listenerType = method.parameterTypes.last()
            val listener = callbackProxy(listenerType) { name, callbackArgs ->
                if (isFailureCallback(name)) {
                    adRequesting.set(false)
                    Log.w(TAG, "Interstitial request failed: ${describeArgs(callbackArgs)}")
                    return@callbackProxy
                }
                val response = extractResponseId(callbackArgs)
                if (response.isNotBlank()) {
                    readyResponseId = response
                    adRequesting.set(false)
                    val target = pendingActivity?.get()
                    if (target != null && !target.isFinishing && !target.isDestroyed) {
                        showReadyAd(target, loaded)
                    }
                }
            }
            val args = buildInvocationArgs(
                method = method,
                context = context,
                stringValues = listOf(loaded.zoneId),
                listener = listener,
            )
            method.invoke(null, *args)
        }.onFailure {
            adRequesting.set(false)
            Log.w(TAG, "Interstitial request unavailable", it)
        }
    }

    private fun showReadyAd(activity: Activity, loaded: Config) {
        if (Looper.myLooper() != Looper.getMainLooper()) {
            main.post { showReadyAd(activity, loaded) }
            return
        }
        val responseId = readyResponseId
        val sessionId = pendingSessionId
        if (responseId.isBlank() || sessionId <= 0L) return
        if (!BlueVpnEntitlement.resolveUi(activity).isFree) {
            cancelPending()
            return
        }
        if (!eligibleForSession(activity, loaded, sessionId)) {
            pendingSessionId = 0L
            pendingActivity = null
            return
        }

        runCatching {
            val sdk = Class.forName("ir.tapsell.plus.TapsellPlus")
            val method = sdk.methods.firstOrNull {
                it.name == "showInterstitialAd" && it.parameterTypes.size >= 3
            } ?: error("TapsellPlus.showInterstitialAd not found")
            val listener = callbackProxy(method.parameterTypes.last()) { name, callbackArgs ->
                when {
                    name.contains("close", true) || name.contains("dismiss", true) -> {
                        readyResponseId = ""
                        requestInterstitial(activity.applicationContext, loaded)
                    }
                    isFailureCallback(name) -> {
                        readyResponseId = ""
                        Log.w(TAG, "Interstitial show failed: ${describeArgs(callbackArgs)}")
                        requestInterstitial(activity.applicationContext, loaded)
                    }
                }
            }
            val args = buildInvocationArgs(
                method = method,
                context = activity,
                stringValues = listOf(responseId),
                listener = listener,
            )
            method.invoke(null, *args)
            markShown(activity, sessionId)
            readyResponseId = ""
            pendingSessionId = 0L
            pendingActivity = null
        }.onFailure {
            Log.w(TAG, "Interstitial show unavailable", it)
            // Keep VPN connected and retry only on the next connection.
            readyResponseId = ""
            pendingSessionId = 0L
            pendingActivity = null
        }
    }

    private fun eligibleForSession(context: Context, loaded: Config, sessionId: Long): Boolean {
        // Do not show a late-loaded ad after the user has already disconnected
        // or a newer VPN session has replaced the pending one.
        if (BlueVpnPreferences.connectedAt(context) != sessionId) return false
        val storage = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        if (storage.getLong(KEY_LAST_SESSION, 0L) == sessionId) return false
        val now = System.currentTimeMillis()
        val last = storage.getLong(KEY_LAST_SHOWN_AT, 0L)
        if (loaded.minIntervalSeconds > 0 && now - last < loaded.minIntervalSeconds * 1_000L) {
            return false
        }
        val today = SimpleDateFormat("yyyy-MM-dd", Locale.US).format(Date())
        val count = if (storage.getString(KEY_DAY, "") == today) {
            storage.getInt(KEY_DAY_COUNT, 0)
        } else 0
        return loaded.dailyCap <= 0 || count < loaded.dailyCap
    }

    private fun markShown(context: Context, sessionId: Long) {
        val storage = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val today = SimpleDateFormat("yyyy-MM-dd", Locale.US).format(Date())
        val oldCount = if (storage.getString(KEY_DAY, "") == today) {
            storage.getInt(KEY_DAY_COUNT, 0)
        } else 0
        storage.edit()
            .putLong(KEY_LAST_SESSION, sessionId)
            .putLong(KEY_LAST_SHOWN_AT, System.currentTimeMillis())
            .putString(KEY_DAY, today)
            .putInt(KEY_DAY_COUNT, oldCount + 1)
            .apply()
    }

    private fun cancelPending() {
        pendingSessionId = 0L
        pendingActivity = null
        readyResponseId = ""
        adRequesting.set(false)
    }

    private fun callbackProxy(
        listenerType: Class<*>,
        callback: (String, Array<out Any?>?) -> Unit,
    ): Any {
        require(listenerType.isInterface) { "Listener type is not an interface" }
        return Proxy.newProxyInstance(
            listenerType.classLoader,
            arrayOf(listenerType),
        ) { _, method, args ->
            callback(method.name, args)
            defaultValue(method.returnType)
        }
    }

    private fun buildInvocationArgs(
        method: Method,
        context: Context,
        stringValues: List<String>,
        listener: Any,
    ): Array<Any?> {
        var stringIndex = 0
        return method.parameterTypes.map { type ->
            when {
                Context::class.java.isAssignableFrom(type) -> context
                type == String::class.java -> stringValues.getOrElse(stringIndex++) { "" }
                type.isInterface -> listener
                type == Boolean::class.javaPrimitiveType || type == Boolean::class.java -> false
                type == Int::class.javaPrimitiveType || type == Int::class.java -> 0
                else -> null
            }
        }.toTypedArray()
    }

    private fun extractResponseId(args: Array<out Any?>?): String {
        args.orEmpty().forEach { value ->
            if (value is String && value.isNotBlank()) return value
            if (value != null) {
                val getter = value.javaClass.methods.firstOrNull {
                    it.parameterCount == 0 && it.name.lowercase() in setOf(
                        "getresponseid", "getresponse_id", "getid"
                    )
                }
                val extracted = runCatching { getter?.invoke(value) as? String }.getOrNull().orEmpty()
                if (extracted.isNotBlank()) return extracted
            }
        }
        return ""
    }

    private fun isFailureCallback(name: String): Boolean =
        name.contains("error", true) || name.contains("fail", true)

    private fun isSuccessCallback(name: String): Boolean =
        name.contains("success", true) || name.contains("initialize", true) || name.contains("response", true)

    private fun describeArgs(args: Array<out Any?>?): String =
        args.orEmpty().joinToString(" | ") { it?.toString().orEmpty().take(180) }

    private fun defaultValue(type: Class<*>): Any? = when (type) {
        Boolean::class.javaPrimitiveType -> false
        Byte::class.javaPrimitiveType -> 0.toByte()
        Short::class.javaPrimitiveType -> 0.toShort()
        Int::class.javaPrimitiveType -> 0
        Long::class.javaPrimitiveType -> 0L
        Float::class.javaPrimitiveType -> 0f
        Double::class.javaPrimitiveType -> 0.0
        Char::class.javaPrimitiveType -> '\u0000'
        else -> null
    }
}
