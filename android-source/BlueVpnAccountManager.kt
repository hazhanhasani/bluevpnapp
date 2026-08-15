package com.v2ray.ang.bluevpn

import android.app.AlarmManager
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.os.Build
import android.provider.Settings
import com.v2ray.ang.BuildConfig
import com.v2ray.ang.core.CoreServiceManager
import com.v2ray.ang.dto.entities.SubscriptionItem
import com.v2ray.ang.handler.MmkvManager
import org.json.JSONArray
import org.json.JSONObject
import java.io.IOException
import java.net.ConnectException
import java.net.HttpURLConnection
import java.net.SocketTimeoutException
import java.net.UnknownHostException
import java.net.URL
import java.security.MessageDigest
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Locale
import java.util.TimeZone
import java.util.UUID
import java.util.concurrent.Executors
import java.util.concurrent.atomic.AtomicBoolean
import java.util.concurrent.atomic.AtomicLong

data class BlueVpnAccountSnapshot(
    val email: String,
    val subscriptionActive: Boolean,
    val subscriptionUrl: String,
    val status: String,
    val expire: String?,
    val expireFa: String?,
    val dataLimitBytes: Long,
    val usedTrafficBytes: Long,
    val deviceLimit: Int,
    val syncError: String,
    val phoneVerified: Boolean,
    val authMethod: String,
    val poolIdentity: String,
)

data class BlueVpnOtpRequest(
    val challengeId: String,
    val phone: String,
    val expiresInSeconds: Int,
    val resendAfterSeconds: Int,
)

data class BlueVpnFreeSubscription(
    val id: String,
    val name: String,
    val url: String,
    val priority: Int,
)

data class BlueVpnFreeAccessSnapshot(
    val enabled: Boolean,
    val subscriptionUrl: String,
    val subscriptions: List<BlueVpnFreeSubscription>,
    val sessionMinutes: Int,
    val warpEnabled: Boolean,
    val warpMode: String,
    val warpFallbackEnabled: Boolean,
    val warpStartTimeoutSeconds: Int,
    val warpWarmTimeoutSeconds: Int,
    val warpColdTimeoutSeconds: Int,
    val warpTotalTimeoutSeconds: Int,
    val warpQuickReconnect: Boolean,
    val warpAdaptiveEnabled: Boolean,
    val warpAllowedTransports: Set<String>,
    val warpScanMode: String,
    val warpIpMode: String,
    val warpH2Enabled: Boolean,
    val warpFragmentEnabled: Boolean,
    val warpFragmentSize: String,
    val warpFragmentDelay: String,
    val warpWireGuardEnabled: Boolean,
    val warpGoolEnabled: Boolean,
    val warpNoizeProfile: String,
    val guestAllowed: Boolean,
)

object BlueVpnPersianDate {
    private val tehran = TimeZone.getTimeZone("Asia/Tehran")
    private val persianDigits = mapOf(
        '0' to '۰', '1' to '۱', '2' to '۲', '3' to '۳', '4' to '۴',
        '5' to '۵', '6' to '۶', '7' to '۷', '8' to '۸', '9' to '۹',
    )

    fun formatIso(raw: String?, includeTime: Boolean = true): String? {
        val value = raw?.trim().orEmpty()
        if (value.isBlank() || value == "null") return null
        if (value.startsWith("9999-")) return "نامحدود"
        val normalized = value.replace(
            Regex("\\.(\\d{3})\\d+([+-]\\d{2}:\\d{2}|Z)$"),
            ".$1$2",
        )
        val patterns = listOf(
            "yyyy-MM-dd'T'HH:mm:ss.SSSXXX",
            "yyyy-MM-dd'T'HH:mm:ssXXX",
            "yyyy-MM-dd'T'HH:mm:ss.SSS'Z'",
            "yyyy-MM-dd'T'HH:mm:ss'Z'",
            "yyyy-MM-dd",
        )
        val millis = patterns.firstNotNullOfOrNull { pattern ->
            runCatching {
                SimpleDateFormat(pattern, Locale.US).apply {
                    timeZone = TimeZone.getTimeZone("UTC")
                    isLenient = false
                }.parse(normalized)?.time
            }.getOrNull()
        } ?: return null
        val calendar = Calendar.getInstance(tehran).apply { timeInMillis = millis }
        val (jy, jm, jd) = gregorianToJalali(
            calendar.get(Calendar.YEAR),
            calendar.get(Calendar.MONTH) + 1,
            calendar.get(Calendar.DAY_OF_MONTH),
        )
        val date = "%04d/%02d/%02d".format(Locale.US, jy, jm, jd)
        val result = if (includeTime) {
            val time = "%02d:%02d".format(
                Locale.US,
                calendar.get(Calendar.HOUR_OF_DAY),
                calendar.get(Calendar.MINUTE),
            )
            "$date، ساعت $time"
        } else {
            date
        }
        return result.map { persianDigits[it] ?: it }.joinToString("")
    }

    private fun gregorianToJalali(gy: Int, gm: Int, gd: Int): Triple<Int, Int, Int> {
        val gDays = intArrayOf(0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334)
        val gy2 = if (gm > 2) gy + 1 else gy
        var days = 355666 + 365 * gy + (gy2 + 3) / 4 -
            (gy2 + 99) / 100 + (gy2 + 399) / 400 + gd + gDays[gm - 1]
        var jy = -1595 + 33 * (days / 12053)
        days %= 12053
        jy += 4 * (days / 1461)
        days %= 1461
        if (days > 365) {
            jy += (days - 1) / 365
            days = (days - 1) % 365
        }
        val jm: Int
        val jd: Int
        if (days < 186) {
            jm = 1 + days / 31
            jd = 1 + days % 31
        } else {
            jm = 7 + (days - 186) / 30
            jd = 1 + (days - 186) % 30
        }
        return Triple(jy, jm, jd)
    }
}

object BlueVpnAccountManager {
    private val refreshLock = Any()
    private val accountSyncLock = Any()
    private val subscriptionReconcileLock = Any()
    @Volatile private var subscriptionRefreshRunning = false
    private val backgroundExecutor = Executors.newSingleThreadExecutor { task ->
        Thread(task, "bluevpn-account-background").apply { isDaemon = true }
    }
    private val subscriptionInstallExecutor = Executors.newSingleThreadExecutor { task ->
        Thread(task, "bluevpn-subscription-install").apply { isDaemon = true }
    }
    @Volatile private var lastForcedAccountSyncAt = 0L
    private val primaryRestored = AtomicBoolean(false)
    private val primaryRestoreLock = Any()
    private val authStateLock = Any()
    private val authSessionEpoch = AtomicLong(0L)
    private val freePrepareLock = Any()
    private val mobileConfigLock = Any()
    private val profileOwnershipLock = Any()
    private val plansLock = Any()
    @Volatile private var freePrepareRunning = false
    @Volatile private var freeSnapshotCacheAt = 0L
    @Volatile private var freeSnapshotCache: BlueVpnFreeAccessSnapshot? = null
    @Volatile private var accountSnapshotCacheAt = 0L
    @Volatile private var accountSnapshotCache: BlueVpnAccountSnapshot? = null
    @Volatile private var mobileConfigCacheAt = 0L
    @Volatile private var mobileConfigCacheRaw = ""
    @Volatile private var plansCacheAt = 0L
    @Volatile private var plansCacheRaw = ""

    private const val P = "bluevpn_account"
    private const val BACKUP = "bluevpn_auth_backup"
    private const val SUB = "BlueVPN Account"
    private const val FREE_SUB = "BlueVPN Free"
    private const val FREE_PREFS = "bluevpn_free_access"
    private const val PREMIUM_LKG_PREFS = "bluevpn_premium_lkg"
    private const val OWNERSHIP_PREFS = "bluevpn_profile_ownership"
    private const val KEY_PENDING_ENTITLEMENT_RECONCILE = "pending_entitlement_reconcile"
    private const val KEY_PREMIUM_BOUNDARY_FINGERPRINTS = "premium_boundary_fingerprints"
    private const val KEY_PREMIUM_BOUNDARY_SAVED_AT = "premium_boundary_saved_at"
    private const val KEY_EVER_FREE_FINGERPRINTS = "ever_free_fingerprints"
    private const val KEY_EVER_PREMIUM_FINGERPRINTS = "ever_premium_fingerprints"
    private const val KEY_OWNER_MAP_JSON = "owner_map_json"
    private const val FREE_ALARM_ACTION = "com.v2ray.ang.bluevpn.FREE_SESSION_EXPIRED"
    private const val AUTO_SYNC_INTERVAL_MS = 5 * 60_000L
    private const val FREE_SUB_REFRESH_INTERVAL_MS = 60 * 60_000L
    private const val FREE_CONFIG_TTL_MS = 5 * 60_000L
    private const val FREE_SNAPSHOT_CACHE_MS = 30_000L
    private const val ACCOUNT_SNAPSHOT_CACHE_MS = 5_000L
    private const val MOBILE_CONFIG_CACHE_MS = 60_000L
    private const val PLANS_CACHE_MS = 2 * 60_000L

    private fun prefs(c: Context) =
        c.getSharedPreferences(P, Context.MODE_PRIVATE)

    private fun backup(c: Context) =
        c.getSharedPreferences(BACKUP, Context.MODE_PRIVATE)

    private class ApiException(
        val status: Int,
        val code: String,
        message: String,
    ) : Exception(message)

    fun apiBaseUrl() =
        BuildConfig.BLUEVPN_API_BASE_URL.trimEnd('/')

    fun mobileConfig(c: Context, force: Boolean = false): Result<JSONObject> = runCatching {
        val appContext = c.applicationContext
        val now = android.os.SystemClock.elapsedRealtime()
        val cached = mobileConfigCacheRaw
        if (!force && cached.isNotBlank() && now - mobileConfigCacheAt < MOBILE_CONFIG_CACHE_MS) {
            return@runCatching JSONObject(cached)
        }
        synchronized(mobileConfigLock) {
            val lockedNow = android.os.SystemClock.elapsedRealtime()
            val lockedCached = mobileConfigCacheRaw
            if (!force && lockedCached.isNotBlank() && lockedNow - mobileConfigCacheAt < MOBILE_CONFIG_CACHE_MS) {
                return@synchronized JSONObject(lockedCached)
            }
            val path = "/api/v1/mobile/config" + if (force) "?refresh=true" else ""
            val response = if (hasSession(appContext)) {
                try {
                    // Release-channel selection is account scoped. Always use the
                    // authenticated request pipeline so an expired/missing access
                    // token can be renewed from the refresh token before WordPress
                    // decides whether this customer belongs to Beta or Stable.
                    authenticatedRequest(appContext, "GET", path, null)
                } catch (error: ApiException) {
                    // If refresh definitively invalidated the local session, fall
                    // back to public Stable metadata instead of breaking updates.
                    if (error.status == 401 && !hasSession(appContext)) {
                        request(appContext, "GET", path, null, false)
                    } else {
                        throw error
                    }
                }
            } else {
                request(appContext, "GET", path, null, false)
            }
            applyRemoteMobileConfig(appContext, response)
            mobileConfigCacheRaw = response.toString()
            mobileConfigCacheAt = lockedNow
            JSONObject(mobileConfigCacheRaw)
        }
    }

    /**
     * Apply server-authored Free policy from any successful /mobile/config response.
     * BlueVpnUpdateManager reuses mobileConfig(), so this remains the single
     * persistence path for server-authored Free policy and update metadata.
     */
    fun applyRemoteMobileConfig(c: Context, config: JSONObject): Boolean {
        val appContext = c.applicationContext
        val free = config.optJSONObject("free_access") ?: return false
        val storage = freePrefs(appContext)
        val sources = free.optJSONArray("subscriptions") ?: JSONArray()
        val storedSources = JSONArray()
        for (index in 0 until sources.length()) {
            val row = sources.optJSONObject(index) ?: continue
            val url = row.optString("subscription_url").trim()
            if (!url.startsWith("http")) continue
            storedSources.put(
                JSONObject()
                    .put("id", row.optString("id").trim().ifBlank { "source-$index" })
                    .put("name", row.optString("name").trim().ifBlank { "سرور رایگان ${index + 1}" })
                    .put("url", url)
                    .put("priority", row.optInt("priority", index))
            )
        }
        val legacyUrl = free.optString("subscription_url").trim()
        if (storedSources.length() == 0 && legacyUrl.startsWith("http")) {
            storedSources.put(
                JSONObject()
                    .put("id", "legacy-default")
                    .put("name", "سرور رایگان")
                    .put("url", legacyUrl)
                    .put("priority", 0)
            )
        }

        val warp = free.optJSONObject("warp") ?: JSONObject()
        val warpMode = warp.optString("mode", free.optString("engine_mode", "warp_fallback_pool"))
            .trim().lowercase()
            .takeIf { it in setOf("warp_only", "warp_fallback_pool", "pool_only") }
            ?: "warp_fallback_pool"
        val warpEnabled = warp.optBoolean("enabled", warpMode != "pool_only")
        val warpFallbackEnabled = warp.optBoolean("fallback_pool_enabled", warpMode == "warp_fallback_pool")
        val warpStartTimeoutSeconds = warp.optInt("start_timeout_seconds", 7).coerceIn(3, 40)
        val warpWarmTimeoutSeconds = warp.optInt("warm_timeout_seconds", 8).coerceIn(4, 12)
        val warpColdTimeoutSeconds = warp.optInt("cold_timeout_seconds", 30).coerceIn(15, 40)
        val warpTotalTimeoutSeconds = warp.optInt("total_timeout_seconds", 75).coerceIn(30, 90)
        val warpQuickReconnect = warp.optBoolean("quick_reconnect", true)
        val warpAdaptiveEnabled = warp.optBoolean("adaptive_strategy_enabled", true)
        val allowedJson = warp.optJSONArray("allowed_transports")
        val warpAllowedTransports = buildSet {
            if (allowedJson != null) for (i in 0 until allowedJson.length()) allowedJson.optString(i).trim().lowercase().takeIf { it in setOf("h3","h2","h2_fragment","wireguard","gool") }?.let(::add)
            if (isEmpty()) addAll(setOf("h3","h2","h2_fragment"))
        }
        val warpScanMode = warp.optString("scan_mode", "balanced").trim().lowercase().takeIf { it in setOf("turbo","balanced","thorough","stealth","ironclad") } ?: "balanced"
        val warpIpMode = warp.optString("ip_mode", "auto").trim().lowercase().takeIf { it in setOf("auto","v4","dual") } ?: "auto"
        val warpH2Enabled = warp.optBoolean("h2_enabled", true)
        val warpFragmentEnabled = warp.optBoolean("fragment_enabled", true)
        val warpFragmentSize = warp.optString("fragment_size", "8-24").trim().takeIf { it.matches(Regex("\\d{1,3}(-\\d{1,3})?")) } ?: "8-24"
        val warpFragmentDelay = warp.optString("fragment_delay", "5-15").trim().takeIf { it.matches(Regex("\\d{1,3}(-\\d{1,3})?")) } ?: "5-15"
        val warpWireGuardEnabled = warp.optBoolean("wireguard_enabled", false)
        val warpGoolEnabled = warp.optBoolean("warp_in_warp_enabled", false)
        val warpNoizeProfile = warp.optString("noize_profile", "firewall").trim().lowercase().takeIf { it in setOf("off","light","balanced","aggressive","firewall","gfw") } ?: "firewall"
        val guestAllowed = free.optBoolean("guest_allowed", true)
        val oldMinutes = storage.getInt("session_minutes", 60).coerceIn(15, 180)
        val newMinutes = free.optInt("session_minutes", 60).coerceIn(15, 180)
        val now = System.currentTimeMillis()
        storage.edit()
            .putBoolean("enabled", free.optBoolean("enabled", warpEnabled))
            .putBoolean("warp_enabled", warpEnabled)
            .putString("warp_mode", warpMode)
            .putBoolean("warp_fallback_enabled", warpFallbackEnabled)
            .putInt("warp_start_timeout_seconds", warpStartTimeoutSeconds)
            .putInt("warp_warm_timeout_seconds", warpWarmTimeoutSeconds)
            .putInt("warp_cold_timeout_seconds", warpColdTimeoutSeconds)
            .putInt("warp_total_timeout_seconds", warpTotalTimeoutSeconds)
            .putBoolean("warp_quick_reconnect", warpQuickReconnect)
            .putBoolean("warp_adaptive_enabled", warpAdaptiveEnabled)
            .putStringSet("warp_allowed_transports", warpAllowedTransports)
            .putString("warp_scan_mode", warpScanMode)
            .putString("warp_ip_mode", warpIpMode)
            .putBoolean("warp_h2_enabled", warpH2Enabled)
            .putBoolean("warp_fragment_enabled", warpFragmentEnabled)
            .putString("warp_fragment_size", warpFragmentSize)
            .putString("warp_fragment_delay", warpFragmentDelay)
            .putBoolean("warp_wireguard_enabled", warpWireGuardEnabled)
            .putBoolean("warp_gool_enabled", warpGoolEnabled)
            .putString("warp_noize_profile", warpNoizeProfile)
            .putBoolean("guest_allowed", guestAllowed)
            .putString("subscription_url", legacyUrl)
            .putString("subscriptions_json", storedSources.toString())
            .putInt("session_minutes", newMinutes)
            .putLong("config_at", now)
            .commit()
        invalidateFreeSnapshot()

        // A server-side reduction (for example 60 -> 30 minutes) is authoritative
        // even for an already-running Free session. Never extend an active session
        // when the admin increases the limit; the new value applies on next connect.
        if (newMinutes < oldMinutes && storage.getBoolean("session_active", false)) {
            val startedAt = storage.getLong("session_started_at", 0L)
            val currentEnd = storage.getLong("session_ends_at", 0L)
            if (startedAt > 0L && currentEnd > 0L) {
                val allowedEnd = startedAt + newMinutes * 60_000L
                if (currentEnd > allowedEnd) {
                    storage.edit().putLong("session_ends_at", allowedEnd).commit()
                    if (allowedEnd > now) scheduleFreeAlarm(appContext, allowedEnd)
                    else enforceFreeSession(appContext)
                }
            }
        }
        return true
    }

    fun refreshFreePolicy(c: Context, force: Boolean = false): Result<BlueVpnFreeAccessSnapshot> = runCatching {
        mobileConfig(c.applicationContext, force).getOrThrow()
        freeAccessSnapshot(c.applicationContext)
    }

    private fun restorePrimary(c: Context) {
        if (primaryRestored.get()) return
        synchronized(primaryRestoreLock) {
            if (primaryRestored.get()) return
            val primary = prefs(c)
            val secondary = backup(c)
            val editor = primary.edit()
            var changed = false

            fun restoreString(key: String) {
                if (primary.getString(key, "").orEmpty().isNotBlank()) return
                val saved = secondary.getString(key, "").orEmpty()
                if (saved.isNotBlank()) {
                    editor.putString(key, saved)
                    changed = true
                }
            }

            restoreString("token")
            restoreString("refresh_token")
            restoreString("email")
            restoreString("device_id")
            if (changed) editor.apply()
            primaryRestored.set(true)
        }
    }

    private fun invalidateAccountSnapshot() {
        accountSnapshotCacheAt = 0L
        accountSnapshotCache = null
    }

    private fun invalidateMobileConfigCache() {
        synchronized(mobileConfigLock) {
            mobileConfigCacheAt = 0L
            mobileConfigCacheRaw = ""
        }
    }

    private fun invalidateFreeSnapshot() {
        freeSnapshotCacheAt = 0L
        freeSnapshotCache = null
    }

    private fun persistAuth(
        c: Context,
        token: String,
        refreshToken: String,
        email: String,
        newSession: Boolean = false,
        expectedEpoch: Long? = null,
    ): Boolean = synchronized(authStateLock) {
        if (expectedEpoch != null && authSessionEpoch.get() != expectedEpoch) {
            return@synchronized false
        }
        if (newSession) authSessionEpoch.incrementAndGet()
        val device = deviceId(c)

        prefs(c).edit()
            .putString("token", token)
            .putString("refresh_token", refreshToken)
            .putString("email", email)
            .putString("device_id", device)
            .remove("auth_error")
            .commit()

        backup(c).edit()
            .putString("token", token)
            .putString("refresh_token", refreshToken)
            .putString("email", email)
            .putString("device_id", device)
            .putLong("saved_at", System.currentTimeMillis())
            .commit()
        invalidateAccountSnapshot()
        invalidateMobileConfigCache()
        true
    }

    fun token(c: Context): String {
        restorePrimary(c)
        return prefs(c).getString("token", "").orEmpty()
    }

    private fun refreshToken(c: Context): String {
        restorePrimary(c)
        return prefs(c).getString(
            "refresh_token",
            ""
        ).orEmpty()
    }

    fun hasSession(c: Context): Boolean =
        token(c).isNotBlank() || refreshToken(c).isNotBlank()

    fun active(c: Context) =
        prefs(c).getBoolean("active", false)

    /** Premium entitlement is valid only while an authenticated app session exists. */
    fun premiumEntitlementActive(c: Context): Boolean =
        hasSession(c) && active(c)

    fun entitlementReconcilePending(c: Context): Boolean =
        prefs(c).getBoolean(KEY_PENDING_ENTITLEMENT_RECONCILE, false)

    private fun setEntitlementReconcilePending(c: Context, pending: Boolean) {
        val edit = prefs(c).edit()
        if (pending) edit.putBoolean(KEY_PENDING_ENTITLEMENT_RECONCILE, true)
        else edit.remove(KEY_PENDING_ENTITLEMENT_RECONCILE)
        edit.apply()
    }

    fun entitlement(c: Context): BlueVpnEntitlementSnapshot =
        BlueVpnEntitlement.resolve(c)

    private fun freePrefs(c: Context) =
        c.getSharedPreferences(FREE_PREFS, Context.MODE_PRIVATE)

    /** True after mobile config has explicitly declared whether Free access is enabled. */
    fun freeAccessConfigured(c: Context): Boolean =
        freePrefs(c.applicationContext).contains("enabled")

    fun freeAccessSnapshot(c: Context): BlueVpnFreeAccessSnapshot {
        val now = android.os.SystemClock.elapsedRealtime()
        freeSnapshotCache?.takeIf {
            freeSnapshotCacheAt > 0L && now - freeSnapshotCacheAt < FREE_SNAPSHOT_CACHE_MS
        }?.let { return it }

        val storage = freePrefs(c)
        val parsed = mutableListOf<BlueVpnFreeSubscription>()
        val raw = storage.getString("subscriptions_json", "").orEmpty()
        if (raw.isNotBlank()) {
            runCatching { JSONArray(raw) }.getOrNull()?.let { array ->
                for (index in 0 until array.length()) {
                    val row = array.optJSONObject(index) ?: continue
                    val url = row.optString("url").trim()
                    if (!url.startsWith("http")) continue
                    parsed += BlueVpnFreeSubscription(
                        id = row.optString("id").trim().ifBlank { "source-$index" },
                        name = row.optString("name").trim().ifBlank { "سرور رایگان ${index + 1}" },
                        url = url,
                        priority = row.optInt("priority", index),
                    )
                }
            }
        }
        val legacyUrl = storage.getString("subscription_url", "").orEmpty().trim()
        if (parsed.isEmpty() && legacyUrl.startsWith("http")) {
            parsed += BlueVpnFreeSubscription(
                id = "legacy-default",
                name = "سرور رایگان",
                url = legacyUrl,
                priority = 0,
            )
        }
        val ordered = parsed.distinctBy { it.id }.sortedBy { it.priority }
        // 4.6.5 migration rule: WARP is the primary Free engine. Devices
        // upgrading from 4.6.4 do not have warp_enabled yet, so default it to
        // true until the authoritative mobile/config response is persisted.
        val warpMode = storage.getString("warp_mode", "warp_fallback_pool").orEmpty()
            .takeIf { it in setOf("warp_only", "warp_fallback_pool", "pool_only") }
            ?: "warp_fallback_pool"
        val warpEnabled = if (storage.contains("warp_enabled")) {
            storage.getBoolean("warp_enabled", true)
        } else {
            warpMode != "pool_only"
        }
        val snapshot = BlueVpnFreeAccessSnapshot(
            enabled = storage.getBoolean("enabled", warpEnabled) || warpEnabled,
            subscriptionUrl = ordered.firstOrNull()?.url.orEmpty(),
            subscriptions = ordered,
            sessionMinutes = storage.getInt("session_minutes", 60).coerceIn(15, 180),
            warpEnabled = warpEnabled,
            warpMode = warpMode,
            warpFallbackEnabled = storage.getBoolean("warp_fallback_enabled", warpMode == "warp_fallback_pool"),
            warpStartTimeoutSeconds = storage.getInt("warp_start_timeout_seconds", 7).coerceIn(3, 40),
            warpWarmTimeoutSeconds = storage.getInt("warp_warm_timeout_seconds", 8).coerceIn(4, 12),
            warpColdTimeoutSeconds = storage.getInt("warp_cold_timeout_seconds", 30).coerceIn(15, 40),
            warpTotalTimeoutSeconds = storage.getInt("warp_total_timeout_seconds", 75).coerceIn(30, 90),
            warpQuickReconnect = storage.getBoolean("warp_quick_reconnect", true),
            warpAdaptiveEnabled = storage.getBoolean("warp_adaptive_enabled", true),
            warpAllowedTransports = storage.getStringSet("warp_allowed_transports", setOf("h3","h2","h2_fragment")).orEmpty().filter { it in setOf("h3","h2","h2_fragment","wireguard","gool") }.toSet().ifEmpty { setOf("h3","h2","h2_fragment") },
            warpScanMode = storage.getString("warp_scan_mode", "balanced").orEmpty().takeIf { it in setOf("turbo","balanced","thorough","stealth","ironclad") } ?: "balanced",
            warpIpMode = storage.getString("warp_ip_mode", "auto").orEmpty().takeIf { it in setOf("auto","v4","dual") } ?: "auto",
            warpH2Enabled = storage.getBoolean("warp_h2_enabled", true),
            warpFragmentEnabled = storage.getBoolean("warp_fragment_enabled", true),
            warpFragmentSize = storage.getString("warp_fragment_size", "8-24").orEmpty().ifBlank { "8-24" },
            warpFragmentDelay = storage.getString("warp_fragment_delay", "5-15").orEmpty().ifBlank { "5-15" },
            warpWireGuardEnabled = storage.getBoolean("warp_wireguard_enabled", false),
            warpGoolEnabled = storage.getBoolean("warp_gool_enabled", false),
            warpNoizeProfile = storage.getString("warp_noize_profile", "firewall").orEmpty().ifBlank { "firewall" },
            guestAllowed = storage.getBoolean("guest_allowed", true),
        )
        freeSnapshotCache = snapshot
        freeSnapshotCacheAt = now
        return snapshot
    }

    fun freeAccessEnabled(c: Context): Boolean {
        val snapshot = freeAccessSnapshot(c)
        val warpReadyByPolicy = snapshot.warpEnabled && snapshot.warpMode != "pool_only"
        val legacyPoolReadyByPolicy = snapshot.subscriptions.isNotEmpty() && snapshot.warpMode != "warp_only"
        return snapshot.enabled && (warpReadyByPolicy || legacyPoolReadyByPolicy)
    }

    fun warpFreeEnabled(c: Context): Boolean {
        val snapshot = freeAccessSnapshot(c)
        return snapshot.enabled && snapshot.warpEnabled && snapshot.warpMode != "pool_only"
    }

    fun warpFallbackEnabled(c: Context): Boolean {
        val snapshot = freeAccessSnapshot(c)
        return snapshot.enabled && snapshot.warpFallbackEnabled && snapshot.warpMode == "warp_fallback_pool"
    }

    fun isFreeMode(c: Context): Boolean =
        !premiumEntitlementActive(c) && freeAccessEnabled(c)

    fun hasInstalledFreeServers(c: Context): Boolean {
        val storage = freePrefs(c.applicationContext)
        val guids = storage.getStringSet("subscription_guids", emptySet()).orEmpty()
            .ifEmpty {
                storage.getString("subscription_guid", "").orEmpty()
                    .takeIf { it.isNotBlank() }
                    ?.let { setOf(it) }
                    .orEmpty()
            }
        if (guids.isEmpty()) return false
        // "Installed" is not enough after Premium -> logout. Premium mode leaves
        // the Free profiles cached but disables their subscription rows. Treat a
        // cached-but-disabled pool as not ready so prepareFreeAccess() re-enables
        // the exact configured Free subscriptions before any connect attempt.
        val enabledFree = MmkvManager.decodeSubscriptions()
            .asSequence()
            .filter { it.subscription.enabled && it.subscription.remarks.startsWith(FREE_SUB) }
            .map { it.guid.trim() }
            .filter { it.isNotBlank() }
            .toSet()
        return guids.any { guid ->
            guid in enabledFree &&
                runCatching { MmkvManager.decodeServerList(guid).isNotEmpty() }
                    .getOrDefault(false)
        }
    }

    fun prepareFreeAccess(c: Context, force: Boolean = false): Result<Boolean> = runCatching {
        val appContext = c.applicationContext
        val ownsPreparation = synchronized(freePrepareLock) {
            if (freePrepareRunning) false else {
                freePrepareRunning = true
                true
            }
        }
        if (!ownsPreparation) return@runCatching hasInstalledFreeServers(appContext)
        try {

            val storage = freePrefs(appContext)
            val now = System.currentTimeMillis()
            val lastConfigAt = storage.getLong("config_at", 0L)
            val shouldFetch = force || now - lastConfigAt > FREE_CONFIG_TTL_MS ||
                freeAccessSnapshot(appContext).subscriptions.isEmpty()

            if (shouldFetch) {
                // mobileConfig() is the canonical Free-policy persistence path.
                // Do not duplicate parsing here or session-limit changes can get
                // out of sync with update-check/foreground refresh responses.
                mobileConfig(appContext, force = force).getOrThrow()
            }

            if (premiumEntitlementActive(appContext)) {
                stopFreeSession(appContext, expired = false)
                return@runCatching false
            }

            val snapshot = freeAccessSnapshot(appContext)
            if (!snapshot.enabled || snapshot.subscriptions.isEmpty()) {
                return@runCatching false
            }

            val fingerprint = snapshot.subscriptions
                .joinToString("|") { "${it.id}:${it.url}:${it.priority}" }
                .hashCode().toString()
            val installedFingerprint = storage
                .getString("installed_sources_fingerprint", "")
                .orEmpty()
            val localReady = hasInstalledFreeServers(appContext)
            if (force || !localReady || installedFingerprint != fingerprint) {
                val installed = installFreeSubscriptions(appContext, snapshot.subscriptions, force)
                if (installed) {
                    storage.edit()
                        .putString("installed_sources_fingerprint", fingerprint)
                        .apply()
                } else if (BlueVpnRuntimeGate.connectionActive(appContext)) {
                    setEntitlementReconcilePending(appContext, true)
                }
            }
            BlueVpnPreferences.setAutomaticSelection(appContext)
            val ready = hasInstalledFreeServers(appContext)
            if (ready && !hasSession(appContext)) setEntitlementReconcilePending(appContext, false)
            ready
        } finally {
            synchronized(freePrepareLock) {
                freePrepareRunning = false
            }
        }
    }

    private fun installFreeSubscriptions(
        c: Context,
        sources: List<BlueVpnFreeSubscription>,
        force: Boolean,
    ): Boolean {
        if (!BlueVpnRuntimeGate.beginSubscriptionMutation(c)) return false
        try {
        val storage = freePrefs(c)
        val existing = MmkvManager.decodeSubscriptions()
            .filter { it.subscription.remarks.startsWith(FREE_SUB) }
        val selectedFingerprint = BlueVpnProfileManager.captureSelectedFingerprint(
            existing.map { it.guid }.filter { it.isNotBlank() }.toSet(),
        )
        val desiredIds = sources.map { it.id }.toSet()
        val installedGuids = linkedSetOf<String>()
        val lastInstallAt = storage.getLong("installed_at", 0L)
        val recent = !force &&
            System.currentTimeMillis() - lastInstallAt < FREE_SUB_REFRESH_INTERVAL_MS

        sources.forEach { source ->
            val remark = "$FREE_SUB • ${source.id}"
            val old = existing.firstOrNull {
                it.subscription.remarks == remark || it.subscription.url == source.url
            }
            val unchanged = old?.subscription?.url == source.url &&
                old.subscription.enabled &&
                old.subscription.userAgent == null
            val item = old?.subscription?.copy(
                remarks = remark,
                url = source.url,
                enabled = true,
                autoUpdate = false,
                userAgent = null,
            ) ?: SubscriptionItem(
                remarks = remark,
                url = source.url,
                enabled = true,
                autoUpdate = false,
                userAgent = null,
            )
            if (!recent || !unchanged) {
                MmkvManager.encodeSubscription(old?.guid.orEmpty(), item)
            }
        }

        existing.filter { old ->
            val id = old.subscription.remarks.substringAfter("•", "").trim()
            id.isNotBlank() && id !in desiredIds
        }.forEach { old ->
            MmkvManager.encodeSubscription(
                old.guid,
                old.subscription.copy(enabled = false),
            )
        }

        val desiredUrls = sources.map { it.url.trim() }.toSet()
        val refreshRows = MmkvManager.decodeSubscriptions().filter { row ->
            row.subscription.enabled &&
                row.subscription.remarks.startsWith(FREE_SUB) &&
                row.subscription.url.trim() in desiredUrls
        }
        // A recent metadata write is NOT proof that v2rayNG has imported the
        // subscription body. In 4.5.1 an existing empty Free row could sit inside
        // FREE_SUB_REFRESH_INTERVAL_MS and therefore skip the only parser call,
        // leaving BlueVPN at 0 locations while the same URL imported ~200 nodes
        // in stock v2rayNG. Empty/incomplete rows always get an authoritative
        // stock-parser refresh now.
        val emptyOrBrokenRows = refreshRows.filter { row ->
            runCatching {
                MmkvManager.decodeServerList(row.guid).none { guid ->
                    guid.isNotBlank() && MmkvManager.decodeServerConfig(guid) != null
                }
            }.getOrDefault(true)
        }
        val sourceRowsMissing = refreshRows.size < sources.size
        if (!recent || existing.isEmpty() || emptyOrBrokenRows.isNotEmpty() || sourceRowsMissing) {
            BlueVpnSubscriptionIntelligence.refreshWithinMutation(
                c,
                refreshRows,
                aggressiveRepair = existing.isEmpty() || emptyOrBrokenRows.isNotEmpty() || sourceRowsMissing,
            )
        }
        MmkvManager.decodeSubscriptions()
            .filter { it.subscription.enabled && it.subscription.remarks.startsWith(FREE_SUB) }
            .filter { row ->
                runCatching {
                    MmkvManager.decodeServerList(row.guid).any { guid ->
                        guid.isNotBlank() && MmkvManager.decodeServerConfig(guid) != null
                    }
                }.getOrDefault(false)
            }
            .forEach { installedGuids += it.guid }
        registerFreePoolOwnership(c)
        if (selectedFingerprint != null) {
            val refreshedServerGuids = installedGuids.flatMap { subscriptionGuid ->
                runCatching { MmkvManager.decodeServerList(subscriptionGuid) }
                    .getOrDefault(emptyList())
            }
            BlueVpnProfileManager.restoreSelectedFingerprint(
                selectedFingerprint,
                refreshedServerGuids,
            )
        }
        storage.edit()
            .putStringSet("subscription_guids", installedGuids)
            .putString("subscription_guid", installedGuids.firstOrNull().orEmpty())
            .putLong("installed_at", System.currentTimeMillis())
            .commit()
        BlueVpnPoolOrchestrator.reconcile(c)
        BlueVpnLocationUtil.invalidateCache()
        return installedGuids.isNotEmpty() && installedGuids.any { subscriptionGuid ->
            runCatching { MmkvManager.decodeServerList(subscriptionGuid).isNotEmpty() }.getOrDefault(false)
        }
        } finally {
            BlueVpnRuntimeGate.endSubscriptionMutation()
        }
    }

    private fun configuredFreeSubscriptionGuids(c: Context): Set<String> {
        val storage = freePrefs(c)
        val stored = storage.getStringSet("subscription_guids", emptySet()).orEmpty()
            .ifEmpty {
                storage.getString("subscription_guid", "").orEmpty()
                    .takeIf { it.isNotBlank() }
                    ?.let { setOf(it) }
                    .orEmpty()
            }
        val configuredUrls = freeAccessSnapshot(c).subscriptions
            .map { it.url.trim() }
            .filter { it.startsWith("http") }
            .toSet()
        val installed = MmkvManager.decodeSubscriptions()
            .asSequence()
            .filter {
                it.subscription.remarks.startsWith(FREE_SUB) &&
                    (configuredUrls.isEmpty() || it.subscription.url.trim() in configuredUrls)
            }
            .map { it.guid }
            .filter { it.isNotBlank() }
            .toSet()
        return when {
            stored.isEmpty() -> installed
            installed.isEmpty() -> emptySet()
            else -> stored intersect installed
        }
    }

    private fun enabledFreeSubscriptionGuids(c: Context): Set<String> {
        val configured = configuredFreeSubscriptionGuids(c)
        if (configured.isEmpty()) return emptySet()
        val enabled = MmkvManager.decodeSubscriptions()
            .asSequence()
            .filter {
                it.subscription.enabled &&
                    it.subscription.remarks.startsWith(FREE_SUB)
            }
            .map { it.guid }
            .filter { it.isNotBlank() }
            .toSet()
        return configured intersect enabled
    }

    /**
     * Return every enabled subscription row that belongs to the exact current
     * Premium URL. Older BlueVPN Account rows can remain in MMKV after renewal
     * or provider migration; they must never be merged into the active pool.
     */
    private fun managedSubscriptionGuids(c: Context): Set<String> {
        val expectedUrl = snapshot(c).subscriptionUrl.trim()
        if (!expectedUrl.startsWith("http")) return emptySet()
        return MmkvManager.decodeSubscriptions()
            .asSequence()
            .filter {
                it.subscription.remarks == SUB &&
                    it.subscription.enabled &&
                    it.subscription.url.trim() == expectedUrl
            }
            .map { it.guid }
            .filter { it.isNotBlank() }
            .toSet()
    }

    /**
     * Authoritative current Premium pool. Unlike preferredServerGuids(), this
     * never falls back to a previously imported account pool. Readiness and
     * refresh decisions must use this exact list so a Last-Known-Good cache
     * cannot hide an empty/failed import for the current subscription URL.
     */
    private fun currentPremiumServerGuids(c: Context): List<String> =
        usableServerGuids(managedSubscriptionGuids(c))

    private fun currentPremiumPoolReady(c: Context): Boolean =
        premiumEntitlementActive(c) && currentPremiumServerGuids(c).isNotEmpty()

    /**
     * True only when the exact current entitlement already owns at least one
     * decodable profile. This intentionally does not treat an unrelated global
     * v2rayNG row as readiness and lets the UI avoid blocking on a background
     * refresh when a safe current pool is already usable.
     */
    fun hasUsableCurrentEntitlementPool(c: Context): Boolean = when {
        premiumEntitlementActive(c) -> currentPremiumServerGuids(c).isNotEmpty()
        isFreeMode(c) -> usableServerGuids(enabledFreeSubscriptionGuids(c)).isNotEmpty()
        else -> false
    }

    fun entitlementSubscriptionGuids(c: Context): Set<String> = when {
        premiumEntitlementActive(c) -> managedSubscriptionGuids(c)
        isFreeMode(c) -> enabledFreeSubscriptionGuids(c)
        else -> emptySet()
    }

    private fun isBlueVpnManagedSubscription(remarks: String?): Boolean {
        val value = remarks.orEmpty().trim()
        return value == SUB || value.startsWith(FREE_SUB)
    }

    /**
     * Every known Free subscription row, enabled or disabled. We intentionally
     * keep their physical profiles in MMKV as a last-known-good cache, but their
     * server GUIDs must never leak into a Premium fallback selection.
     */
    private fun allFreeSubscriptionGuids(): Set<String> =
        MmkvManager.decodeSubscriptions()
            .asSequence()
            .filter { it.subscription.remarks.startsWith(FREE_SUB) }
            .map { it.guid.trim() }
            .filter { it.isNotBlank() }
            .toSet()

    private fun allFreeServerGuids(): Set<String> =
        allFreeSubscriptionGuids()
            .flatMap { subscriptionGuid ->
                runCatching { MmkvManager.decodeServerList(subscriptionGuid) }
                    .getOrDefault(emptyList())
            }
            .map { it.trim() }
            .filter { it.isNotBlank() }
            .toSet()

    /**
     * Preserve the semantic endpoint identities owned by the Premium pool at
     * the exact authentication boundary. v2rayNG may regenerate GUIDs during a
     * later Free import, so GUID-only isolation is not enough to prevent a
     * freshly-imported duplicate of the just-used Premium route from leaking
     * into the first Free connection.
     */
    private fun ownershipPrefs(c: Context) =
        c.applicationContext.getSharedPreferences(OWNERSHIP_PREFS, Context.MODE_PRIVATE)

    private fun premiumOwnerTag(c: Context): String =
        premiumOwnerKey(c).takeIf { it.isNotBlank() }?.let { "PREMIUM:$it" }.orEmpty()

    private fun freeOwnerTag(sourceId: String): String = "FREE:${sourceId.trim()}"

    private fun updateOwnershipRegistry(
        c: Context,
        ownerTag: String,
        serverGuids: Collection<String>,
    ) {
        if (ownerTag.isBlank()) return
        val fingerprints = serverGuids.asSequence()
            .mapNotNull { BlueVpnProfileManager.fingerprintGuid(it) }
            .filter { it.isNotBlank() }
            .toSet()
        if (fingerprints.isEmpty()) return
        synchronized(profileOwnershipLock) {
            val storage = ownershipPrefs(c)
            val root = runCatching { JSONObject(storage.getString(KEY_OWNER_MAP_JSON, "{}").orEmpty()) }
                .getOrElse { JSONObject() }
            // Current ownership is replaceable for the same logical source/account,
            // while the cross-tier history below is intentionally permanent.
            val keys = mutableListOf<String>()
            val iterator = root.keys()
            while (iterator.hasNext()) keys += iterator.next()
            keys.forEach { fp ->
                val arr = root.optJSONArray(fp) ?: JSONArray()
                val owners = (0 until arr.length()).mapNotNull { arr.optString(it).takeIf(String::isNotBlank) }
                    .filter { it != ownerTag }
                    .toMutableSet()
                if (owners.isEmpty()) root.remove(fp)
                else root.put(fp, JSONArray(owners.toList()))
            }
            fingerprints.forEach { fp ->
                val arr = root.optJSONArray(fp) ?: JSONArray()
                val owners = (0 until arr.length()).mapNotNull { arr.optString(it).takeIf(String::isNotBlank) }
                    .toMutableSet()
                owners += ownerTag
                root.put(fp, JSONArray(owners.toList()))
            }
            val isFree = ownerTag.startsWith("FREE:")
            val historyKey = if (isFree) KEY_EVER_FREE_FINGERPRINTS else KEY_EVER_PREMIUM_FINGERPRINTS
            val history = storage.getStringSet(historyKey, emptySet()).orEmpty().toMutableSet()
            history += fingerprints
            storage.edit()
                .putString(KEY_OWNER_MAP_JSON, root.toString())
                .putStringSet(historyKey, history)
                .commit()
        }
    }

    private fun registerFreePoolOwnership(c: Context) {
        val rows = MmkvManager.decodeSubscriptions()
            .filter { it.subscription.enabled && it.subscription.remarks.startsWith(FREE_SUB) }
        rows.forEach { row ->
            val sourceId = row.subscription.remarks.substringAfter("•", "").trim()
                .ifBlank { row.guid.trim() }
            val guids = runCatching { MmkvManager.decodeServerList(row.guid) }.getOrDefault(emptyList())
            updateOwnershipRegistry(c, freeOwnerTag(sourceId), guids)
        }
    }

    private fun registerPremiumPoolOwnership(c: Context, serverGuids: Collection<String>) {
        val tag = premiumOwnerTag(c)
        if (tag.isNotBlank()) updateOwnershipRegistry(c, tag, serverGuids)
    }

    private fun ownersForFingerprint(c: Context, fingerprint: String): Set<String> = synchronized(profileOwnershipLock) {
        val raw = ownershipPrefs(c).getString(KEY_OWNER_MAP_JSON, "{}").orEmpty()
        val arr = runCatching { JSONObject(raw).optJSONArray(fingerprint) }.getOrNull() ?: return@synchronized emptySet()
        (0 until arr.length()).mapNotNull { arr.optString(it).takeIf(String::isNotBlank) }.toSet()
    }

    /**
     * Permanent semantic ownership gate. A profile that has ever belonged to the
     * opposite tier is never eligible for the current tier, even if v2rayNG later
     * regenerates its GUID or the app is restarted days later.
     */
    private fun hardIsolationAllowed(c: Context, serverGuid: String): Boolean {
        val desiredTier = if (premiumEntitlementActive(c)) {
            BlueVpnPoolOrchestrator.Tier.PREMIUM
        } else {
            BlueVpnPoolOrchestrator.Tier.FREE
        }
        return BlueVpnPoolOrchestrator.allowed(c, serverGuid, desiredTier)
    }

    private fun rememberPremiumBoundaryFingerprints(c: Context) {
        if (!premiumEntitlementActive(c)) return
        val exact = usableServerGuids(managedSubscriptionGuids(c))
        registerPremiumPoolOwnership(c, exact)
        val fingerprints = exact.mapNotNull { BlueVpnProfileManager.fingerprintGuid(it) }.toSet()
        if (fingerprints.isEmpty()) return
        freePrefs(c.applicationContext).edit()
            .putStringSet(KEY_PREMIUM_BOUNDARY_FINGERPRINTS, fingerprints)
            .putLong(KEY_PREMIUM_BOUNDARY_SAVED_AT, System.currentTimeMillis())
            .apply()
    }

    private fun premiumBoundaryFingerprints(c: Context): Set<String> =
        ownershipPrefs(c).getStringSet(KEY_EVER_PREMIUM_FINGERPRINTS, emptySet())
            .orEmpty().filter { it.isNotBlank() }.toSet()

    private fun sha256(value: String): String =
        MessageDigest.getInstance("SHA-256")
            .digest(value.toByteArray(Charsets.UTF_8))
            .joinToString("") { "%02x".format(it) }

    private fun premiumOwnerKey(c: Context): String {
        if (!premiumEntitlementActive(c)) return ""
        val identity = snapshot(c).email.trim().lowercase(Locale.ROOT)
        if (identity.isBlank()) return ""
        return sha256(identity).take(24)
    }

    private fun rememberPremiumLastKnownGood(c: Context, serverGuids: Collection<String>) {
        if (!premiumEntitlementActive(c)) return
        val owner = premiumOwnerKey(c)
        if (owner.isBlank()) return
        val free = allFreeServerGuids()
        val safe = serverGuids
            .asSequence()
            .map { it.trim() }
            .filter { it.isNotBlank() && it !in free }
            .filter { MmkvManager.decodeServerConfig(it) != null }
            .distinct()
            .toSet()
        if (safe.isEmpty()) return
        val storage = c.applicationContext.getSharedPreferences(PREMIUM_LKG_PREFS, Context.MODE_PRIVATE)
        if (storage.getStringSet("servers_$owner", emptySet()).orEmpty() == safe) return
        val account = snapshot(c)
        val poolIdentity = prefs(c).getString("pool_identity", "").orEmpty().trim()
        storage.edit()
            .putStringSet("servers_$owner", safe)
            .putString("url_$owner", account.subscriptionUrl.trim())
            .putString("pool_$owner", poolIdentity)
            .putLong("saved_at_$owner", System.currentTimeMillis())
            .apply()
    }

    private fun premiumLastKnownGoodServerGuids(c: Context): List<String> {
        if (!premiumEntitlementActive(c)) return emptyList()
        val owner = premiumOwnerKey(c)
        if (owner.isBlank()) return emptyList()
        val free = allFreeServerGuids()
        val storage = c.applicationContext.getSharedPreferences(PREMIUM_LKG_PREFS, Context.MODE_PRIVATE)
        val account = snapshot(c)
        val currentUrl = account.subscriptionUrl.trim()
        val currentPoolIdentity = prefs(c).getString("pool_identity", "").orEmpty().trim()
        val savedUrl = storage.getString("url_$owner", "").orEmpty().trim()
        val savedPoolIdentity = storage.getString("pool_$owner", "").orEmpty().trim()
        // Never carry a stale Premium pool across provider URL/account-pool
        // rotation. LKG is only a transient continuity cache for the exact
        // current entitlement source.
        if (savedUrl.isBlank() || savedUrl != currentUrl) return emptyList()
        if (currentPoolIdentity.isNotBlank() && savedPoolIdentity.isNotBlank() &&
            currentPoolIdentity != savedPoolIdentity
        ) return emptyList()
        return storage
            .getStringSet("servers_$owner", emptySet())
            .orEmpty()
            .asSequence()
            .map { it.trim() }
            .filter { it.isNotBlank() && it !in free }
            .filter { MmkvManager.decodeServerConfig(it) != null }
            .distinct()
            .toList()
    }

    private fun usableServerGuids(subscriptionGuids: Collection<String>): List<String> =
        subscriptionGuids
            .flatMap { subscriptionGuid ->
                runCatching { MmkvManager.decodeServerList(subscriptionGuid) }
                    .getOrDefault(emptyList())
            }
            .map { it.trim() }
            .filter { serverGuid ->
                serverGuid.isNotBlank() && MmkvManager.decodeServerConfig(serverGuid) != null
            }
            .distinct()

    /**
     * Never delete a BlueVPN pool merely because it is currently inactive.
     *
     * The Free/Premium split originally made this destructive: disabling a row
     * also deleted its imported v2rayNG profiles. A transient subscription or
     * entitlement refresh could therefore turn a working Premium account into
     * an empty pool. The old, reliable BlueVPN behaviour was effectively
     * last-known-good: profiles stayed in v2rayNG until a replacement import was
     * ready. Entitlement filtering is enough to prevent the inactive pool from
     * being selected, so cleanup is now metadata-only.
     */
    fun pruneInactiveManagedPools(c: Context): Int {
        // Defensive guard: this helper has several callers. Even if a future
        // caller forgets the outer runtime check, never rewrite subscription
        // metadata while Xray owns the MMKV pool.
        if (BlueVpnRuntimeGate.connectionActive(c)) return 0
        val keep = entitlementSubscriptionGuids(c)
        MmkvManager.decodeSubscriptions().forEach { row ->
            if (!isBlueVpnManagedSubscription(row.subscription.remarks)) return@forEach
            if (row.guid in keep) return@forEach
            if (row.subscription.enabled) {
                MmkvManager.encodeSubscription(
                    row.guid,
                    row.subscription.copy(enabled = false),
                )
            }
        }
        return 0
    }

    /**
     * Main-thread-safe ownership check used only for presentation.
     *
     * It never enumerates subscription server lists. A previously resolved deep
     * entitlement snapshot is preferred; otherwise the selected profile must at
     * least belong to one of the exact enabled subscription rows for the current
     * Free/Premium identity. Connection preparation still uses the strict deep
     * [selectedServerAllowed]/[preferredServerGuids] path on a worker thread.
     */
    fun selectedServerAllowedUi(
        c: Context,
        serverGuid: String,
        subscriptionId: String?,
        cachedServerGuids: Collection<String> = BlueVpnEntitlement.resolveUi(c).serverGuids,
    ): Boolean {
        val guid = serverGuid.trim()
        if (guid.isBlank()) return false
        if (guid in cachedServerGuids) return true
        val id = subscriptionId.orEmpty().trim()
        return id.isNotBlank() && id in entitlementSubscriptionGuids(c)
    }

    fun selectedServerAllowed(c: Context): Boolean {
        val selected = MmkvManager.getSelectServer().orEmpty().trim()
        if (selected.isBlank()) return false
        val profile = MmkvManager.decodeServerConfig(selected) ?: return false
        return candidateAllowed(c, selected, profile.subscriptionId)
    }

    /**
     * Preserve a working Premium selection across a temporary subscription
     * refresh. Free mode remains strict and can only select the configured Free
     * pool; Premium may use a preserved non-Free v2rayNG profile as fallback.
     */
    fun ensureEntitlementSelection(c: Context): String? {
        val selected = MmkvManager.getSelectServer().orEmpty().trim()
        // The selected MMKV key is shared with the daemon process. During an
        // active/starting connection it is immutable; the exact running GUID is
        // tracked by MainViewModel instead of rewriting global selection.
        if (BlueVpnRuntimeGate.connectionActive(c)) {
            return selected.takeIf { it.isNotBlank() && MmkvManager.decodeServerConfig(it) != null }
        }
        if (selected.isNotBlank()) {
            val profile = MmkvManager.decodeServerConfig(selected)
            if (profile != null && candidateAllowed(c, selected, profile.subscriptionId)) {
                return selected
            }
        }

        preferredServerGuids(c).firstOrNull()?.let {
            MmkvManager.setSelectServer(it)
            return it
        }

        // Free/anonymous mode must never retain a Premium selection. Premium,
        // however, should not throw away a still-decodable last-known-good
        // profile simply because the provider refresh is temporarily empty.
        if (selected.isNotBlank() && !premiumEntitlementActive(c)) {
            MmkvManager.setSelectServer("")
        }
        return null
    }

    /**
     * Return only the server GUIDs that belong to the current entitlement.
     *
     * BlueVPN used to fall back from a missing Premium import to every local
     * v2rayNG profile. Once a Free pool was introduced that compatibility path
     * became unsafe: stale Premium rows, imported profiles and Free routes could
     * be observed by the same automatic selector. WordPress is now the control
     * plane, therefore pool ownership is strict and deterministic.
     */
    fun preferredServerGuids(c: Context): List<String> {
        val exact = if (premiumEntitlementActive(c)) currentPremiumServerGuids(c) else usableServerGuids(entitlementSubscriptionGuids(c))
        if (!premiumEntitlementActive(c)) {
            registerFreePoolOwnership(c)
            // Free profiles are accepted only when their semantic owner is a
            // configured Free source and that endpoint has never belonged to Premium.
            return exact.filter { guid -> hardIsolationAllowed(c, guid) }
        }
        if (exact.isNotEmpty()) {
            registerPremiumPoolOwnership(c, exact)
            val isolated = exact.filter { guid -> hardIsolationAllowed(c, guid) }
            // Snapshot only profiles that survived the permanent tier boundary.
            rememberPremiumLastKnownGood(c, isolated)
            return isolated
        }
        // Premium may temporarily lose its exact MMKV list while an import is
        // rebuilding it. The LKG still has to pass the same semantic ownership gate.
        val fallback = premiumLastKnownGoodServerGuids(c)
        registerPremiumPoolOwnership(c, fallback)
        return fallback.filter { guid -> hardIsolationAllowed(c, guid) }
    }

    fun entitlementPoolFingerprint(c: Context): String {
        val mode = when {
            premiumEntitlementActive(c) -> "premium"
            isFreeMode(c) -> "free"
            else -> "none"
        }
        val subscriptions = entitlementSubscriptionGuids(c).sorted().joinToString(",")
        val servers = preferredServerGuids(c).sorted().joinToString(",")
        return "$mode|$subscriptions|$servers"
    }

    /**
     * Stable identity of the user's current entitlement. Unlike
     * entitlementPoolFingerprint this deliberately excludes imported server GUIDs,
     * because v2rayNG temporarily clears and repopulates those GUIDs during a
     * subscription refresh. Using the server list as a UI cache key made the
     * locations appear for a moment and then disappear.
     */
    fun entitlementIdentityFingerprint(c: Context): String = when {
        premiumEntitlementActive(c) -> snapshot(c).let { account ->
            "premium|${account.poolIdentity.ifBlank { account.subscriptionUrl.trim() }}"
        }
        isFreeMode(c) -> {
            val sources = freeAccessSnapshot(c).subscriptions
                .sortedWith(compareBy<BlueVpnFreeSubscription> { it.priority }.thenBy { it.id })
                .joinToString("|") { "${it.id}:${it.url.trim()}" }
            "free|$sources"
        }
        else -> "none"
    }

    fun isSubscriptionRefreshRunning(): Boolean = subscriptionRefreshRunning

    fun candidateAllowed(c: Context, subscriptionId: String?): Boolean =
        candidateAllowed(c, "", subscriptionId)

    /**
     * Entitlement checks must prefer the server list owned by the active
     * subscription. Some v2rayNG imports temporarily leave ProfileItem.subscriptionId
     * blank while the subscription database already owns the server GUID. Matching
     * by server GUID prevents an active Premium account from seeing an empty
     * location screen during that transition without exposing another account's
     * stored routes.
     */
    fun candidateAllowed(
        c: Context,
        serverGuid: String,
        subscriptionId: String?,
        entitlementServerGuids: Set<String> = preferredServerGuids(c).toSet(),
    ): Boolean {
        val guid = serverGuid.trim()
        if (guid.isNotBlank()) {
            // Membership and semantic ownership are both mandatory. This second
            // gate protects direct/manual/AI callers from stale cached pools.
            return guid in entitlementServerGuids && hardIsolationAllowed(c, guid)
        }

        val id = subscriptionId.orEmpty().trim()
        if (id.isBlank()) return false
        // Never accept a stale BlueVPN Account row merely because it is marked
        // as managed. The subscription id must be owned by the exact active URL
        // (Premium) or by the configured enabled Free pool.
        return id in entitlementSubscriptionGuids(c)
    }

    /**
     * Force account/subscription reconciliation and wait briefly for v2rayNG's
     * asynchronous subscription importer to publish its server GUIDs. Must be
     * called from a background dispatcher.
     */
    fun awaitEntitlementServers(
        c: Context,
        timeoutMs: Long = 14_000L,
    ): Result<Int> = runCatching {
        val appContext = c.applicationContext
        // Never turn a locations/AI repair request into a subscription mutation
        // while the daemon is connecting or connected. Return the frozen local
        // entitlement pool and let an explicit refresh happen after disconnect.
        if (BlueVpnRuntimeGate.connectionActive(appContext)) {
            return@runCatching preferredServerGuids(appContext).size
        }
        if (hasSession(appContext)) {
            val current = snapshot(appContext)
            if (current.subscriptionActive && current.subscriptionUrl.startsWith("http")) {
                reconcileSubscriptionMode(
                    c = appContext,
                    premiumActive = true,
                    premiumUrl = current.subscriptionUrl,
                    forceRefresh = currentPremiumServerGuids(appContext).isEmpty(),
                )
            } else {
                sync(appContext, force = true).getOrThrow()
            }
        } else {
            prepareFreeAccess(appContext, force = true).getOrThrow()
        }
        val premiumPoolReady = !premiumEntitlementActive(appContext) || currentPremiumPoolReady(appContext)
        if (premiumPoolReady) {
            pruneInactiveManagedPools(appContext)
        }
        ensureEntitlementSelection(appContext)

        val deadline = android.os.SystemClock.elapsedRealtime() + timeoutMs.coerceIn(2_000L, 30_000L)
        var lastCount = 0
        do {
            val guids = if (premiumEntitlementActive(appContext)) {
                currentPremiumServerGuids(appContext)
            } else {
                preferredServerGuids(appContext)
            }
            lastCount = guids.size
            if (lastCount > 0) {
                BlueVpnLocationUtil.invalidateCache()
                return@runCatching lastCount
            }
            Thread.sleep(350L)
        } while (android.os.SystemClock.elapsedRealtime() < deadline)

        BlueVpnLocationUtil.invalidateCache()
        lastCount
    }

    private fun reconcileSubscriptionMode(
        c: Context,
        premiumActive: Boolean,
        premiumUrl: String,
        forceRefresh: Boolean,
    ): Boolean = synchronized(subscriptionReconcileLock) {
        // Account refresh may continue while connected, but the complete
        // subscription metadata swap + import is one atomic transaction. A
        // Connect operation can see either the old pool or the new pool, never
        // a half-enabled/half-imported MMKV state.
        if (!BlueVpnRuntimeGate.beginSubscriptionMutation(c)) {
            setEntitlementReconcilePending(c, true)
            return@synchronized false
        }
        try {
        val existing = MmkvManager.decodeSubscriptions()
        var changed = false
        var mustRefresh = forceRefresh

        existing.filter { it.subscription.remarks.startsWith(FREE_SUB) }.forEach { row ->
            val shouldEnable = !premiumActive &&
                row.guid in configuredFreeSubscriptionGuids(c)
            if (row.subscription.enabled != shouldEnable) {
                MmkvManager.encodeSubscription(
                    row.guid,
                    row.subscription.copy(enabled = shouldEnable),
                )
                changed = true
            }
        }

        val managedRows = existing.filter { it.subscription.remarks == SUB }
        val normalizedPremiumUrl = premiumUrl.trim()
        if (premiumActive && normalizedPremiumUrl.startsWith("http")) {
            val managed = managedRows.firstOrNull {
                it.subscription.url.trim() == normalizedPremiumUrl
            }

            // Disable stale Premium rows from an older panel, renewal or URL.
            // Keeping several enabled "BlueVPN Account" rows is what caused the
            // automatic selector to combine free/old/premium routes in MMKV.
            managedRows.filter {
                it.guid != managed?.guid && it.subscription.enabled
            }.forEach { row ->
                MmkvManager.encodeSubscription(
                    row.guid,
                    row.subscription.copy(enabled = false),
                )
                changed = true
            }

            val needsManagedWrite = managed == null ||
                !managed.subscription.enabled ||
                managed.subscription.userAgent != null ||
                managed.subscription.autoUpdate
            if (needsManagedWrite) {
                val item = managed?.subscription?.copy(
                    remarks = SUB,
                    url = normalizedPremiumUrl,
                    enabled = true,
                    autoUpdate = false,
                    userAgent = null,
                ) ?: SubscriptionItem(
                    remarks = SUB,
                    url = normalizedPremiumUrl,
                    enabled = true,
                    autoUpdate = false,
                    userAgent = null,
                )
                MmkvManager.encodeSubscription(
                    managed?.guid.orEmpty(),
                    item,
                )
                changed = true
                mustRefresh = true
            }

            val currentGuids = MmkvManager.decodeSubscriptions()
                .filter {
                    it.subscription.remarks == SUB &&
                        it.subscription.enabled &&
                        it.subscription.url.trim() == normalizedPremiumUrl
                }
                .map { it.guid }
                .filter { it.isNotBlank() }
            if (currentGuids.isEmpty() || currentGuids.all { guid ->
                    runCatching { MmkvManager.decodeServerList(guid).isEmpty() }
                        .getOrDefault(true)
                }) {
                mustRefresh = true
            }
        } else {
            managedRows.filter { it.subscription.enabled }.forEach { row ->
                MmkvManager.encodeSubscription(
                    row.guid,
                    row.subscription.copy(enabled = false),
                )
                changed = true
            }
        }

        // Do not delete the previous Premium physical pool before the new
        // entitlement row has imported at least one real profile. A rotated
        // subscription URL plus a temporary network/provider failure used to
        // leave Premium at 0 locations. Free rows can be removed immediately;
        // stale Premium rows stay disabled and are pruned only after the new
        // Premium pool is confirmed ready.
        // Keep inactive physical profiles as a last-known-good cache. Their
        // subscription rows are disabled above and candidateAllowed() enforces
        // Free/Premium isolation, so destructive MMKV cleanup is unnecessary.
        pruneInactiveManagedPools(c)
        if (mustRefresh) {
            subscriptionRefreshRunning = true
            try {
                val activeRows = MmkvManager.decodeSubscriptions().filter { row ->
                    row.subscription.enabled && when {
                        premiumActive ->
                            row.subscription.remarks == SUB &&
                                row.subscription.url.trim() == normalizedPremiumUrl
                        else ->
                            row.subscription.remarks.startsWith(FREE_SUB) &&
                                row.guid in configuredFreeSubscriptionGuids(c)
                    }
                }
                BlueVpnSubscriptionIntelligence.refreshWithinMutation(
                    c,
                    activeRows,
                    aggressiveRepair = activeRows.any { row ->
                        runCatching { MmkvManager.decodeServerList(row.guid).isEmpty() }.getOrDefault(true)
                    },
                )
            } finally {
                subscriptionRefreshRunning = false
            }
        }
        val currentPoolReady = !premiumActive || currentPremiumServerGuids(c).isNotEmpty()
        if (changed || mustRefresh) {
            if (currentPoolReady) {
                // Transactional swap complete: only now delete stale Premium
                // rows. Until this point they are disabled and invisible to
                // BlueVPN's entitlement-aware selector, but remain recoverable.
                pruneInactiveManagedPools(c)
            }
            BlueVpnLocationUtil.invalidateCache()
            ensureEntitlementSelection(c)
        }
        setEntitlementReconcilePending(c, !currentPoolReady)
        currentPoolReady
        } finally {
            BlueVpnRuntimeGate.endSubscriptionMutation()
        }
    }

    fun reconcilePendingEntitlement(c: Context): Result<Boolean> = runCatching {
        val appContext = c.applicationContext
        if (!entitlementReconcilePending(appContext)) return@runCatching true
        if (BlueVpnRuntimeGate.connectionActive(appContext)) return@runCatching false

        val account = snapshot(appContext)
        if (hasSession(appContext) && account.subscriptionActive && account.subscriptionUrl.startsWith("http")) {
            reconcileSubscriptionMode(
                c = appContext,
                premiumActive = true,
                premiumUrl = account.subscriptionUrl,
                forceRefresh = currentPremiumServerGuids(appContext).isEmpty(),
            )
        } else {
            val metadataReady = reconcileSubscriptionMode(
                c = appContext,
                premiumActive = false,
                premiumUrl = "",
                forceRefresh = false,
            )
            if (!metadataReady) return@runCatching false
            if (!hasSession(appContext) || !account.subscriptionActive) {
                prepareFreeAccess(appContext, force = false).getOrThrow()
            }
        }
        !entitlementReconcilePending(appContext)
    }

    fun startFreeSession(c: Context) {
        val appContext = c.applicationContext
        if (!isFreeMode(appContext)) {
            stopFreeSession(appContext, expired = false)
            return
        }
        val storage = freePrefs(appContext)
        val currentEnd = storage.getLong("session_ends_at", 0L)
        val now = System.currentTimeMillis()
        val end = if (currentEnd > now) currentEnd else
            now + freeAccessSnapshot(appContext).sessionMinutes * 60_000L
        storage.edit()
            .putLong("session_started_at", if (currentEnd > now) storage.getLong("session_started_at", now) else now)
            .putLong("session_ends_at", end)
            .putBoolean("session_active", true)
            .remove("expired_notice")
            .commit()
        scheduleFreeAlarm(appContext, end)
    }

    fun stopFreeSession(c: Context, expired: Boolean) {
        val appContext = c.applicationContext
        cancelFreeAlarm(appContext)
        freePrefs(appContext).edit()
            .remove("session_started_at")
            .remove("session_ends_at")
            .putBoolean("session_active", false)
            .apply { if (expired) putBoolean("expired_notice", true) else remove("expired_notice") }
            .commit()
    }

    fun freeSessionRemainingMillis(c: Context): Long {
        if (!isFreeMode(c)) return Long.MAX_VALUE
        val end = freePrefs(c).getLong("session_ends_at", 0L)
        return if (end <= 0L) 0L else (end - System.currentTimeMillis()).coerceAtLeast(0L)
    }

    fun enforceFreeSession(c: Context): Boolean {
        val appContext = c.applicationContext
        if (!isFreeMode(appContext)) return false
        val storage = freePrefs(appContext)
        if (!storage.getBoolean("session_active", false)) return false
        if (freeSessionRemainingMillis(appContext) > 0L) return false
        runCatching { CoreServiceManager.stopVService(appContext) }
        runCatching { BlueVpnPreferences.clearConnected(appContext) }
        stopFreeSession(appContext, expired = false)
        stopFreeSession(appContext, expired = true)
        return true
    }

    fun consumeFreeExpiredNotice(c: Context): Boolean {
        val storage = freePrefs(c)
        val value = storage.getBoolean("expired_notice", false)
        if (value) storage.edit().remove("expired_notice").apply()
        return value
    }

    private fun freeAlarmIntent(c: Context): PendingIntent =
        PendingIntent.getBroadcast(
            c,
            6301,
            Intent(c, BlueVpnFreeSessionReceiver::class.java).setAction(FREE_ALARM_ACTION),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )

    private fun scheduleFreeAlarm(c: Context, triggerAt: Long) {
        val alarm = c.getSystemService(Context.ALARM_SERVICE) as AlarmManager
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            alarm.setAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, triggerAt, freeAlarmIntent(c))
        } else {
            alarm.set(AlarmManager.RTC_WAKEUP, triggerAt, freeAlarmIntent(c))
        }
    }

    private fun cancelFreeAlarm(c: Context) {
        val alarm = c.getSystemService(Context.ALARM_SERVICE) as AlarmManager
        alarm.cancel(freeAlarmIntent(c))
    }

    fun pendingOrder(c: Context) =
        prefs(c).getString("pending_order", "").orEmpty()

    fun setPendingOrder(c: Context, id: String) =
        prefs(c).edit().putString("pending_order", id).apply()

    fun clearPendingOrder(c: Context) =
        setPendingOrder(c, "")

    fun isDeletedOrderError(error: Throwable): Boolean =
        error is ApiException &&
            (error.status == 404 || error.status == 410) &&
            error.code in setOf("ORDER_NOT_FOUND", "ORDER_GONE", "HTTP_404", "HTTP_410")

    fun markCheckoutBrowserOpen(c: Context, id: String) {
        prefs(c).edit()
            .putString("checkout_browser_order", id)
            .putLong("checkout_browser_opened_at", System.currentTimeMillis())
            .commit()
    }

    fun checkoutBrowserOrder(c: Context): String =
        prefs(c).getString(
            "checkout_browser_order",
            "",
        ).orEmpty()

    fun clearCheckoutBrowserOrder(c: Context) {
        prefs(c).edit()
            .remove("checkout_browser_order")
            .remove("checkout_browser_opened_at")
            .commit()
    }

    fun consumeCheckoutBrowserOrder(c: Context): String {
        val value = checkoutBrowserOrder(c)
        clearCheckoutBrowserOrder(c)
        return value
    }

    fun deviceId(c: Context): String {
        restorePrimary(c)

        val primary = prefs(c)
        val old = primary.getString("device_id", "").orEmpty()
        if (old.isNotBlank()) return old

        val backupId =
            backup(c).getString("device_id", "").orEmpty()
        if (backupId.isNotBlank()) {
            primary.edit()
                .putString("device_id", backupId)
                .apply()
            return backupId
        }

        val androidId = Settings.Secure.getString(
            c.contentResolver,
            Settings.Secure.ANDROID_ID
        ).orEmpty()

        val seed = if (androidId.isBlank()) {
            UUID.randomUUID().toString()
        } else {
            "${c.packageName}:$androidId"
        }

        val id = MessageDigest.getInstance("SHA-256")
            .digest(seed.toByteArray())
            .joinToString("") { "%02x".format(it) }
            .take(40)

        primary.edit().putString("device_id", id).apply()
        backup(c).edit().putString("device_id", id).apply()
        return id
    }

    fun deviceName() =
        "${Build.MANUFACTURER} ${Build.MODEL}".trim()

    private fun parseIsoMillis(raw: String?): Long? {
        val value = raw?.trim().orEmpty()
        if (value.isBlank() || value == "null") return null
        if (value.startsWith("9999-")) return Long.MAX_VALUE
        val normalized = value.replace(
            Regex("\\.(\\d{3})\\d+([+-]\\d{2}:\\d{2}|Z)$"),
            ".$1$2",
        )
        val patterns = listOf(
            "yyyy-MM-dd'T'HH:mm:ss.SSSXXX",
            "yyyy-MM-dd'T'HH:mm:ssXXX",
            "yyyy-MM-dd'T'HH:mm:ss.SSS'Z'",
            "yyyy-MM-dd'T'HH:mm:ss'Z'",
            "yyyy-MM-dd",
        )
        return patterns.firstNotNullOfOrNull { pattern ->
            runCatching {
                SimpleDateFormat(pattern, Locale.US).apply {
                    timeZone = TimeZone.getTimeZone("UTC")
                    isLenient = false
                }.parse(normalized)?.time
            }.getOrNull()
        }
    }

    private fun effectiveSubscriptionActive(
        subscription: JSONObject,
    ): Boolean {
        val url = subscription.optString("url").trim()
        val urlValid = url.startsWith("http://") || url.startsWith("https://")
        val limit = subscription.optLong("data_limit_bytes", 0L)
        val used = subscription.optLong("used_traffic_bytes", 0L)
        val withinTraffic = limit <= 0L || used < limit
        val status = subscription.optString("status", "inactive")
            .trim()
            .lowercase(Locale.US)
        val statusActive = status in setOf("active", "enabled", "on", "valid")
        val terminalStatus = status in setOf(
            "disabled", "expired", "blocked", "banned", "deleted",
            "limited", "revoked", "suspended",
        )
        if (terminalStatus) return false
        val entitlementActive = subscription.optBoolean("entitlement_active", false)
        val unlimited = subscription.optBoolean("unlimited", false) ||
            subscription.optString("expire_mode") == "unlimited" ||
            subscription.optString("expire").startsWith("9999-")
        val skewMillis = subscription.optLong(
            "clock_skew_tolerance_seconds",
            120L,
        ).coerceAtLeast(0L) * 1_000L
        val expiryValid = when {
            unlimited -> true
            subscription.has("remaining_seconds") &&
                !subscription.isNull("remaining_seconds") ->
                subscription.optLong("remaining_seconds", -1L) >=
                    -(skewMillis / 1_000L)
            else -> {
                val expiry = parseIsoMillis(
                    subscription.optString("expire").ifBlank {
                        subscription.optString("expires_at")
                    }
                )
                expiry != null && expiry > System.currentTimeMillis() - skewMillis
            }
        }
        val backendActive = subscription.optBoolean("active", false)
        return urlValid && withinTraffic && expiryValid &&
            (backendActive || statusActive || entitlementActive)
    }

    private fun accountCacheVersion(c: Context): Long =
        prefs(c).getLong("account_app_version", -1L)

    private fun currentAppVersion(): Long = BuildConfig.VERSION_CODE.toLong()

    fun snapshot(c: Context): BlueVpnAccountSnapshot {
        restorePrimary(c)
        val now = android.os.SystemClock.elapsedRealtime()
        accountSnapshotCache?.takeIf {
            accountSnapshotCacheAt > 0L && now - accountSnapshotCacheAt < ACCOUNT_SNAPSHOT_CACHE_MS
        }?.let { return it }
        val p = prefs(c)
        val snapshot = BlueVpnAccountSnapshot(
            p.getString("email", "").orEmpty(),
            active(c),
            p.getString("url", "").orEmpty(),
            p.getString("status", "inactive").orEmpty(),
            p.getString("expire", null),
            p.getString("expire_fa", null),
            p.getLong("limit", 0),
            p.getLong("used", 0),
            p.getInt("devices", 1),
            p.getString("sync_error", "").orEmpty(),
            p.getBoolean("phone_verified", false),
            p.getString("auth_method", "legacy_email").orEmpty(),
            p.getString("pool_identity", "").orEmpty(),
        )
        accountSnapshotCache = snapshot
        accountSnapshotCacheAt = now
        return snapshot
    }

    private fun enforceFreeBoundaryTransition(c: Context) {
        val appContext = c.applicationContext
        runCatching { CoreServiceManager.stopVService(appContext) }
        runCatching { BlueVpnPreferences.clearConnected(appContext) }
        setEntitlementReconcilePending(appContext, true)
        runCatching { MmkvManager.setSelectServer("") }
        BlueVpnPreferences.clearConnected(appContext)
        BlueVpnPreferences.setAutomaticSelection(appContext)
        BlueVpnPreferences.beginHealthSession(appContext)
        BlueVpnSmartSelector.clear(appContext)
        BlueVpnLocationUtil.invalidateCache()
        stopFreeSession(appContext, expired = false)
        appContext.getSharedPreferences("bluevpn_subscription_info", Context.MODE_PRIVATE)
            .edit().clear().commit()
        subscriptionInstallExecutor.execute {
            runCatching {
                reconcileSubscriptionMode(appContext, premiumActive = false, premiumUrl = "", forceRefresh = false)
                prepareFreeAccess(appContext, force = true).getOrThrow()
                ensureEntitlementSelection(appContext)
            }
            BlueVpnLocationUtil.invalidateCache()
        }
    }

    fun logout(c: Context) {
        val appContext = c.applicationContext
        val id = deviceId(appContext)
        val access = token(appContext)

        // Snapshot the exact Premium endpoint identities before credentials are
        // destroyed. The following Free session quarantines those endpoints so
        // a regenerated GUID cannot reconnect to the same Premium route/IP.
        rememberPremiumBoundaryFingerprints(appContext)

        // Cross an explicit authentication boundary before touching UI/MMKV. Any
        // /account or /auth/refresh response that started before this point is now
        // stale and is forbidden from restoring Premium state after logout.
        synchronized(authStateLock) {
            authSessionEpoch.incrementAndGet()
            prefs(appContext).edit()
                .clear()
                .putString("device_id", id)
                .commit()
            backup(appContext).edit()
                .clear()
                .putString("device_id", id)
                .commit()
            invalidateAccountSnapshot()
            invalidateMobileConfigCache()
        }

        enforceFreeBoundaryTransition(appContext)

        // Remote session revocation is best-effort and never blocks the UI. The
        // captured access token is used because local credentials are already gone.
        if (access.isNotBlank()) {
            backgroundExecutor.execute {
                runCatching {
                    request(
                        appContext,
                        "POST",
                        "/api/v1/auth/logout",
                        JSONObject(),
                        true,
                        accessOverride = access,
                    )
                }
            }
        }
    }

    private fun invalidateSession(
        c: Context,
        code: String,
    ) {
        val appContext = c.applicationContext
        // Automatic auth loss is the same security boundary as explicit Logout.
        // Capture Premium ownership before credentials disappear, then perform the
        // full tunnel/selection/MMKV transition to Free.
        rememberPremiumBoundaryFingerprints(appContext)
        val id = deviceId(appContext)
        val email = snapshot(appContext).email
        synchronized(authStateLock) {
            authSessionEpoch.incrementAndGet()
            prefs(appContext).edit()
                .remove("token")
                .remove("refresh_token")
                .putString("email", email)
                .putString("device_id", id)
                .putString("auth_error", code)
                .commit()

            backup(appContext).edit()
                .remove("token")
                .remove("refresh_token")
                .putString("email", email)
                .putString("device_id", id)
                .commit()
            invalidateAccountSnapshot()
            invalidateMobileConfigCache()
        }
        enforceFreeBoundaryTransition(appContext)
    }

    fun requestOtp(
        c: Context,
        phone: String,
        bindToCurrentAccount: Boolean = false,
    ): Result<BlueVpnOtpRequest> = runCatching {
        val response = if (bindToCurrentAccount) {
            authenticatedRequest(
                c,
                "POST",
                "/api/v1/account/phone/otp/request",
                JSONObject()
                    .put("phone", phone.trim())
                    .put("device_id", deviceId(c))
                    .put("device_name", deviceName()),
            )
        } else {
            request(
                c,
                "POST",
                "/api/v1/auth/otp/request",
                JSONObject()
                    .put("phone", phone.trim())
                    .put("device_id", deviceId(c))
                    .put("device_name", deviceName()),
                false,
            )
        }
        val challenge = response.optString("challenge_id")
        if (challenge.isBlank()) error(message(response))
        BlueVpnOtpRequest(
            challengeId = challenge,
            phone = response.optString("phone", phone.trim()),
            expiresInSeconds = response.optInt("expires_in_seconds", 120),
            resendAfterSeconds = response.optInt("resend_after_seconds", 60),
        )
    }

    fun verifyOtp(
        c: Context,
        phone: String,
        challengeId: String,
        code: String,
        bindToCurrentAccount: Boolean = false,
    ): Result<BlueVpnAccountSnapshot> = runCatching {
        val payload = JSONObject()
            .put("phone", phone.trim())
            .put("challenge_id", challengeId)
            .put("code", code.trim())
            .put("device_id", deviceId(c))
            .put("device_name", deviceName())

        val response = if (bindToCurrentAccount) {
            authenticatedRequest(
                c,
                "POST",
                "/api/v1/account/phone/otp/verify",
                payload,
            )
        } else {
            request(
                c,
                "POST",
                "/api/v1/auth/otp/verify",
                payload,
                false,
            )
        }

        if (!bindToCurrentAccount) {
            val access = response.optString("token")
            val refresh = response.optString("refresh_token")
            if (access.isBlank()) error(message(response))
            persistAuth(c, access, refresh, phone.trim(), newSession = true)
        }

        applyAccount(
            c,
            response.getJSONObject("account"),
            expectedAuthEpoch = authSessionEpoch.get(),
            deferEntitlementWork = !bindToCurrentAccount,
        )
    }

    fun authenticateWithEmail(
        c: Context,
        email: String,
        password: String,
        register: Boolean,
    ): Result<BlueVpnAccountSnapshot> = runCatching {
        val normalizedEmail = email.trim().lowercase(Locale.US)
        if (normalizedEmail.isBlank() || !normalizedEmail.contains("@")) {
            error("ایمیل معتبر وارد کنید")
        }
        if (password.length < 8) {
            error("رمز عبور باید حداقل ۸ کاراکتر باشد")
        }
        val response = request(
            c,
            "POST",
            if (register) "/api/v1/auth/register" else "/api/v1/auth/login",
            JSONObject()
                .put("email", normalizedEmail)
                .put("password", password)
                .put("device_id", deviceId(c))
                .put("device_name", deviceName()),
            false,
        )
        val access = response.optString("token")
        val refresh = response.optString("refresh_token")
        if (access.isBlank()) error(message(response))
        persistAuth(c, access, refresh, normalizedEmail, newSession = true)
        applyAccount(
            c,
            response.getJSONObject("account"),
            expectedAuthEpoch = authSessionEpoch.get(),
            deferEntitlementWork = true,
        )
    }

    private fun refreshSession(
        c: Context,
        failedAccessToken: String,
    ): Boolean = synchronized(refreshLock) {
        restorePrimary(c)
        val expectedAuthEpoch = authSessionEpoch.get()

        val currentAccess = token(c)

        // Another request may already have refreshed the session while this
        // request was waiting for the lock.
        if (
            failedAccessToken.isNotBlank() &&
            currentAccess.isNotBlank() &&
            currentAccess != failedAccessToken
        ) {
            return@synchronized true
        }

        val refresh = refreshToken(c)
        val identity = snapshot(c).email
            .ifBlank {
                backup(c).getString(
                    "email",
                    ""
                ).orEmpty()
            }

        if (refresh.isBlank() || identity.isBlank()) {
            return@synchronized false
        }

        try {
            val response = request(
                c,
                "POST",
                "/api/v1/auth/refresh",
                JSONObject()
                    .put("identity", identity)
                    .put("phone", identity)
                    .put("email", identity)
                    .put("device_id", deviceId(c))
                    .put("device_name", deviceName())
                    .put("refresh_token", refresh),
                false,
            )

            val access = response.optString("token")
            val newRefresh = response.optString(
                "refresh_token",
                refresh,
            )

            if (access.isBlank()) {
                return@synchronized false
            }

            val persisted = persistAuth(
                c,
                access,
                newRefresh,
                identity,
                expectedEpoch = expectedAuthEpoch,
            )
            if (!persisted || authSessionEpoch.get() != expectedAuthEpoch || !hasSession(c)) {
                return@synchronized false
            }

            response.optJSONObject("account")
                ?.let { applyAccount(c, it, expectedAuthEpoch = expectedAuthEpoch) }

            authSessionEpoch.get() == expectedAuthEpoch && hasSession(c)
        } catch (error: ApiException) {
            val accessChanged =
                token(c).isNotBlank() &&
                    token(c) != failedAccessToken

            val refreshChanged =
                refreshToken(c).isNotBlank() &&
                    refreshToken(c) != refresh

            if (accessChanged || refreshChanged) {
                return@synchronized true
            }

            if (
                error.status == 401 &&
                error.code in setOf(
                    "INVALID_REFRESH",
                    "DEVICE_DISABLED",
                    "ACCOUNT_DISABLED",
                    "REFRESH_REQUIRED",
                )
            ) {
                invalidateSession(c, error.code)
            }

            false
        } catch (_: Exception) {
            // Network, timeout and server errors must never erase login.
            false
        }
    }

    private fun authenticatedRequest(
        c: Context,
        method: String,
        path: String,
        body: JSONObject?,
    ): JSONObject {
        val attemptedAccess = token(c)

        try {
            return request(
                c,
                method,
                path,
                body,
                true,
            )
        } catch (error: ApiException) {
            if (
                error.status == 401 &&
                refreshSession(
                    c,
                    attemptedAccess,
                )
            ) {
                return request(
                    c,
                    method,
                    path,
                    body,
                    true,
                )
            }

            // Only a definitive refresh rejection can clear local login.
            // Timeouts, server errors and simultaneous requests keep it.
            throw error
        }
    }

    fun sync(
        c: Context,
        force: Boolean = false,
        deferEntitlementWork: Boolean = false,
    ): Result<BlueVpnAccountSnapshot> = runCatching {
        if (!hasSession(c)) error("AUTH_REQUIRED")

        // Coalesce every account refresh through one lock. Home/resume, account
        // screens and a manual refresh used to overlap and each request could
        // apply a slightly different snapshot while a subscription import was
        // also being scheduled. One serialized owner removes that race.
        synchronized(accountSyncLock) {
            if (!hasSession(c)) error("AUTH_REQUIRED")
            val expectedAuthEpoch = authSessionEpoch.get()
            val now = System.currentTimeMillis()
            val effectiveForce = force && !BlueVpnRuntimeGate.connectionActive(c)
            val last = prefs(c).getLong("last_sync", 0)
            val local = snapshot(c)
            val appUpdated = accountCacheVersion(c) != currentAppVersion()
            val entitlementIncomplete =
                local.subscriptionActive && local.subscriptionUrl.isBlank()

            // Even explicit refresh callers are coalesced for a few seconds. This
            // prevents double /account/sync + provider jobs from one UI action
            // without changing the semantics of a later deliberate refresh.
            if (
                effectiveForce &&
                !appUpdated &&
                !entitlementIncomplete &&
                now - lastForcedAccountSyncAt < 4_000L
            ) {
                return@synchronized snapshot(c)
            }

            if (
                !effectiveForce &&
                !appUpdated &&
                !entitlementIncomplete &&
                now - last < AUTO_SYNC_INTERVAL_MS
            ) {
                return@synchronized local
            }

            // Routine refresh reads the WordPress snapshot only. Provider polling
            // remains explicit and server-throttled; it never runs merely because
            // Home/AI/Locations became visible.
            val response = authenticatedRequest(
                c,
                if (effectiveForce) "POST" else "GET",
                if (effectiveForce) "/api/v1/account/sync" else "/api/v1/account",
                if (effectiveForce) JSONObject() else null,
            )
            if (effectiveForce) lastForcedAccountSyncAt = System.currentTimeMillis()
            if (authSessionEpoch.get() != expectedAuthEpoch || !hasSession(c)) {
                error("AUTH_SESSION_CHANGED")
            }

            applyAccount(
                c,
                response.getJSONObject("account"),
                forceSubscriptions = effectiveForce,
                expectedAuthEpoch = expectedAuthEpoch,
                deferEntitlementWork = deferEntitlementWork,
            )
        }
    }

    fun plans(c: Context): Result<JSONArray> = runCatching {
        if (!hasSession(c)) error("AUTH_REQUIRED")
        val now = android.os.SystemClock.elapsedRealtime()
        val cached = plansCacheRaw
        if (cached.isNotBlank() && now - plansCacheAt < PLANS_CACHE_MS) {
            return@runCatching JSONArray(cached)
        }
        synchronized(plansLock) {
            val lockedNow = android.os.SystemClock.elapsedRealtime()
            val lockedCached = plansCacheRaw
            if (lockedCached.isNotBlank() && lockedNow - plansCacheAt < PLANS_CACHE_MS) {
                return@synchronized JSONArray(lockedCached)
            }
            val rows = authenticatedRequest(c, "GET", "/api/v1/plans", null)
                .getJSONArray("plans")
            plansCacheRaw = rows.toString()
            plansCacheAt = lockedNow
            JSONArray(plansCacheRaw)
        }
    }

    fun createOrder(
        c: Context,
        planId: Int,
    ): Result<JSONObject> = runCatching {
        val response = authenticatedRequest(
            c,
            "POST",
            "/api/v1/orders",
            JSONObject().put("plan_id", planId),
        )
        val data = response.optJSONObject("data")
        val order = response.optJSONObject("order")
            ?: data?.optJSONObject("order")
            ?: data?.takeIf {
                it.has("payment_url") || it.has("checkout_url") || it.has("id")
            }
            ?: response.takeIf {
                it.has("payment_url") || it.has("checkout_url") || it.has("id")
            }
            ?: error(message(response))

        if (order.optString("payment_url").isBlank()) {
            val compatibleUrl = sequenceOf(
                "checkout_url", "redirect_url", "pay_url", "url", "payment_link",
            ).map { order.optString(it).trim() }
                .firstOrNull { it.startsWith("http://") || it.startsWith("https://") }
                .orEmpty()
            if (compatibleUrl.isNotBlank()) order.put("payment_url", compatibleUrl)
        }
        order
    }

    fun closeCheckout(
        c: Context,
        id: String,
    ): Result<JSONObject> = runCatching {
        authenticatedRequest(
            c,
            "POST",
            "/api/v1/orders/$id/checkout/close",
            JSONObject(),
        ).getJSONObject("order")
    }

    fun postAiEvent(
        c: Context,
        payload: JSONObject,
    ): Result<JSONObject> = runCatching {
        if (hasSession(c)) {
            authenticatedRequest(c, "POST", "/api/v1/ai/events", payload)
        } else {
            request(c, "POST", "/api/v1/ai/events", payload, false)
        }
    }


    fun resolveServerLocations(
        c: Context,
        keys: List<String>,
    ): Result<JSONObject> = runCatching {
        val array = org.json.JSONArray()
        keys.distinct().take(600).forEach { key ->
            if (key.matches(Regex("[a-f0-9]{40}"))) array.put(key)
        }
        authenticatedRequest(
            c,
            "POST",
            "/api/v1/server-locations/resolve",
            JSONObject().put("keys", array),
        )
    }

    fun reportServerLocation(
        c: Context,
        configKey: String,
        countryCode: String,
    ): Result<JSONObject> = runCatching {
        authenticatedRequest(
            c,
            "POST",
            "/api/v1/server-locations/verify",
            JSONObject()
                .put("config_key", configKey)
                .put("country_code", countryCode)
                .put("source", "client_trace"),
        )
    }

    fun aiRecommendations(
        c: Context,
        operator: String,
        networkType: String,
        mode: String,
        planTier: String,
    ): Result<JSONObject> = runCatching {
        val path = "/api/v1/ai/recommendations" +
            "?operator=" + java.net.URLEncoder.encode(operator, "UTF-8") +
            "&network_type=" + java.net.URLEncoder.encode(networkType, "UTF-8") +
            "&mode=" + java.net.URLEncoder.encode(mode, "UTF-8") +
            "&plan_tier=" + java.net.URLEncoder.encode(planTier, "UTF-8")
        if (hasSession(c)) {
            authenticatedRequest(c, "GET", path, null)
        } else {
            request(c, "GET", path, null, false)
        }
    }

    fun aiDashboard(c: Context): Result<JSONObject> = runCatching {
        if (hasSession(c)) {
            authenticatedRequest(c, "GET", "/api/v1/ai/dashboard", null)
        } else {
            request(c, "GET", "/api/v1/ai/dashboard", null, false)
        }
    }

    fun submitFeedback(
        c: Context,
        payload: JSONObject,
    ): Result<JSONObject> = runCatching {
        authenticatedRequest(
            c,
            "POST",
            "/api/v1/feedback",
            payload,
        )
    }

    fun order(
        c: Context,
        id: String,
    ): Result<JSONObject> = runCatching {
        authenticatedRequest(
            c,
            "GET",
            "/api/v1/orders/$id",
            null,
        ).getJSONObject("order")
    }

    private fun applyAccount(
        c: Context,
        account: JSONObject,
        forceSubscriptions: Boolean = false,
        expectedAuthEpoch: Long? = null,
        deferEntitlementWork: Boolean = false,
    ): BlueVpnAccountSnapshot {
        if (!hasSession(c)) return snapshot(c)
        if (expectedAuthEpoch != null && authSessionEpoch.get() != expectedAuthEpoch) return snapshot(c)
        val subscription =
            account.optJSONObject("subscription") ?: JSONObject()
        val incomingUrl = subscription.optString("url").trim()
        val incomingPoolIdentity = subscription.optString("pool_identity").trim()
        val previousPoolIdentity = prefs(c).getString("pool_identity", "").orEmpty()
        val previous = snapshot(c)
        if (previous.subscriptionActive) {
            // Capture the old exact pool under the old account identity before
            // an entitlement URL rotation rewrites local account metadata.
            val previousExact = usableServerGuids(managedSubscriptionGuids(c))
            if (previousExact.isNotEmpty()) {
                rememberPremiumLastKnownGood(c, previousExact)
            }
        }

        c.getSharedPreferences(
            "bluevpn_subscription_info",
            Context.MODE_PRIVATE
        ).edit().clear().apply()

        val identity = account.optString("display_identity").ifBlank {
            account.optString("phone_display").ifBlank {
                account.optString("phone").ifBlank {
                    account.optString("email")
                }
            }
        }
        val incomingActive = effectiveSubscriptionActive(subscription)
        val incomingStatus = subscription.optString("status", "inactive").trim().lowercase(Locale.US)
        val syncError = subscription.optString("sync_error").trim()
        val terminalStatuses = setOf("expired", "disabled", "blocked", "deleted", "cancelled", "canceled")
        val preserveLastGoodPremium =
            previous.subscriptionActive &&
                previous.subscriptionUrl.trim().startsWith("http") &&
                !incomingActive &&
                syncError.isNotBlank() &&
                incomingStatus !in terminalStatuses
        val effectiveActive = incomingActive || preserveLastGoodPremium
        val url = if (preserveLastGoodPremium && incomingUrl.isBlank()) {
            previous.subscriptionUrl.trim()
        } else {
            incomingUrl
        }
        val poolIdentity = if (preserveLastGoodPremium) {
            previousPoolIdentity
        } else {
            incomingPoolIdentity
        }
        val entitlementChanged =
            previous.subscriptionActive != effectiveActive ||
                previous.subscriptionUrl.trim() != url.trim() ||
                (poolIdentity.isNotBlank() && poolIdentity != previousPoolIdentity)
        val committed = synchronized(authStateLock) {
            if (!hasSession(c) ||
                (expectedAuthEpoch != null && authSessionEpoch.get() != expectedAuthEpoch)
            ) {
                false
            } else {
                prefs(c).edit()
                    .putString("email", identity)
                    .putBoolean("phone_verified", account.optBoolean("phone_verified", false))
                    .putString("auth_method", account.optString("auth_method", "legacy_email"))
                    .putBoolean(
                        "active",
                        effectiveActive
                    )
                    .putString(
                        "status",
                        if (preserveLastGoodPremium) previous.status else subscription.optString("status", "inactive")
                    )
                    .putString(
                        "expire",
                        subscription.optString("expire")
                            .takeIf {
                                it.isNotBlank() && it != "null"
                            }
                    )
                    .putString(
                        "expire_fa",
                        subscription.optString("expire_fa")
                            .takeIf {
                                it.isNotBlank() && it != "null"
                            }
                    )
                    .putString("url", url)
                    .putString("pool_identity", poolIdentity)
                    .putLong(
                        "limit",
                        subscription.optLong("data_limit_bytes")
                    )
                    .putLong(
                        "used",
                        subscription.optLong("used_traffic_bytes")
                    )
                    .putInt(
                        "devices",
                        subscription.optInt("device_limit", 1)
                    )
                    .putLong(
                        "last_sync",
                        System.currentTimeMillis()
                    )
                    .putString(
                        "sync_error",
                        syncError
                    )
                    .putString(
                        "active_reason",
                        subscription.optString("active_reason")
                    )
                    .putLong(
                        "account_app_version",
                        currentAppVersion()
                    )
                    .commit()
                invalidateAccountSnapshot()
                if (identity.isNotBlank()) {
                    backup(c).edit()
                        .putString("email", identity)
                        .commit()
                }
                true
            }
        }
        if (!committed) return snapshot(c)
        if (expectedAuthEpoch != null && authSessionEpoch.get() != expectedAuthEpoch) return snapshot(c)
        if (!hasSession(c)) return snapshot(c)

        val entitlementWork = {
            if (effectiveActive) {
                stopFreeSession(c, expired = false)
                if (forceSubscriptions) {
                    // A forced account refresh must not re-import a healthy unchanged
                    // subscription. Re-importing on every screen entry clears MMKV for
                    // a short window and makes locations flash in and out.
                    val poolMissing = preferredServerGuids(c).isEmpty()
                    reconcileSubscriptionMode(
                        c = c.applicationContext,
                        premiumActive = true,
                        premiumUrl = url,
                        forceRefresh = entitlementChanged || poolMissing,
                    )
                } else if (url.startsWith("http")) {
                    // A routine account payload must not re-import a healthy pool.
                    // But a server-authored pool_identity change is an entitlement
                    // mutation even when the URL is unchanged (for example a plan or
                    // provider ownership change). In that case one authoritative
                    // refresh is required; otherwise the UI can show the new plan
                    // while MMKV still contains the previous plan's routes.
                    val exactPoolMissing = preferredServerGuids(c).isEmpty()
                    if (entitlementChanged || exactPoolMissing) {
                        reconcileSubscriptionMode(
                            c = c.applicationContext,
                            premiumActive = true,
                            premiumUrl = url,
                            forceRefresh = true,
                        )
                    }
                }
            } else {
                reconcileSubscriptionMode(
                    c = c.applicationContext,
                    premiumActive = false,
                    premiumUrl = "",
                    forceRefresh = false,
                )
                prepareFreeAccess(c, force = false)
            }
            BlueVpnEntitlement.reconcile(c)
        }

        // Authentication must finish as soon as the server has issued a valid
        // session. Subscription import, Free/Premium pool reconciliation and AI
        // preparation can be expensive (hundreds of profiles) and must never keep
        // the login/OTP screen blocked. Auth callers explicitly defer this work.
        if (deferEntitlementWork) {
            backgroundExecutor.execute { runCatching { entitlementWork() } }
        } else {
            entitlementWork()
        }
        return snapshot(c)
    }

    private fun request(
        c: Context,
        method: String,
        path: String,
        body: JSONObject?,
        auth: Boolean,
        accessOverride: String? = null,
    ): JSONObject {
        val connection =
            URL(apiBaseUrl() + path)
                .openConnection() as HttpURLConnection

        try {
            connection.requestMethod = method
            val invoiceRequest = method == "POST" && path == "/api/v1/orders"
            val otpRequest = method == "POST" && path in setOf(
                "/api/v1/auth/otp/request",
                "/api/v1/account/phone/otp/request",
            )
            connection.connectTimeout = when {
                invoiceRequest -> 12_000
                otpRequest -> 10_000
                else -> 7_000
            }
            // OTP is synchronous by design: WordPress must wait for IranPayamak
            // to accept/reject the pattern request before returning a challenge.
            // The backend provider timeout is intentionally shorter than this
            // client budget so Android receives the real provider error instead
            // of timing out first with a generic "no server response" message.
            connection.readTimeout = when {
                invoiceRequest -> 50_000
                otpRequest -> 30_000
                else -> 12_000
            }
            connection.useCaches = false
            connection.setRequestProperty("Cache-Control", "no-cache")
            connection.setRequestProperty(
                "Accept",
                "application/json"
            )
            connection.setRequestProperty(
                "Content-Type",
                "application/json"
            )
            connection.setRequestProperty(
                "X-Device-ID",
                deviceId(c)
            )
            connection.setRequestProperty(
                "User-Agent",
                "BlueVPN/${BuildConfig.VERSION_NAME}"
            )

            if (auth) {
                val access = accessOverride ?: token(c)
                if (access.isBlank()) {
                    throw ApiException(
                        401,
                        "AUTH_REQUIRED",
                        "ورود لازم است",
                    )
                }
                connection.setRequestProperty(
                    "Authorization",
                    "Bearer $access"
                )
            }

            if (body != null && method != "GET") {
                connection.doOutput = true
                connection.outputStream
                    .bufferedWriter()
                    .use { it.write(body.toString()) }
            }

            val status = connection.responseCode
            val stream = if (status in 200..299) {
                connection.inputStream
            } else {
                connection.errorStream
            }

            val raw = stream
                ?.bufferedReader()
                ?.use { it.readText() }
                .orEmpty()

            val response = if (raw.isBlank()) {
                JSONObject()
            } else {
                runCatching { JSONObject(raw) }.getOrElse {
                    val fallback = if (status in listOf(502, 503, 504)) {
                        "سرویس موردنیاز موقتاً در دسترس نیست؛ چند لحظه دیگر دوباره تلاش کنید."
                    } else {
                        "پاسخ معتبر از سرور دریافت نشد."
                    }
                    JSONObject().put(
                        "detail",
                        JSONObject()
                            .put("code", "HTTP_$status")
                            .put("message", fallback),
                    )
                }
            }

            if (status !in 200..299) {
                val detail = response.opt("detail")
                val code = if (detail is JSONObject) {
                    detail.optString("code", "HTTP_$status")
                } else {
                    "HTTP_$status"
                }

                throw ApiException(
                    status,
                    code,
                    if (status in listOf(502, 503, 504)) {
                        safeApiMessage(
                            message(response),
                            "سرویس موردنیاز موقتاً در دسترس نیست؛ چند لحظه دیگر دوباره تلاش کنید.",
                        )
                    } else {
                        message(response)
                    },
                )
            }

            return response
        } catch (error: SocketTimeoutException) {
            val invoiceRequest = method == "POST" && path == "/api/v1/orders"
            val otpRequest = method == "POST" && path in setOf(
                "/api/v1/auth/otp/request",
                "/api/v1/account/phone/otp/request",
            )
            throw ApiException(
                0,
                when {
                    invoiceRequest -> "BLUEPAY_TIMEOUT"
                    otpRequest -> "SMS_REQUEST_TIMEOUT"
                    else -> "NETWORK_TIMEOUT"
                },
                when {
                    invoiceRequest -> "ساخت فاکتور بیش از حد طول کشید؛ اتصال اینترنت را بررسی کرده و دوباره تلاش کنید. فاکتور تکراری ساخته نمی‌شود."
                    otpRequest -> "سامانه پیامک در مهلت مقرر پاسخ نداد؛ چند لحظه دیگر دوباره تلاش کنید."
                    else -> "پاسخ سرور دیر دریافت شد؛ اتصال اینترنت را بررسی و دوباره تلاش کنید."
                },
            )
        } catch (error: UnknownHostException) {
            throw ApiException(
                0,
                "DNS_UNAVAILABLE",
                "آدرس سرور پیدا نشد؛ اینترنت یا DNS دستگاه را بررسی کنید.",
            )
        } catch (error: ConnectException) {
            throw ApiException(
                0,
                "CONNECTION_FAILED",
                "اتصال به سرور برقرار نشد؛ چند لحظه دیگر دوباره تلاش کنید.",
            )
        } catch (error: IOException) {
            throw ApiException(
                0,
                "NETWORK_IO",
                error.message?.takeIf { it.isNotBlank() }
                    ?: "ارتباط شبکه با سرور قطع شد؛ دوباره تلاش کنید.",
            )
        } finally {
            connection.disconnect()
        }
    }

    private fun safeApiMessage(value: String, fallback: String): String {
        val raw = value.trim()
        if (raw.isBlank()) return fallback
        val lowered = raw.lowercase(Locale.ROOT)
        if (
            lowered.contains("<!doctype") ||
            lowered.contains("<html") ||
            lowered.contains("<body") ||
            lowered.contains("cdn-cgi") ||
            lowered.contains("error-section__")
        ) {
            return fallback
        }
        val cleaned = raw
            .replace(Regex("<[^>]+>"), " ")
            .replace(Regex("\\s+"), " ")
            .trim()
        return cleaned.take(180).ifBlank { fallback }
    }

    private fun message(response: JSONObject): String {
        val detail = response.opt("detail")
        val raw = if (detail is JSONObject) {
            detail.optString(
                "message",
                detail.optString("code", "خطای سرور")
            )
        } else {
            detail?.toString()
                ?.takeIf { it.isNotBlank() }
                ?: response.optString(
                    "message",
                    "خطای ارتباط با سرور"
                )
        }
        return safeApiMessage(raw, "خطای ارتباط با سرور")
    }
}


class BlueVpnFreeSessionReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent?) {
        if (intent?.action != "com.v2ray.ang.bluevpn.FREE_SESSION_EXPIRED") return
        BlueVpnAccountManager.enforceFreeSession(context.applicationContext)
    }
}
