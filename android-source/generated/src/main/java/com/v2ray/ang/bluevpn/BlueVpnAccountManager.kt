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
import com.v2ray.ang.handler.AngConfigManager
import com.v2ray.ang.handler.MmkvManager
import org.json.JSONArray
import org.json.JSONObject
import java.net.HttpURLConnection
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
            BlueVpnPreferences.setSmartBalance(appContext, true)
            BlueVpnPreferences.setPreferredLocation(appContext, "")
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
            val item = SubscriptionItem(
                remarks = remark,
                url = source.url,
                enabled = true,
                autoUpdate = true,
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
                SubscriptionItem(
                    remarks = old.subscription.remarks,
                    url = old.subscription.url,
                    enabled = false,
                    autoUpdate = old.subscription.autoUpdate,
                ),
            )
        }

        if (!recent || existing.isEmpty()) {
            AngConfigManager.updateConfigViaSubAll()
        }
        MmkvManager.decodeSubscriptions()
            .filter { it.subscription.enabled && it.subscription.remarks.startsWith(FREE_SUB) }
            .forEach { installedGuids += it.guid }
        storage.edit()
            .putStringSet("subscription_guids", installedGuids)
            .putString("subscription_guid", installedGuids.firstOrNull().orEmpty())
            .putLong("installed_at", System.currentTimeMillis())
            .commit()
        BlueVpnLocationUtil.invalidateCache()
    }

    private fun configuredFreeSubscriptionGuids(c: Context): Set<String> {
        val storage = freePrefs(c)
        return storage.getStringSet("subscription_guids", emptySet()).orEmpty()
            .ifEmpty {
                storage.getString("subscription_guid", "").orEmpty()
                    .takeIf { it.isNotBlank() }
                    ?.let { setOf(it) }
                    .orEmpty()
            }
    }

    private fun allFreeSubscriptionGuids(): Set<String> =
        MmkvManager.decodeSubscriptions()
            .asSequence()
            .filter { it.subscription.remarks.startsWith(FREE_SUB) }
            .map { it.guid }
            .filter { it.isNotBlank() }
            .toSet()

    private fun managedSubscriptionGuid(): String =
        MmkvManager.decodeSubscriptions()
            .firstOrNull { it.subscription.remarks == SUB }
            ?.guid
            .orEmpty()

    /**
     * Returns server GUIDs belonging to the current entitlement before the
     * global MMKV list is scanned. This prevents a free account from missing
     * its servers merely because premium/legacy profiles occupy the first
     * entries in the database.
     */
    fun preferredServerGuids(c: Context): List<String> {
        val subscriptionGuids = when {
            active(c) -> managedSubscriptionGuid()
                .takeIf { it.isNotBlank() }
                ?.let { listOf(it) }
                .orEmpty()
            isFreeMode(c) -> configuredFreeSubscriptionGuids(c).toList()
            else -> emptyList()
        }
        return subscriptionGuids
            .flatMap { guid ->
                runCatching { MmkvManager.decodeServerList(guid) }
                    .getOrDefault(emptyList())
            }
            .filter { it.isNotBlank() }
            .distinct()
    }

    fun candidateAllowed(c: Context, subscriptionId: String?): Boolean {
        val id = subscriptionId.orEmpty()
        val allFreeGuids = allFreeSubscriptionGuids()
        return when {
            active(c) -> {
                val managedGuid = managedSubscriptionGuid()
                if (managedGuid.isNotBlank()) id == managedGuid
                else id.isNotBlank() && id !in allFreeGuids
            }
            isFreeMode(c) -> id in configuredFreeSubscriptionGuids(c)
            else -> false
        }
    }

    private fun reconcileSubscriptionMode(
        c: Context,
        premiumActive: Boolean,
        premiumUrl: String,
        forceRefresh: Boolean,
    ) {
        val existing = MmkvManager.decodeSubscriptions()
        var changed = false
        var mustRefresh = forceRefresh

        existing.filter { it.subscription.remarks.startsWith(FREE_SUB) }.forEach { row ->
            val shouldEnable = !premiumActive &&
                row.guid in configuredFreeSubscriptionGuids(c)
            if (row.subscription.enabled != shouldEnable) {
                MmkvManager.encodeSubscription(
                    row.guid,
                    SubscriptionItem(
                        remarks = row.subscription.remarks,
                        url = row.subscription.url,
                        enabled = shouldEnable,
                        autoUpdate = row.subscription.autoUpdate,
                    ),
                )
                changed = true
            }
        }

        val managed = existing.firstOrNull { it.subscription.remarks == SUB }
        if (premiumActive && premiumUrl.startsWith("http")) {
            val needsManagedWrite = managed == null ||
                managed.subscription.url != premiumUrl ||
                !managed.subscription.enabled
            if (needsManagedWrite) {
                MmkvManager.encodeSubscription(
                    managed?.guid.orEmpty(),
                    SubscriptionItem(
                        remarks = SUB,
                        url = premiumUrl,
                        enabled = true,
                        autoUpdate = true,
                    ),
                )
                changed = true
                mustRefresh = true
            }
            val currentGuid = managedSubscriptionGuid()
            if (currentGuid.isBlank() ||
                runCatching { MmkvManager.decodeServerList(currentGuid).isEmpty() }
                    .getOrDefault(true)) {
                mustRefresh = true
            }
        } else if (managed != null && managed.subscription.enabled) {
            MmkvManager.encodeSubscription(
                managed.guid,
                SubscriptionItem(
                    remarks = managed.subscription.remarks,
                    url = managed.subscription.url,
                    enabled = false,
                    autoUpdate = managed.subscription.autoUpdate,
                ),
            )
            changed = true
        }

        if (mustRefresh) {
            AngConfigManager.updateConfigViaSubAll()
        }
        if (changed || mustRefresh) {
            BlueVpnLocationUtil.invalidateCache()
            val selectedGuid = MmkvManager.getSelectServer().orEmpty()
            val selectedProfile = selectedGuid
                .takeIf { it.isNotBlank() }
                ?.let { MmkvManager.decodeServerConfig(it) }
            if (selectedProfile == null ||
                !candidateAllowed(c, selectedProfile.subscriptionId)) {
                preferredServerGuids(c).firstOrNull()?.let {
                    MmkvManager.setSelectServer(it)
                }
            }
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
            .apply()

        backup(appContext).edit()
            .clear()
            .putString("device_id", id)
            .apply()
        invalidateAccountSnapshot()

        appContext.getSharedPreferences(
            "bluevpn_subscription_info",
            Context.MODE_PRIVATE
        ).edit().clear().apply()

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
        val entitlementUnknown =
            !local.subscriptionActive ||
                local.subscriptionUrl.isBlank() ||
                local.status.equals("inactive", ignoreCase = true)
        if (
            !force &&
            !appUpdated &&
            !entitlementUnknown &&
            System.currentTimeMillis() - last <
            AUTO_SYNC_INTERVAL_MS
        ) {
            return@runCatching local
        }

        val response = authenticatedRequest(
            c,
            "POST",
            "/api/v1/account/sync",
            JSONObject(),
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
        authenticatedRequest(
            c,
            "POST",
            "/api/v1/orders",
            JSONObject().put("plan_id", planId),
        ).getJSONObject("order")
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
        authenticatedRequest(
            c,
            "POST",
            "/api/v1/ai/events",
            payload,
        )
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
        authenticatedRequest(c, "GET", path, null)
    }

    fun aiDashboard(c: Context): Result<JSONObject> = runCatching {
        authenticatedRequest(
            c,
            "GET",
            "/api/v1/ai/dashboard",
            null,
        )
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
        val url = subscription.optString("url")
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
        val effectiveActive = effectiveSubscriptionActive(subscription)
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
                subscription.optString(
                    "status",
                    "inactive"
                )
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
                subscription.optString("sync_error")
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
                reconcileSubscriptionMode(
                    c = c.applicationContext,
                    premiumActive = true,
                    premiumUrl = url,
                    forceRefresh = true,
                )
            } else if (url.startsWith("http")) {
                // Login/registration returns immediately; foreground/order
                // refreshes pass forceSubscriptions=true and wait for the
                // entitlement hot-swap before updating the UI.
                scheduleInstall(url)
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
        return snapshot(c)
    }

    private fun scheduleInstall(url: String) {
        val now = System.currentTimeMillis()
        if (url == lastScheduledSubscriptionUrl && now - lastScheduledSubscriptionAt < 5_000L) {
            return
        }
        lastScheduledSubscriptionUrl = url
        lastScheduledSubscriptionAt = now
        subscriptionInstallExecutor.execute {
            runCatching { install(url) }
        }
    }

    private fun install(url: String) {
        val subscriptions = MmkvManager.decodeSubscriptions()
        val old = subscriptions.firstOrNull {
            it.subscription.remarks == SUB
        }

        subscriptions
            .filter { it.subscription.remarks.startsWith(FREE_SUB) && it.subscription.enabled }
            .forEach { row ->
                MmkvManager.encodeSubscription(
                    row.guid,
                    SubscriptionItem(
                        remarks = row.subscription.remarks,
                        url = row.subscription.url,
                        enabled = false,
                        autoUpdate = row.subscription.autoUpdate,
                    ),
                )
            }

        val item = SubscriptionItem(
            remarks = SUB,
            url = url,
            enabled = true,
            autoUpdate = true,
        )

        MmkvManager.encodeSubscription(
            old?.guid.orEmpty(),
            item,
        )
        AngConfigManager.updateConfigViaSubAll()
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
            connection.connectTimeout = 7_000
            connection.readTimeout = 12_000
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
