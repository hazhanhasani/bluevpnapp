package com.v2ray.ang.bluevpn

import android.app.AlarmManager
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.os.Build
import android.provider.Settings
import com.v2ray.ang.BuildConfig
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
    private val subscriptionReconcileLock = Any()
    @Volatile private var subscriptionRefreshRunning = false
    private val backgroundExecutor = Executors.newSingleThreadExecutor { task ->
        Thread(task, "bluevpn-account-background").apply { isDaemon = true }
    }
    private val subscriptionInstallExecutor = Executors.newSingleThreadExecutor { task ->
        Thread(task, "bluevpn-subscription-install").apply { isDaemon = true }
    }
    @Volatile private var lastScheduledSubscriptionUrl = ""
    @Volatile private var lastScheduledSubscriptionAt = 0L
    private val primaryRestored = AtomicBoolean(false)
    private val primaryRestoreLock = Any()
    private val freePrepareLock = Any()
    private val mobileConfigLock = Any()
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
            val response = request(c.applicationContext, "GET", "/api/v1/mobile/config", null, false)
            mobileConfigCacheRaw = response.toString()
            mobileConfigCacheAt = lockedNow
            JSONObject(mobileConfigCacheRaw)
        }
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

    private fun invalidateFreeSnapshot() {
        freeSnapshotCacheAt = 0L
        freeSnapshotCache = null
    }

    private fun persistAuth(
        c: Context,
        token: String,
        refreshToken: String,
        email: String,
    ) {
        val device = deviceId(c)

        prefs(c).edit()
            .putString("token", token)
            .putString("refresh_token", refreshToken)
            .putString("email", email)
            .putString("device_id", device)
            .remove("auth_error")
            .apply()

        backup(c).edit()
            .putString("token", token)
            .putString("refresh_token", refreshToken)
            .putString("email", email)
            .putString("device_id", device)
            .putLong("saved_at", System.currentTimeMillis())
            .apply()
        invalidateAccountSnapshot()
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

    fun entitlement(c: Context): BlueVpnEntitlementSnapshot =
        BlueVpnEntitlement.resolve(c)

    private fun freePrefs(c: Context) =
        c.getSharedPreferences(FREE_PREFS, Context.MODE_PRIVATE)

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
        val snapshot = BlueVpnFreeAccessSnapshot(
            enabled = storage.getBoolean("enabled", false),
            subscriptionUrl = ordered.firstOrNull()?.url.orEmpty(),
            subscriptions = ordered,
            sessionMinutes = storage.getInt("session_minutes", 60).coerceIn(15, 180),
        )
        freeSnapshotCache = snapshot
        freeSnapshotCacheAt = now
        return snapshot
    }

    fun freeAccessEnabled(c: Context): Boolean {
        val snapshot = freeAccessSnapshot(c)
        return snapshot.enabled && snapshot.subscriptions.isNotEmpty()
    }

    fun isFreeMode(c: Context): Boolean =
        !active(c) && freeAccessEnabled(c)

    fun hasInstalledFreeServers(c: Context): Boolean {
        val storage = freePrefs(c.applicationContext)
        val guids = storage.getStringSet("subscription_guids", emptySet()).orEmpty()
            .ifEmpty {
                storage.getString("subscription_guid", "").orEmpty()
                    .takeIf { it.isNotBlank() }
                    ?.let { setOf(it) }
                    .orEmpty()
            }
        return guids.any { guid ->
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
        if (!ownsPreparation) return@runCatching freeAccessEnabled(appContext)
        try {

            val storage = freePrefs(appContext)
            val now = System.currentTimeMillis()
            val lastConfigAt = storage.getLong("config_at", 0L)
            val shouldFetch = force || now - lastConfigAt > FREE_CONFIG_TTL_MS ||
                freeAccessSnapshot(appContext).subscriptions.isEmpty()

            if (shouldFetch) {
                val config = mobileConfig(appContext, force = force).getOrThrow()
                val free = config.optJSONObject("free_access") ?: JSONObject()
                val sources = free.optJSONArray("subscriptions") ?: JSONArray()
                val storedSources = JSONArray()
                for (index in 0 until sources.length()) {
                    val row = sources.optJSONObject(index) ?: continue
                    val url = row.optString("subscription_url").trim()
                    if (!url.startsWith("http")) continue
                    storedSources.put(JSONObject()
                        .put("id", row.optString("id").trim().ifBlank { "source-$index" })
                        .put("name", row.optString("name").trim().ifBlank { "سرور رایگان ${index + 1}" })
                        .put("url", url)
                        .put("priority", row.optInt("priority", index)))
                }
                val legacyUrl = free.optString("subscription_url").trim()
                if (storedSources.length() == 0 && legacyUrl.startsWith("http")) {
                    storedSources.put(JSONObject()
                        .put("id", "legacy-default")
                        .put("name", "سرور رایگان")
                        .put("url", legacyUrl)
                        .put("priority", 0))
                }
                storage.edit()
                    .putBoolean("enabled", free.optBoolean("enabled", false))
                    .putString("subscription_url", legacyUrl)
                    .putString("subscriptions_json", storedSources.toString())
                    .putInt("session_minutes", free.optInt("session_minutes", 60).coerceIn(15, 180))
                    .putLong("config_at", now)
                    .apply()
                invalidateFreeSnapshot()
            }

            if (active(appContext)) {
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
                installFreeSubscriptions(appContext, snapshot.subscriptions, force)
                storage.edit()
                    .putString("installed_sources_fingerprint", fingerprint)
                    .apply()
            }
            BlueVpnPreferences.setAutomaticSelection(appContext)
            true
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
    ) {
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
            val unchanged = old?.subscription?.url == source.url && old.subscription.enabled
            val item = old?.subscription?.copy(
                remarks = remark,
                url = source.url,
                enabled = true,
                autoUpdate = false,
                userAgent = old.subscription.userAgent
                    ?: BlueVpnSubscriptionIntelligence.recommendedUserAgent(c, source.url),
            ) ?: SubscriptionItem(
                remarks = remark,
                url = source.url,
                enabled = true,
                autoUpdate = false,
                userAgent = BlueVpnSubscriptionIntelligence.recommendedUserAgent(c, source.url),
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

        if (!recent || existing.isEmpty()) {
            val desiredUrls = sources.map { it.url.trim() }.toSet()
            val refreshRows = MmkvManager.decodeSubscriptions().filter { row ->
                row.subscription.enabled &&
                    row.subscription.remarks.startsWith(FREE_SUB) &&
                    row.subscription.url.trim() in desiredUrls
            }
            BlueVpnSubscriptionIntelligence.refresh(
                c,
                refreshRows,
                aggressiveRepair = existing.isEmpty(),
            )
        }
        MmkvManager.decodeSubscriptions()
            .filter { it.subscription.enabled && it.subscription.remarks.startsWith(FREE_SUB) }
            .forEach { installedGuids += it.guid }
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
        BlueVpnLocationUtil.invalidateCache()
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

    fun entitlementSubscriptionGuids(c: Context): Set<String> = when {
        active(c) -> managedSubscriptionGuids(c)
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
        if (selected.isNotBlank() && !active(c)) {
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
    fun preferredServerGuids(c: Context): List<String> =
        usableServerGuids(entitlementSubscriptionGuids(c))

    fun entitlementPoolFingerprint(c: Context): String {
        val mode = when {
            active(c) -> "premium"
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
        active(c) -> "premium|${snapshot(c).subscriptionUrl.trim()}"
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
            // preferredServerGuids() is strict for Free mode and contains the
            // last-known-good compatibility ladder for Premium mode.
            return guid in entitlementServerGuids
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
        if (hasSession(appContext)) {
            val current = snapshot(appContext)
            if (current.subscriptionActive && current.subscriptionUrl.startsWith("http")) {
                reconcileSubscriptionMode(
                    c = appContext,
                    premiumActive = true,
                    premiumUrl = current.subscriptionUrl,
                    forceRefresh = preferredServerGuids(appContext).isEmpty(),
                )
            } else {
                sync(appContext, force = true).getOrThrow()
            }
        } else {
            prepareFreeAccess(appContext, force = true).getOrThrow()
        }
        val premiumPoolReady = !active(appContext) || preferredServerGuids(appContext).isNotEmpty()
        if (premiumPoolReady) {
            pruneInactiveManagedPools(appContext)
        }
        ensureEntitlementSelection(appContext)

        val deadline = android.os.SystemClock.elapsedRealtime() + timeoutMs.coerceIn(2_000L, 30_000L)
        var lastCount = 0
        do {
            val guids = preferredServerGuids(appContext)
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
    ) = synchronized(subscriptionReconcileLock) {
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

            val needsManagedWrite = managed == null || !managed.subscription.enabled
            if (needsManagedWrite) {
                val item = managed?.subscription?.copy(
                    remarks = SUB,
                    url = normalizedPremiumUrl,
                    enabled = true,
                    autoUpdate = false,
                    userAgent = managed.subscription.userAgent
                        ?: BlueVpnSubscriptionIntelligence.recommendedUserAgent(c, normalizedPremiumUrl),
                ) ?: SubscriptionItem(
                    remarks = SUB,
                    url = normalizedPremiumUrl,
                    enabled = true,
                    autoUpdate = false,
                    userAgent = BlueVpnSubscriptionIntelligence.recommendedUserAgent(c, normalizedPremiumUrl),
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
                BlueVpnSubscriptionIntelligence.refresh(
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
        if (changed || mustRefresh) {
            val currentPoolReady = !premiumActive || preferredServerGuids(c).isNotEmpty()
            if (currentPoolReady) {
                // Transactional swap complete: only now delete stale Premium
                // rows. Until this point they are disabled and invisible to
                // BlueVPN's entitlement-aware selector, but remain recoverable.
                pruneInactiveManagedPools(c)
            }
            BlueVpnLocationUtil.invalidateCache()
            ensureEntitlementSelection(c)
        }
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
        // Legacy direct call replaced: CoreServiceManager.stopVService(appContext)
        runCatching { BlueVpnEngineManager.stop(appContext) }
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
        )
        accountSnapshotCache = snapshot
        accountSnapshotCacheAt = now
        return snapshot
    }

    fun logout(c: Context) {
        val appContext = c.applicationContext
        val id = deviceId(appContext)
        val access = token(appContext)

        // Logout must be immediate from the user's perspective. Stop the tunnel
        // before deleting credentials so an authenticated VPN session can never
        // remain active after the account has been left.
        // Legacy direct call replaced: CoreServiceManager.stopVService(appContext)
        runCatching { BlueVpnEngineManager.stop(appContext) }
        runCatching { BlueVpnPreferences.clearConnected(appContext) }

        prefs(appContext).edit()
            .clear()
            .putString("device_id", id)
            .commit()

        backup(appContext).edit()
            .clear()
            .putString("device_id", id)
            .commit()
        invalidateAccountSnapshot()

        // Logout is also an entitlement boundary. Drop every account-owned UI
        // choice immediately so the home screen can fall back to guest/free
        // without showing the previous Premium account for another frame.
        BlueVpnPreferences.clearConnected(appContext)
        BlueVpnPreferences.setAutomaticSelection(appContext)
        BlueVpnPreferences.beginHealthSession(appContext)
        BlueVpnSmartSelector.clear(appContext)
        BlueVpnLocationUtil.invalidateCache()
        stopFreeSession(appContext, expired = false)

        appContext.getSharedPreferences(
            "bluevpn_subscription_info",
            Context.MODE_PRIVATE
        ).edit().clear().commit()

        // Disable old Premium subscription rows off the UI thread without
        // deleting their v2rayNG profiles. Free sources are re-enabled by
        // prepareFreeAccess() when Home resumes.
        subscriptionInstallExecutor.execute {
            runCatching { pruneInactiveManagedPools(appContext) }
            BlueVpnLocationUtil.invalidateCache()
        }

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
        val id = deviceId(c)
        val email = snapshot(c).email

        prefs(c).edit()
            .remove("token")
            .remove("refresh_token")
            .putString("email", email)
            .putString("device_id", id)
            .putString("auth_error", code)
            .apply()

        backup(c).edit()
            .remove("token")
            .remove("refresh_token")
            .putString("email", email)
            .putString("device_id", id)
            .apply()
        invalidateAccountSnapshot()
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
            persistAuth(c, access, refresh, phone.trim())
        }

        applyAccount(c, response.getJSONObject("account"))
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
        persistAuth(c, access, refresh, normalizedEmail)
        applyAccount(c, response.getJSONObject("account"))
    }

    private fun refreshSession(
        c: Context,
        failedAccessToken: String,
    ): Boolean = synchronized(refreshLock) {
        restorePrimary(c)

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

            persistAuth(
                c,
                access,
                newRefresh,
                identity,
            )

            response.optJSONObject("account")
                ?.let { applyAccount(c, it) }

            true
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
    ): Result<BlueVpnAccountSnapshot> = runCatching {
        if (!hasSession(c)) error("AUTH_REQUIRED")

        val last = prefs(c).getLong("last_sync", 0)
        val local = snapshot(c)
        val appUpdated = accountCacheVersion(c) != currentAppVersion()
        // An inactive/free account is a valid stable state. Treat only an active
        // Premium account with a missing URL as incomplete; otherwise routine
        // foreground resumes would hit WordPress and the remote panels forever.
        val entitlementIncomplete =
            local.subscriptionActive && local.subscriptionUrl.isBlank()
        if (
            !force &&
            !appUpdated &&
            !entitlementIncomplete &&
            System.currentTimeMillis() - last < AUTO_SYNC_INTERVAL_MS
        ) {
            return@runCatching local
        }

        // Routine refresh reads the WordPress snapshot only. Provider polling is
        // reserved for explicit/forced sync and is throttled server-side.
        val response = authenticatedRequest(
            c,
            if (force) "POST" else "GET",
            if (force) "/api/v1/account/sync" else "/api/v1/account",
            if (force) JSONObject() else null,
        )

        applyAccount(
            c,
            response.getJSONObject("account"),
            forceSubscriptions = force,
        )
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
    ): Result<JSONObject> = runCatching {
        val path = "/api/v1/ai/recommendations" +
            "?operator=" + java.net.URLEncoder.encode(operator, "UTF-8") +
            "&network_type=" + java.net.URLEncoder.encode(networkType, "UTF-8") +
            "&mode=" + java.net.URLEncoder.encode(mode, "UTF-8")
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
    ): BlueVpnAccountSnapshot {
        val subscription =
            account.optJSONObject("subscription") ?: JSONObject()
        val incomingUrl = subscription.optString("url").trim()
        val previous = snapshot(c)

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
        val entitlementChanged =
            previous.subscriptionActive != effectiveActive ||
                previous.subscriptionUrl.trim() != url.trim()
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
            .apply()
        invalidateAccountSnapshot()

        if (identity.isNotBlank()) {
            backup(c).edit()
                .putString("email", identity)
                .apply()
        }

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
                // Install only when the entitlement URL actually changed or the
                // exact Premium pool does not exist locally.
                val exactPoolMissing = preferredServerGuids(c).isEmpty()
                if (entitlementChanged || exactPoolMissing) {
                    scheduleInstall(c, url)
                }
            }
        } else {
            reconcileSubscriptionMode(
                c = c.applicationContext,
                premiumActive = false,
                premiumUrl = "",
                forceRefresh = false,
            )
            backgroundExecutor.execute { prepareFreeAccess(c, force = false) }
        }
        BlueVpnEntitlement.reconcile(c)
        return snapshot(c)
    }

    private fun scheduleInstall(context: Context, url: String) {
        val now = System.currentTimeMillis()
        if (url == lastScheduledSubscriptionUrl && now - lastScheduledSubscriptionAt < 5_000L) {
            return
        }
        lastScheduledSubscriptionUrl = url
        lastScheduledSubscriptionAt = now
        val appContext = context.applicationContext
        subscriptionInstallExecutor.execute {
            runCatching { install(appContext, url) }
        }
    }

    private fun install(c: Context, url: String) = synchronized(subscriptionReconcileLock) {
        val subscriptions = MmkvManager.decodeSubscriptions()
        val old = subscriptions.firstOrNull {
            it.subscription.remarks == SUB &&
                it.subscription.url.trim() == url.trim()
        }
        val selectedFingerprint = old?.guid
            ?.takeIf { it.isNotBlank() }
            ?.let { BlueVpnProfileManager.captureSelectedFingerprint(setOf(it)) }
        val currentReady = old != null &&
            old.subscription.enabled &&
            runCatching {
                MmkvManager.decodeServerList(old.guid).any { serverGuid ->
                    serverGuid.isNotBlank() && MmkvManager.decodeServerConfig(serverGuid) != null
                }
            }.getOrDefault(false)
        subscriptions
            .filter { it.subscription.remarks.startsWith(FREE_SUB) }
            .forEach { row ->
                MmkvManager.encodeSubscription(
                    row.guid,
                    row.subscription.copy(enabled = false),
                )
                // Preserve the imported Free profiles physically; they remain
                // invisible to Premium via entitlement filtering and can be
                // reused immediately after logout without a destructive swap.
            }

        val stalePremiumRows = subscriptions
            .filter { it.subscription.remarks == SUB && it.guid != old?.guid }
        stalePremiumRows.forEach { row ->
            MmkvManager.encodeSubscription(
                row.guid,
                row.subscription.copy(enabled = false),
            )
            // Keep the physical rows until the replacement Premium pool is
            // confirmed. They are disabled and entitlement filtering prevents
            // BlueVPN from selecting them during the swap.
        }

        val item = old?.subscription?.copy(
            remarks = SUB,
            url = url,
            enabled = true,
            autoUpdate = false,
            userAgent = old.subscription.userAgent
                ?: BlueVpnSubscriptionIntelligence.recommendedUserAgent(context = c, url = url),
        ) ?: SubscriptionItem(
            remarks = SUB,
            url = url,
            enabled = true,
            autoUpdate = false,
            userAgent = BlueVpnSubscriptionIntelligence.recommendedUserAgent(context = c, url = url),
        )

        if (!currentReady) {
            MmkvManager.encodeSubscription(
                old?.guid.orEmpty(),
                item,
            )
            subscriptionRefreshRunning = true
            try {
                val refreshRows = MmkvManager.decodeSubscriptions().filter { row ->
                    row.subscription.enabled &&
                        row.subscription.remarks == SUB &&
                        row.subscription.url.trim() == url.trim()
                }
                BlueVpnSubscriptionIntelligence.refresh(
                    c,
                    refreshRows,
                    aggressiveRepair = true,
                )
            } finally {
                subscriptionRefreshRunning = false
            }
        }
        val activePremiumRow = MmkvManager.decodeSubscriptions().firstOrNull { row ->
            row.subscription.enabled &&
                row.subscription.remarks == SUB &&
                row.subscription.url.trim() == url.trim()
        }
        val activePremiumReady = activePremiumRow?.guid
            ?.let { guid ->
                runCatching {
                    MmkvManager.decodeServerList(guid).any { serverGuid ->
                        serverGuid.isNotBlank() && MmkvManager.decodeServerConfig(serverGuid) != null
                    }
                }.getOrDefault(false)
            } == true

        if (activePremiumReady) {
            // Replacement is ready. Keep stale Premium profiles disabled as a
            // last-known-good fallback instead of deleting working v2rayNG data.
            // preferredServerGuids() always prefers the exact active URL first.
        }
        if (old != null && selectedFingerprint != null) {
            val refreshedServerGuids = runCatching {
                MmkvManager.decodeServerList(old.guid)
            }.getOrDefault(emptyList())
            BlueVpnProfileManager.restoreSelectedFingerprint(
                selectedFingerprint,
                refreshedServerGuids,
            )
        }
        ensureEntitlementSelection(c)
        BlueVpnLocationUtil.invalidateCache()
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
            connection.connectTimeout = if (invoiceRequest) 12_000 else 7_000
            // Invoice creation includes one outbound call from the BlueVPN backend
            // to BluePay. Twelve seconds was shorter than the backend/provider
            // timeout and made Android report «no server response» while the
            // invoice was still being created.
            connection.readTimeout = if (invoiceRequest) 50_000 else 12_000
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
            throw ApiException(
                0,
                if (invoiceRequest) "BLUEPAY_TIMEOUT" else "NETWORK_TIMEOUT",
                if (invoiceRequest) {
                    "ساخت فاکتور بیش از حد طول کشید؛ اتصال اینترنت را بررسی کرده و دوباره تلاش کنید. فاکتور تکراری ساخته نمی‌شود."
                } else {
                    "پاسخ سرور دیر دریافت شد؛ اتصال اینترنت را بررسی و دوباره تلاش کنید."
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
