package com.v2ray.ang.bluevpn

import android.content.Context
import android.os.Handler
import android.os.Looper
import android.os.SystemClock
import com.v2ray.ang.dto.entities.ProfileItem
import com.v2ray.ang.handler.MmkvManager
import org.json.JSONArray
import java.net.InetAddress
import java.net.InetSocketAddress
import java.net.Socket
import java.security.MessageDigest
import java.util.Locale
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.Executors
import java.util.concurrent.atomic.AtomicBoolean

data class BlueVpnLocation(
    val key: String,
    val title: String,
    val flag: String,
)

enum class BlueVpnSelectionMode {
    AUTO,
    MANUAL_LOCATION,
    MANUAL_SERVER,
}

object BlueVpnPreferences {
    private const val PREFS = "bluevpn_customer_preferences"
    private const val KEY_SMART_BALANCE = "smart_balance"
    private const val KEY_SELECTION_MODE = "selection_mode"
    private const val KEY_PREFERRED_LOCATION = "preferred_location"
    private const val KEY_MANUAL_SERVER_GUID = "manual_server_guid"
    private const val KEY_CONNECTED_AT = "connected_at"
    private const val FAILED_PREFIX = "failed_server_"
    private const val SESSION_INACTIVE_PREFIX = "session_inactive_"
    private const val KEY_HEALTH_SESSION_AT = "health_session_at"
    private const val FAILURE_COOLDOWN_MS = 10 * 60 * 1000L
    private const val SUCCESS_PREFIX = "successful_server_"
    private const val SUCCESS_LATENCY_PREFIX = "successful_latency_"
    private const val SUCCESS_FRESH_MS = 20 * 60 * 1000L
    private const val VERIFIED_COUNTRY_PREFIX = "verified_country_"
    private const val VERIFIED_COUNTRY_AT_PREFIX = "verified_country_at_"
    private const val VERIFIED_COUNTRY_TTL_MS = 180L * 24L * 60L * 60L * 1000L

    private fun prefs(context: Context) =
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    fun selectionMode(context: Context): BlueVpnSelectionMode {
        val storage = prefs(context)
        val raw = storage.getString(KEY_SELECTION_MODE, "").orEmpty()
        return runCatching { BlueVpnSelectionMode.valueOf(raw) }.getOrNull()
            ?: if (storage.getBoolean(KEY_SMART_BALANCE, true)) {
                BlueVpnSelectionMode.AUTO
            } else {
                BlueVpnSelectionMode.MANUAL_LOCATION
            }
    }

    fun smartBalance(context: Context): Boolean =
        selectionMode(context) == BlueVpnSelectionMode.AUTO

    fun setSmartBalance(context: Context, enabled: Boolean) {
        if (enabled) {
            setAutomaticSelection(context)
            return
        }
        val storage = prefs(context)
        val current = selectionMode(context)
        if (current == BlueVpnSelectionMode.AUTO) {
            storage.edit()
                .putBoolean(KEY_SMART_BALANCE, false)
                .putString(KEY_SELECTION_MODE, BlueVpnSelectionMode.MANUAL_LOCATION.name)
                .apply()
        } else {
            storage.edit().putBoolean(KEY_SMART_BALANCE, false).apply()
        }
    }

    fun setAutomaticSelection(context: Context) {
        prefs(context).edit()
            .putBoolean(KEY_SMART_BALANCE, true)
            .putString(KEY_SELECTION_MODE, BlueVpnSelectionMode.AUTO.name)
            .remove(KEY_MANUAL_SERVER_GUID)
            .putString(KEY_PREFERRED_LOCATION, "")
            .apply()
    }

    fun setManualLocationSelection(context: Context, locationKey: String) {
        prefs(context).edit()
            .putBoolean(KEY_SMART_BALANCE, false)
            .putString(KEY_SELECTION_MODE, BlueVpnSelectionMode.MANUAL_LOCATION.name)
            .remove(KEY_MANUAL_SERVER_GUID)
            .putString(KEY_PREFERRED_LOCATION, locationKey)
            .apply()
    }

    fun setManualServerSelection(context: Context, locationKey: String, guid: String) {
        prefs(context).edit()
            .putBoolean(KEY_SMART_BALANCE, false)
            .putString(KEY_SELECTION_MODE, BlueVpnSelectionMode.MANUAL_SERVER.name)
            .putString(KEY_MANUAL_SERVER_GUID, guid)
            .putString(KEY_PREFERRED_LOCATION, locationKey)
            .apply()
    }

    fun manualServerGuid(context: Context): String =
        prefs(context).getString(KEY_MANUAL_SERVER_GUID, "").orEmpty()

    fun preferredLocation(context: Context): String =
        prefs(context).getString(KEY_PREFERRED_LOCATION, "").orEmpty()

    fun setPreferredLocation(context: Context, key: String) {
        prefs(context).edit()
            .putString(KEY_PREFERRED_LOCATION, key)
            .apply()
    }

    fun connectedAt(context: Context): Long =
        prefs(context).getLong(KEY_CONNECTED_AT, 0L)

    fun markConnected(
        context: Context,
        resetTimer: Boolean = false,
    ) {
        val storage = prefs(context)
        val oldValue = storage.getLong(KEY_CONNECTED_AT, 0L)

        if (resetTimer || oldValue <= 0L) {
            storage.edit()
                .putLong(KEY_CONNECTED_AT, System.currentTimeMillis())
                .apply()
        }
    }

    fun clearConnected(context: Context) {
        prefs(context).edit()
            .remove(KEY_CONNECTED_AT)
            .apply()
    }

    fun markServerFailure(context: Context, guid: String) {
        if (guid.isBlank()) return
        prefs(context).edit()
            .putLong(FAILED_PREFIX + guid, System.currentTimeMillis())
            .apply()
    }

    fun clearServerFailure(context: Context, guid: String) {
        if (guid.isBlank()) return
        prefs(context).edit()
            .remove(FAILED_PREFIX + guid)
            .apply()
    }

    fun markServerSuccess(
        context: Context,
        guid: String,
        latencyMs: Long = 0L,
    ) {
        if (guid.isBlank()) return
        prefs(context).edit()
            .putLong(SUCCESS_PREFIX + guid, System.currentTimeMillis())
            .putLong(SUCCESS_LATENCY_PREFIX + guid, latencyMs.coerceAtLeast(0L))
            .remove(FAILED_PREFIX + guid)
            .remove(SESSION_INACTIVE_PREFIX + guid)
            .apply()
    }

    fun successFreshnessScore(context: Context, guid: String): Int {
        if (guid.isBlank()) return 0
        val storage = prefs(context)
        val successfulAt = storage.getLong(SUCCESS_PREFIX + guid, 0L)
        if (successfulAt <= 0L) return 0
        val age = (System.currentTimeMillis() - successfulAt).coerceAtLeast(0L)
        if (age >= SUCCESS_FRESH_MS) return 0
        val freshness = ((SUCCESS_FRESH_MS - age) * 24L / SUCCESS_FRESH_MS).toInt()
        val latency = storage.getLong(SUCCESS_LATENCY_PREFIX + guid, 0L)
        val latencyBonus = when {
            latency in 1..80 -> 10
            latency in 81..160 -> 6
            latency in 161..260 -> 2
            else -> 0
        }
        return freshness + latencyBonus
    }

    fun markVerifiedCountry(
        context: Context,
        guid: String,
        countryCode: String,
    ) {
        val normalized = countryCode.trim().lowercase(Locale.ROOT)
        val profile = MmkvManager.decodeServerConfig(guid) ?: return
        val configKey = BlueVpnLocationUtil.serverIdentity(profile)
        if (configKey.isBlank() || normalized.length != 2) return
        markVerifiedCountryKey(context, configKey, normalized)
        BlueVpnLocationUtil.reportVerifiedCountry(
            context.applicationContext,
            configKey,
            normalized,
        )
    }

    fun markVerifiedCountryKey(
        context: Context,
        configKey: String,
        countryCode: String,
    ) {
        val normalized = countryCode.trim().lowercase(Locale.ROOT)
        if (configKey.isBlank() || normalized.length != 2) return
        prefs(context).edit()
            .putString(VERIFIED_COUNTRY_PREFIX + configKey, normalized)
            .putLong(VERIFIED_COUNTRY_AT_PREFIX + configKey, System.currentTimeMillis())
            .apply()
        BlueVpnLocationUtil.invalidateResolvedCache()
    }

    fun verifiedCountry(context: Context, guid: String): String {
        if (guid.isBlank()) return ""
        val profile = MmkvManager.decodeServerConfig(guid) ?: return ""
        return verifiedCountryKey(context, BlueVpnLocationUtil.serverIdentity(profile))
    }

    fun verifiedCountryKey(context: Context, configKey: String): String {
        if (configKey.isBlank()) return ""
        val storage = prefs(context)
        val verifiedAt = storage.getLong(VERIFIED_COUNTRY_AT_PREFIX + configKey, 0L)
        if (
            verifiedAt <= 0L ||
            System.currentTimeMillis() - verifiedAt > VERIFIED_COUNTRY_TTL_MS
        ) {
            storage.edit()
                .remove(VERIFIED_COUNTRY_PREFIX + configKey)
                .remove(VERIFIED_COUNTRY_AT_PREFIX + configKey)
                .apply()
            return ""
        }
        return storage.getString(VERIFIED_COUNTRY_PREFIX + configKey, "").orEmpty()
    }

    fun failedRecently(context: Context, guid: String): Boolean {
        if (guid.isBlank()) return false

        val failedAt = prefs(context)
            .getLong(FAILED_PREFIX + guid, 0L)

        if (failedAt <= 0L) return false

        val recent =
            System.currentTimeMillis() - failedAt < FAILURE_COOLDOWN_MS

        if (!recent) {
            clearServerFailure(context, guid)
        }
        return recent
    }

    fun beginHealthSession(context: Context) {
        val storage = prefs(context)
        val editor = storage.edit()

        // Session quarantine is intentionally temporary: a route that failed
        // during the previous connect cycle is allowed back on the next user
        // attempt. FAILED_PREFIX is kept so the scorer can still penalize a
        // recently bad route for a few minutes instead of immediately picking
        // it as the first candidate again.
        storage.all.keys
            .filter { it.startsWith(SESSION_INACTIVE_PREFIX) }
            .forEach { editor.remove(it) }

        editor.putLong(
            KEY_HEALTH_SESSION_AT,
            System.currentTimeMillis()
        ).apply()
    }

    fun markSessionInactive(
        context: Context,
        guid: String,
    ) {
        if (guid.isBlank()) return
        prefs(context).edit()
            .putBoolean(SESSION_INACTIVE_PREFIX + guid, true)
            .apply()
    }

    fun clearSessionInactive(
        context: Context,
        guid: String,
    ) {
        if (guid.isBlank()) return
        prefs(context).edit()
            .remove(SESSION_INACTIVE_PREFIX + guid)
            .apply()
    }

    fun isSessionInactive(
        context: Context,
        guid: String,
    ): Boolean =
        guid.isNotBlank() &&
            prefs(context).getBoolean(
                SESSION_INACTIVE_PREFIX + guid,
                false
            )
}

object BlueVpnLocationUtil {

    private const val CANDIDATE_CACHE_TTL_MS = 60_000L
    private const val CONTEXT_STALE_GRACE_MS = 120_000L

    @Volatile
    private var candidateCacheAt = 0L

    @Volatile
    private var candidateCache: List<Candidate> = emptyList()

    @Volatile
    private var contextCandidateCacheAt = 0L

    @Volatile
    private var contextCandidateCache: List<Candidate> = emptyList()

    @Volatile
    private var contextCandidateCacheKey: String = ""

    @Volatile
    private var contextCandidateCacheDirty = false

    private val identityCache = ConcurrentHashMap<String, String>()

    private val cloudExecutor = Executors.newSingleThreadExecutor {
        Thread(it, "bluevpn-location-sync").apply { isDaemon = true }
    }
    private val cloudSyncing = AtomicBoolean(false)
    private val mainHandler = Handler(Looper.getMainLooper())

    @Volatile
    private var lastCloudSyncAt = 0L

    private const val CLOUD_SYNC_TTL_MS = 60_000L

    private data class Rule(
        val location: BlueVpnLocation,
        val flags: List<String>,
        val codes: List<String>,
        val aliases: List<String>,
    )

    private val rules = listOf(
        rule("ca", "کانادا", "🇨🇦", "ca", "can", "canada", "کانادا", "toronto", "montreal", "vancouver", "quebec"),
        rule("de", "آلمان", "🇩🇪", "de", "ger", "germany", "deutschland", "آلمان", "frankfurt", "berlin", "nuremberg", "falkenstein", "dusseldorf", "düsseldorf", "munich", "hamburg"),
        rule("nl", "هلند", "🇳🇱", "nl", "nld", "netherlands", "holland", "هلند", "amsterdam", "rotterdam", "dronten", "meppel"),
        rule("fi", "فنلاند", "🇫🇮", "fi", "fin", "finland", "فنلاند", "helsinki"),
        rule("fr", "فرانسه", "🇫🇷", "fr", "fra", "france", "فرانسه", "paris", "marseille", "gravelines", "strasbourg", "roubaix", "lyon", "lille"),
        rule("gb", "انگلیس", "🇬🇧", "gb", "uk", "united kingdom", "england", "انگلیس", "britain", "london", "manchester", "coventry"),
        rule("us", "آمریکا", "🇺🇸", "us", "usa", "united states", "america", "آمریکا", "new york", "los angeles", "miami", "dallas", "chicago", "seattle", "ashburn", "phoenix"),
        rule("tr", "ترکیه", "🇹🇷", "tr", "tur", "turkey", "türkiye", "ترکیه", "istanbul"),
        rule("ae", "امارات", "🇦🇪", "ae", "uae", "united arab emirates", "امارات", "dubai"),
        rule("se", "سوئد", "🇸🇪", "se", "swe", "sweden", "سوئد", "stockholm"),
        rule("ch", "سوئیس", "🇨🇭", "ch", "che", "switzerland", "سوئیس", "zurich"),
        rule("jp", "ژاپن", "🇯🇵", "jp", "jpn", "japan", "ژاپن", "tokyo"),
        rule("sg", "سنگاپور", "🇸🇬", "sg", "sgp", "singapore", "سنگاپور"),
        rule("ru", "روسیه", "🇷🇺", "ru", "rus", "russia", "روسیه", "moscow"),
        rule("ir", "ایران", "🇮🇷", "ir", "irn", "iran", "ایران", "tehran"),
        rule("at", "اتریش", "🇦🇹", "at", "aut", "austria", "اتریش", "vienna"),
        rule("be", "بلژیک", "🇧🇪", "be", "bel", "belgium", "بلژیک", "brussels"),
        rule("pl", "لهستان", "🇵🇱", "pl", "pol", "poland", "لهستان", "warsaw"),
        rule("es", "اسپانیا", "🇪🇸", "es", "esp", "spain", "اسپانیا", "madrid"),
        rule("it", "ایتالیا", "🇮🇹", "it", "ita", "italy", "ایتالیا", "milan", "rome"),
        rule("no", "نروژ", "🇳🇴", "no", "nor", "norway", "نروژ", "oslo"),
        rule("dk", "دانمارک", "🇩🇰", "dk", "dnk", "denmark", "دانمارک", "copenhagen"),
        rule("cz", "چک", "🇨🇿", "cz", "cze", "czech", "czechia", "چک", "prague"),
        rule("ro", "رومانی", "🇷🇴", "ro", "rou", "romania", "رومانی", "bucharest"),
        rule("bg", "بلغارستان", "🇧🇬", "bg", "bgr", "bulgaria", "بلغارستان", "sofia"),
        rule("ua", "اوکراین", "🇺🇦", "ua", "ukr", "ukraine", "اوکراین", "kyiv"),
        rule("in", "هند", "🇮🇳", "in", "ind", "india", "هند", "mumbai", "delhi"),
        rule("hk", "هنگ‌کنگ", "🇭🇰", "hk", "hkg", "hong kong", "هنگ کنگ", "هنگ‌کنگ"),
        rule("kr", "کره جنوبی", "🇰🇷", "kr", "kor", "south korea", "korea", "کره جنوبی", "seoul"),
        rule("au", "استرالیا", "🇦🇺", "au", "aus", "australia", "استرالیا", "sydney"),
        rule("br", "برزیل", "🇧🇷", "br", "bra", "brazil", "برزیل", "sao paulo"),
        rule("pt", "پرتغال", "🇵🇹", "pt", "prt", "portugal", "پرتغال", "lisbon"),
        rule("gr", "یونان", "🇬🇷", "gr", "grc", "greece", "یونان", "athens"),
        rule("ie", "ایرلند", "🇮🇪", "ie", "irl", "ireland", "ایرلند", "dublin"),
        rule("is", "ایسلند", "🇮🇸", "is", "isl", "iceland", "ایسلند"),
        rule("sa", "عربستان", "🇸🇦", "sa", "sau", "saudi arabia", "عربستان", "riyadh"),
        rule("qa", "قطر", "🇶🇦", "qa", "qat", "qatar", "قطر", "doha"),
        rule("om", "عمان", "🇴🇲", "om", "omn", "oman", "عمان", "muscat"),
    )

    fun locationForCountryCode(countryCode: String?): BlueVpnLocation? {
        val normalized = countryCode.orEmpty().trim().lowercase(Locale.ROOT)
        if (normalized.length != 2) return null
        return rules.firstOrNull { rule ->
            rule.location.key == normalized ||
                rule.codes.any { it.equals(normalized, ignoreCase = true) }
        }?.location
    }

    private val technicalTokens = setOf(
        "vless", "vmess", "trojan", "ss", "shadowsocks", "reality",
        "grpc", "ws", "websocket", "tcp", "udp", "tls", "xray",
        "vpn", "proxy", "config", "configuration", "server", "node",
        "direct", "tunnel", "premium", "vip", "stable", "test",
        "کانفیگ", "سرور", "نود", "اتصال", "ویژه", "پرمیوم",
        "بلو", "bluevpn", "blue", "ray", "xray"
    )

    private fun rule(
        key: String,
        title: String,
        flag: String,
        vararg values: String,
    ): Rule {
        val valueList = values.toList()
        val codes = valueList.filter {
            it.length in 2..3 && it.all { char -> char.isLetter() }
        }.take(2)
        return Rule(
            location = BlueVpnLocation(key, title, flag),
            flags = listOf(flag),
            codes = codes,
            aliases = valueList,
        )
    }

    fun normalizeForSearch(value: String?): String =
        value
            .orEmpty()
            .lowercase(Locale.ROOT)
            .replace('ي', 'ی')
            .replace('ك', 'ک')
            .replace('ۀ', 'ه')
            .replace('\u200c', ' ')
            .replace(
                Regex("[\\u200B-\\u200F\\u202A-\\u202E\\u2066-\\u2069]"),
                ""
            )
            .replace(Regex("[_\\-–—|/\\\\:;,.()\\[\\]{}<>+]+"), " ")
            .replace(Regex("\\s+"), " ")
            .trim()

    private fun tokens(value: String?): Set<String> =
        normalizeForSearch(value)
            .split(" ")
            .map { it.trim() }
            .filter { it.isNotBlank() }
            .toSet()

    private fun containsPhrase(source: String, phrase: String): Boolean {
        val normalizedPhrase = normalizeForSearch(phrase)
        if (normalizedPhrase.isBlank()) return false
        return " $source ".contains(" $normalizedPhrase ")
    }

    private fun countryCodeFromFlag(value: String): String? {
        var index = 0
        while (index < value.length) {
            val first = Character.codePointAt(value, index)
            val firstSize = Character.charCount(first)
            val secondIndex = index + firstSize
            if (secondIndex < value.length) {
                val second = Character.codePointAt(value, secondIndex)
                if (
                    first in 0x1F1E6..0x1F1FF &&
                    second in 0x1F1E6..0x1F1FF
                ) {
                    val firstLetter = ('a'.code + first - 0x1F1E6).toChar()
                    val secondLetter = ('a'.code + second - 0x1F1E6).toChar()
                    return "${firstLetter}${secondLetter}"
                }
            }
            index += firstSize
        }
        return null
    }

    private fun detectKnown(remarks: String): BlueVpnLocation? {
        if (remarks.isBlank()) return null

        countryCodeFromFlag(remarks)
            ?.let(::locationForCountryCode)
            ?.let { return it }

        rules.firstOrNull { rule ->
            rule.flags.any { remarks.contains(it) }
        }?.let { return it.location }

        val normalized = normalizeForSearch(remarks)
        val remarkTokens = tokens(remarks)

        rules.firstOrNull { rule ->
            rule.aliases
                .filter { normalizeForSearch(it).length > 3 }
                .any { containsPhrase(normalized, it) }
        }?.let { return it.location }

        rules.firstOrNull { rule ->
            rule.codes.any { code ->
                remarkTokens.contains(code.lowercase(Locale.ROOT))
            }
        }?.let { return it.location }

        return null
    }

private fun detectFromServer(
    server: String?,
): BlueVpnLocation? {
    val host = server
        .orEmpty()
        .substringBefore(":")
        .trim()
        .lowercase(Locale.ROOT)

    if (host.isBlank()) return null
    if (host.matches(Regex("\\d{1,3}(\\.\\d{1,3}){3}"))) {
        return null
    }

    val hostTokens = host
        .split(Regex("[.\\-_]+"))
        .map { it.trim() }
        .filter { it.isNotBlank() }

    val topLevel = hostTokens.lastOrNull().orEmpty()

    rules.firstOrNull { rule ->
        topLevel == rule.location.key ||
            rule.codes.any {
                topLevel == it.lowercase(Locale.ROOT)
            }
    }?.let { return it.location }

    rules.firstOrNull { rule ->
        hostTokens.any { token ->
            token == rule.location.key ||
                rule.codes.any {
                    token == it.lowercase(Locale.ROOT)
                } ||
                (
                    token.length in 3..5 &&
                        token.take(2) == rule.location.key &&
                        token.drop(2).all { char -> char.isDigit() }
                )
        }
    }?.let { return it.location }

    val normalizedHost = normalizeForSearch(
        host.replace('.', ' ')
    )

    rules.firstOrNull { rule ->
        rule.aliases
            .filter {
                normalizeForSearch(it).length > 3
            }
            .any {
                containsPhrase(normalizedHost, it)
            }
    }?.let { return it.location }

    return null
}

private fun unknownLocation(): BlueVpnLocation =
    BlueVpnLocation(
        key = "unknown",
        title = "در حال شناسایی",
        flag = "🌐",
    )

    fun detect(
        remarks: String?,
        server: String?,
    ): BlueVpnLocation {
        // Never expose provider/default server names in the customer UI.
        // Prefer country/flag evidence in the remark, then inspect safe
        // hostname tokens and country-code TLDs. Unknown routes share a
        // neutral label rather than showing raw server names.
        return detectKnown(remarks.orEmpty())
            ?: detectFromServer(server)
            ?: unknownLocation()
    }

    fun serverIdentity(profile: ProfileItem): String {
        val stableSource = listOf(
            profile.server.orEmpty().trim().lowercase(Locale.ROOT),
            profile.remarks.orEmpty().trim().lowercase(Locale.ROOT),
        ).joinToString("|")
        identityCache[stableSource]?.let { return it }
        val computed = MessageDigest.getInstance("SHA-256")
            .digest(stableSource.toByteArray())
            .joinToString("") { "%02x".format(it) }
            .take(40)
        if (identityCache.size > 2_048) identityCache.clear()
        identityCache[stableSource] = computed
        return computed
    }

    fun reportVerifiedCountry(
        context: Context,
        configKey: String,
        countryCode: String,
    ) {
        val normalized = countryCode.trim().lowercase(Locale.ROOT)
        if (
            !configKey.matches(Regex("[a-f0-9]{40}")) ||
            normalized.length != 2 ||
            !BlueVpnAccountManager.hasSession(context)
        ) return
        cloudExecutor.execute {
            BlueVpnAccountManager.reportServerLocation(
                context.applicationContext,
                configKey,
                normalized,
            )
        }
    }

    fun syncCloudLocations(
        context: Context,
        force: Boolean = false,
        onComplete: (() -> Unit)? = null,
    ) {
        val app = context.applicationContext
        if (!BlueVpnAccountManager.hasSession(app)) {
            onComplete?.let { mainHandler.post(it) }
            return
        }
        val now = System.currentTimeMillis()
        if (!force && now - lastCloudSyncAt < CLOUD_SYNC_TTL_MS) {
            onComplete?.let { mainHandler.post(it) }
            return
        }
        if (!cloudSyncing.compareAndSet(false, true)) {
            onComplete?.let { mainHandler.postDelayed(it, 350L) }
            return
        }

        // Candidate decoding and SHA-256 identity generation can touch hundreds
        // of MMKV entries. Keep the entire preparation off the UI thread.
        cloudExecutor.execute {
            val keys = allCandidates(forceRefresh = false)
                .map { serverIdentity(it.profile) }
                .filter { it.matches(Regex("[a-f0-9]{40}")) }
                .distinct()
            if (keys.isEmpty()) {
                cloudSyncing.set(false)
                lastCloudSyncAt = System.currentTimeMillis()
                onComplete?.let { mainHandler.post(it) }
                return@execute
            }

            var changed = false
            runCatching {
                val response = BlueVpnAccountManager.resolveServerLocations(
                    app,
                    keys,
                ).getOrThrow()
                val rows = response.optJSONArray("locations") ?: JSONArray()
                for (index in 0 until rows.length()) {
                    val row = rows.optJSONObject(index) ?: continue
                    val key = row.optString("config_key").trim().lowercase(Locale.ROOT)
                    val code = row.optString("country_code").trim().lowercase(Locale.ROOT)
                    if (key.matches(Regex("[a-f0-9]{40}")) && code.length == 2) {
                        BlueVpnPreferences.markVerifiedCountryKey(app, key, code)
                        changed = true
                    }
                }
            }
            lastCloudSyncAt = System.currentTimeMillis()
            cloudSyncing.set(false)
            if (changed) invalidateResolvedCache()
            onComplete?.let { mainHandler.post(it) }
        }
    }

    /**
     * Keep profile acceptance aligned with upstream v2rayNG.
     *
     * BlueVPN must not permanently drop a profile because of an option that
     * upstream may migrate, ignore, or normalize during config generation.
     * Runtime/core validation below is authoritative and failed routes are only
     * quarantined for the current connect cycle.
     */
    fun isUsable(
        profile: ProfileItem,
        rawConfig: String? = null,
    ): Boolean {
        val server = profile.server
            .orEmpty()
            .trim()
            .lowercase(Locale.ROOT)

        if (server.isBlank()) return false
        if (server == "127.0.0.1") return false
        if (server == "::1") return false
        if (server == "localhost") return false
        if (server.startsWith("127.")) return false

        return true
    }

    fun invalidateCache() {
        synchronized(this) {
            candidateCacheAt = 0L
            candidateCache = emptyList()
            // Keep the last non-empty context cache visible while a refresh is
            // running. The stable entitlement identity prevents a previous Free
            // or Premium pool from leaking into a different account mode.
            contextCandidateCacheDirty = true
        }
    }

    fun invalidateResolvedCache() {
        synchronized(this) {
            contextCandidateCacheDirty = true
        }
    }

    fun allCandidates(
        forceRefresh: Boolean = false,
    ): List<Candidate> {
        val now = SystemClock.elapsedRealtime()
        val cached = candidateCache

        if (
            !forceRefresh &&
            candidateCacheAt > 0L &&
            now - candidateCacheAt < CANDIDATE_CACHE_TTL_MS
        ) {
            return cached
        }

        val allGuids = MmkvManager.decodeAllServerList()
        val selectedGuid = MmkvManager.getSelectServer().orEmpty()
        val orderedGuids = buildList {
            if (selectedGuid.isNotBlank() && selectedGuid in allGuids) add(selectedGuid)
            allGuids.forEach { guid -> if (guid != selectedGuid) add(guid) }
        }
        val seenFingerprints = HashSet<String>(orderedGuids.size)
        val loaded = orderedGuids.mapNotNull { guid ->
            val profile =
                MmkvManager.decodeServerConfig(guid)
                    ?: return@mapNotNull null
            val raw = MmkvManager.decodeServerRaw(guid)

            if (!isUsable(profile, raw)) {
                return@mapNotNull null
            }

            // Subscription providers often publish the same semantic endpoint
            // under different remarks/GUIDs. Keep the currently selected copy
            // first and collapse only the selection catalogue; the physical
            // imported profiles remain untouched and can reappear after refresh.
            val semanticKey = BlueVpnProfileManager.fingerprint(profile, raw)
            if (!seenFingerprints.add(semanticKey)) {
                return@mapNotNull null
            }

            Candidate(
                guid = guid,
                profile = profile,
                location = detect(profile.remarks, profile.server),
                delay = MmkvManager
                    .decodeServerAffiliationInfo(guid)
                    ?.testDelayMillis ?: 0L,
            )
        }

        synchronized(this) {
            candidateCache = loaded
            candidateCacheAt = SystemClock.elapsedRealtime()
        }
        return loaded
    }

    /**
     * Returns only the already prepared context-aware cache. UI rendering must
     * use this method so a screen never decodes hundreds of MMKV profiles on
     * the main thread. Call allCandidates(context) from Dispatchers.Default to
     * warm or refresh the cache.
     */
    fun cachedCandidates(context: Context): List<Candidate> {
        // BlueVpnAccountManager.entitlementPoolFingerprint(context) used to include
        // transient server GUIDs and is intentionally not the visible-cache key.
        val cacheKey = BlueVpnAccountManager.entitlementIdentityFingerprint(context)
        val cached = contextCandidateCache
        val age = SystemClock.elapsedRealtime() - contextCandidateCacheAt
        // Stale-while-revalidate: keep the last non-empty list visible for the
        // same entitlement while v2rayNG clears and repopulates MMKV. A Free →
        // Premium or URL change produces a different identity and is never shown.
        return if (
            contextCandidateCacheAt > 0L &&
            contextCandidateCacheKey == cacheKey &&
            age in 0L..CONTEXT_STALE_GRACE_MS
        ) cached else emptyList()
    }

    fun hasCandidateCache(context: Context): Boolean = cachedCandidates(context).isNotEmpty()

    fun allCandidates(
        context: Context,
        forceRefresh: Boolean = false,
    ): List<Candidate> {
        val now = SystemClock.elapsedRealtime()
        val cacheKey = BlueVpnAccountManager.entitlementIdentityFingerprint(context)
        val previous = if (
            contextCandidateCacheAt > 0L &&
            contextCandidateCacheKey == cacheKey &&
            now - contextCandidateCacheAt < CONTEXT_STALE_GRACE_MS
        ) contextCandidateCache else emptyList()
        if (
            !forceRefresh &&
            !contextCandidateCacheDirty &&
            contextCandidateCacheAt > 0L &&
            contextCandidateCacheKey == cacheKey &&
            now - contextCandidateCacheAt < CANDIDATE_CACHE_TTL_MS
        ) {
            return contextCandidateCache
        }
        val entitlementGuidList = BlueVpnAccountManager.preferredServerGuids(context)
        val entitlementServerGuids = entitlementGuidList.toSet()
        val selectedGuid = MmkvManager.getSelectServer().orEmpty().trim()
        val orderedEntitlementGuids = buildList {
            if (selectedGuid.isNotBlank() && selectedGuid in entitlementServerGuids) add(selectedGuid)
            entitlementGuidList.forEach { guid -> if (guid != selectedGuid) add(guid) }
        }

        // Important: deduplicate *after* entitlement isolation. The global
        // v2rayNG database can contain the same endpoint in both Free and Premium
        // subscriptions. Global semantic dedupe used to keep the Free copy first,
        // then the entitlement filter removed it and accidentally hid the valid
        // Premium twin. Decode only the active/fallback entitlement pool here.
        val seenEntitlementFingerprints = HashSet<String>(orderedEntitlementGuids.size)
        val resolved = orderedEntitlementGuids
            .mapNotNull { guid ->
                val profile = MmkvManager.decodeServerConfig(guid) ?: return@mapNotNull null
                val raw = MmkvManager.decodeServerRaw(guid)
                if (!isUsable(profile, raw)) return@mapNotNull null
                if (!BlueVpnAccountManager.candidateAllowed(
                        context,
                        guid,
                        profile.subscriptionId,
                        entitlementServerGuids,
                    )) return@mapNotNull null
                val semanticKey = BlueVpnProfileManager.fingerprint(profile, raw)
                if (!seenEntitlementFingerprints.add(semanticKey)) return@mapNotNull null
                Candidate(
                    guid = guid,
                    profile = profile,
                    location = detect(profile.remarks, profile.server),
                    delay = MmkvManager.decodeServerAffiliationInfo(guid)?.testDelayMillis ?: 0L,
                )
            }
            .map { candidate ->
                val configKey = serverIdentity(candidate.profile)
                val verified = BlueVpnPreferences.verifiedCountryKey(context, configKey)
                if (
                    verified.isBlank() &&
                    candidate.location.key != "unknown"
                ) {
                    // A visible country name, city alias, flag or country-code
                    // hostname is already strong evidence. Persist it locally
                    // immediately so a known route never remains under
                    // «در حال شناسایی», then share it with the cloud when the
                    // user has an account.
                    BlueVpnPreferences.markVerifiedCountryKey(
                        context,
                        configKey,
                        candidate.location.key,
                    )
                    reportVerifiedCountry(
                        context.applicationContext,
                        configKey,
                        candidate.location.key,
                    )
                }
                val resolvedCode = verified.ifBlank { candidate.location.key }
                val location = locationForCountryCode(resolvedCode) ?: candidate.location
                if (location == candidate.location) candidate else candidate.copy(location = location)
            }
        if (resolved.isEmpty() && previous.isNotEmpty()) {
            // Never let a transient empty import replace a healthy visible pool.
            // A later MMKV broadcast will retry; until then the engine guard still
            // validates every selected GUID against the current entitlement.
            synchronized(this) {
                contextCandidateCacheDirty = true
            }
            return previous
        }
        synchronized(this) {
            contextCandidateCache = resolved
            contextCandidateCacheAt = SystemClock.elapsedRealtime()
            contextCandidateCacheKey = cacheKey
            contextCandidateCacheDirty = false
        }
        return resolved
    }

    fun orderedCandidates(
        context: Context,
        preferredKey: String? = null,
    ): List<Candidate> {
        val candidates = allCandidates(context)
        if (candidates.isEmpty()) return emptyList()

        val wanted = preferredKey
            .orEmpty()
            .ifBlank {
                BlueVpnPreferences.preferredLocation(context)
            }

        val selectionMode = BlueVpnPreferences.selectionMode(context)
        val manualGuid = BlueVpnPreferences.manualServerGuid(context)

        // Selection ownership is strict:
        // AUTO sees the whole entitlement pool, MANUAL_LOCATION never leaves the
        // chosen country, and MANUAL_SERVER resolves to that exact GUID only.
        val scoped = when (selectionMode) {
            BlueVpnSelectionMode.AUTO -> candidates
            BlueVpnSelectionMode.MANUAL_LOCATION ->
                if (wanted.isBlank()) emptyList() else candidates.filter { it.location.key == wanted }
            BlueVpnSelectionMode.MANUAL_SERVER ->
                if (manualGuid.isBlank()) emptyList() else candidates.filter { it.guid == manualGuid }
        }

        if (scoped.isEmpty()) return emptyList()

        // Routes that failed in the current connect cycle are hard-quarantined
        // for the rest of that cycle. Do not silently add them back when every
        // route has failed; a fresh user attempt calls beginHealthSession() and
        // gives them another chance with their recent-failure penalty intact.
        val sessionHealthy = scoped.filterNot { candidate ->
            BlueVpnPreferences.isSessionInactive(
                context,
                candidate.guid
            )
        }
        if (sessionHealthy.isEmpty()) return emptyList()
        val effective = sessionHealthy

        return BlueVpnSmartSelector.rank(context, effective)
            .map { it.candidate }
    }

    /**
     * Builds a first-connect shortlist without decoding the entire server
     * database. On a cold launch only the selected profile and a handful of
     * following profiles are decoded; the complete candidate cache is warmed
     * later on a background dispatcher.
     */
    fun fastCandidates(
        context: Context,
        preferredKey: String? = null,
        maxCandidates: Int = 8,
    ): List<Candidate> {
        val cached = cachedCandidates(context)
        if (cached.isNotEmpty()) {
            return orderedCandidates(context, preferredKey).take(maxCandidates)
        }

        val selected = MmkvManager.getSelectServer().orEmpty()
        val entitlementGuids = BlueVpnAccountManager.preferredServerGuids(context)
        if (entitlementGuids.isEmpty()) return emptyList()

        // Automatic selection is entitlement-isolated. Never append the global
        // MMKV list: it contains free, expired Premium and manually imported
        // profiles together. The selected profile is retained only if it belongs
        // to the exact current pool.
        val entitlementGuidSet = entitlementGuids.toSet()
        val orderedGuids = buildList {
            if (selected.isNotBlank() && selected in entitlementGuidSet) add(selected)
            entitlementGuids.forEach { add(it) }
        }.distinct()

        val wanted = preferredKey.orEmpty().ifBlank {
            BlueVpnPreferences.preferredLocation(context)
        }
        val selectionMode = BlueVpnPreferences.selectionMode(context)
        val manualGuid = BlueVpnPreferences.manualServerGuid(context)

        val entitlementServerGuidSet = entitlementGuidSet

        fun scan(skipSessionInactive: Boolean): List<Candidate> {
            val result = ArrayList<Candidate>(maxCandidates)
            for (guid in orderedGuids) {
                if (result.size >= maxCandidates) break
                if (skipSessionInactive &&
                    BlueVpnPreferences.isSessionInactive(context, guid)) continue
                val profile = MmkvManager.decodeServerConfig(guid) ?: continue
                if (!isUsable(profile, MmkvManager.decodeServerRaw(guid))) continue
                if (!BlueVpnAccountManager.candidateAllowed(
                        context,
                        guid,
                        profile.subscriptionId,
                        entitlementServerGuidSet,
                    )) continue
                val location = detect(profile.remarks, profile.server)
                when (selectionMode) {
                    BlueVpnSelectionMode.AUTO -> Unit
                    BlueVpnSelectionMode.MANUAL_LOCATION ->
                        if (wanted.isBlank() || location.key != wanted) continue
                    BlueVpnSelectionMode.MANUAL_SERVER ->
                        if (manualGuid.isBlank() || guid != manualGuid) continue
                }
                result += Candidate(
                    guid = guid,
                    profile = profile,
                    location = location,
                    delay = MmkvManager.decodeServerAffiliationInfo(guid)?.testDelayMillis ?: 0L,
                )
            }
            return result
        }

        // Current-cycle failures stay excluded until the next explicit connect
        // attempt. Re-introducing them here caused BlueVPN to loop over the same
        // dead configs and eventually trigger long "not responding" stalls.
        val result = scan(skipSessionInactive = true)
        // Legacy fallback `scan(skipSessionInactive = false)` is intentionally
        // disabled: failed routes must not re-enter the same connect cycle.
        if (result.isEmpty()) return emptyList()
        return BlueVpnSmartSelector.rank(context, result)
            .map { it.candidate }
    }

    /**
     * Returns a small, continuously re-ranked foreground shortlist. The full
     * route set remains available as fallback, while first connection attempts
     * stay fast and prefer recently verified routes on the current network.
     */
    fun instantCandidates(
        context: Context,
        preferredKey: String? = null,
        maxCandidates: Int = 18,
    ): List<Candidate> {
        val ordered = orderedCandidates(context, preferredKey)
        if (ordered.size <= maxCandidates) return ordered

        val selectedGuid = MmkvManager.getSelectServer().orEmpty()
        val head = ordered.take(maxCandidates).toMutableList()
        val selected = ordered.firstOrNull { it.guid == selectedGuid }
        if (
            selected != null &&
            !BlueVpnPreferences.failedRecently(context, selected.guid) &&
            !BlueVpnPreferences.isSessionInactive(context, selected.guid) &&
            head.none { it.guid == selected.guid }
        ) {
            head.add(0, selected)
            if (head.size > maxCandidates) head.removeAt(head.lastIndex)
        }
        return head
    }

    fun healthScore(
        context: Context,
        candidate: Candidate,
    ): Int =
        BlueVpnExperience.healthScore(
            context,
            candidate,
        )

    fun locationHealthScore(
        context: Context,
        candidates: List<Candidate>,
    ): Int {
        if (candidates.isEmpty()) return 0

        val usable = candidates.filter {
            !BlueVpnPreferences.isSessionInactive(
                context,
                it.guid,
            )
        }.ifEmpty { candidates }

        return usable
            .map {
                BlueVpnExperience.healthScore(
                    context,
                    it,
                )
            }
            .maxOrNull()
            ?: 0
    }

    data class CandidatePreflight(
        val ok: Boolean,
        val reason: String,
        val latencyMs: Long = 0L,
    )

    /**
     * Fast advisory preflight before Xray owns the TUN.
     *
     * Only deterministic local/config errors are rejected here. DNS and raw TCP
     * checks are deliberately advisory because they are not equivalent to an
     * Xray handshake: Android DNS can disagree with the core DNS strategy,
     * IPv6 may be listed before a working IPv4 address, and several transports
     * can look unreachable to a plain socket while still working in v2rayNG.
     *
     * The authoritative health decision is made after the core starts by a real
     * HTTP request through the local Xray proxy. A route is quarantined for the
     * current connect cycle only when that end-to-end verification fails.
     */
    fun preflightCandidate(
        candidate: Candidate,
        timeoutMs: Int = 450,
    ): CandidatePreflight {
        val profile = candidate.profile
        val host = profile.server.orEmpty().trim()
        if (host.isBlank()) {
            return CandidatePreflight(false, "آدرس سرور خالی است")
        }
        if (!isUsable(profile, MmkvManager.decodeServerRaw(candidate.guid))) {
            return CandidatePreflight(false, "کانفیگ با هسته فعلی سازگار نیست")
        }

        val port = profilePort(profile)
        if (port != null && port !in 1..65535) {
            return CandidatePreflight(false, "پورت کانفیگ نامعتبر است")
        }

        val started = SystemClock.elapsedRealtime()
        val raw = MmkvManager.decodeServerRaw(candidate.guid).orEmpty()
        val serialized = (
            runCatching { profile.toString() }.getOrDefault("") + "\n" + raw
        ).lowercase(Locale.ROOT)
        val udpLike = serialized.contains("hysteria") ||
            serialized.contains("hy2") ||
            serialized.contains("tuic") ||
            serialized.contains("\"network\":\"quic\"") ||
            serialized.contains("network=quic") ||
            serialized.contains("\"network\":\"kcp\"") ||
            serialized.contains("network=kcp")

        val addresses = runCatching { InetAddress.getAllByName(host).toList() }
            .getOrElse {
                // Do not discard a profile solely because Android's resolver
                // failed. Xray can use a different DNS path and this exact
                // profile may still work in upstream v2rayNG.
                return CandidatePreflight(
                    true,
                    "DNS پیش‌تست نامشخص بود؛ بررسی با هسته ادامه دارد",
                    (SystemClock.elapsedRealtime() - started).coerceAtLeast(1L),
                )
            }
        if (addresses.isEmpty()) {
            return CandidatePreflight(
                true,
                "DNS پیش‌تست پاسخی نداشت؛ بررسی با هسته ادامه دارد",
                (SystemClock.elapsedRealtime() - started).coerceAtLeast(1L),
            )
        }

        if (udpLike || port == null) {
            return CandidatePreflight(
                true,
                "endpoint برای تست واقعی هسته آماده است",
                (SystemClock.elapsedRealtime() - started).coerceAtLeast(1L),
            )
        }

        // Prefer IPv4 first on mobile networks, but still try IPv6. Test more
        // than two addresses because CDN domains commonly return several AAAA/A
        // records and the first pair can be unreachable on the current carrier.
        val orderedAddresses = addresses
            .distinctBy { it.hostAddress.orEmpty() }
            .sortedBy { if (it.address.size == 4) 0 else 1 }
            .take(3)
        val perAddressTimeout = timeoutMs.coerceIn(220, 260)
        val connected = orderedAddresses.any { address ->
            runCatching {
                Socket().use { socket ->
                    socket.tcpNoDelay = true
                    socket.connect(
                        InetSocketAddress(address, port),
                        perAddressTimeout,
                    )
                }
                true
            }.getOrDefault(false)
        }
        return CandidatePreflight(
            true,
            if (connected) {
                "endpoint در پیش‌تست پاسخ داد"
            } else {
                "TCP پیش‌تست قطعی نبود؛ تست واقعی Xray انجام می‌شود"
            },
            (SystemClock.elapsedRealtime() - started).coerceAtLeast(1L),
        )
    }

    private fun profilePort(profile: ProfileItem): Int? {
        val getter = profile.javaClass.methods.firstOrNull { method ->
            method.parameterTypes.isEmpty() &&
                (
                    method.name.equals("getServerPort", ignoreCase = true) ||
                        method.name.equals("getPort", ignoreCase = true)
                    )
        } ?: return null
        val value = runCatching { getter.invoke(profile) }.getOrNull() ?: return null
        return when (value) {
            is Number -> value.toInt()
            is String -> value.trim().toIntOrNull()
            else -> value.toString().trim().toIntOrNull()
        }
    }

    fun activeCandidateCount(
        context: Context,
    ): Int =
        allCandidates(context).count {
            it.delay >= 0L &&
                !BlueVpnPreferences.isSessionInactive(
                    context,
                    it.guid,
                )
        }

    fun selectBest(
        context: Context,
        preferredKey: String? = null,
    ): String? = BlueVpnSmartSelector
        .decide(context, orderedCandidates(context, preferredKey))
        ?.candidate
        ?.guid

    data class Candidate(
        val guid: String,
        val profile: ProfileItem,
        val location: BlueVpnLocation,
        val delay: Long,
    )
}
