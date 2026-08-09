package com.v2ray.ang.bluevpn

import android.content.Context
import android.os.Handler
import android.os.Looper
import android.os.SystemClock
import com.v2ray.ang.dto.entities.ProfileItem
import com.v2ray.ang.handler.MmkvManager
import org.json.JSONArray
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

object BlueVpnPreferences {
    private const val PREFS = "bluevpn_customer_preferences"
    private const val KEY_SMART_BALANCE = "smart_balance"
    private const val KEY_PREFERRED_LOCATION = "preferred_location"
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

    fun smartBalance(context: Context): Boolean =
        prefs(context).getBoolean(KEY_SMART_BALANCE, true)

    fun setSmartBalance(context: Context, enabled: Boolean) {
        prefs(context).edit()
            .putBoolean(KEY_SMART_BALANCE, enabled)
            .apply()
    }

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

        storage.all.keys
            .filter {
                it.startsWith(SESSION_INACTIVE_PREFIX) ||
                    it.startsWith(FAILED_PREFIX)
            }
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

    private val identityCache = ConcurrentHashMap<String, String>()
    private val compatibilityCache = ConcurrentHashMap<String, String>()

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
     * New Xray cores reject legacy TLS profiles that still request
     * allowInsecure/insecure=1. Detect them before starting the core so one
     * stale route cannot show a raw engine error or hold the UI for seconds.
     * The provider must replace these profiles with a valid certificate,
     * pinnedPeerCertSha256 or verifyPeerCertByName configuration.
     */
    private fun containsRemovedTlsOption(value: String?): Boolean {
        val compact = value.orEmpty()
            .lowercase(Locale.ROOT)
            .replace(Regex("\\s+"), "")
        return compact.contains("\"allowinsecure\":true") ||
            compact.contains("allowinsecure=true") ||
            compact.contains("allowinsecure%3dtrue") ||
            compact.contains("insecure=1") ||
            compact.contains("insecure%3d1")
    }

    fun compatibilityIssue(
        profile: ProfileItem,
        rawConfig: String? = null,
    ): String? {
        val key = serverIdentity(profile).ifBlank {
            (profile.server.orEmpty() + "|" + profile.remarks.orEmpty())
                .lowercase(Locale.ROOT)
        }
        compatibilityCache[key]?.let { cached ->
            return cached.takeIf { it.isNotBlank() }
        }
        val explicitInsecure = runCatching {
            profile.javaClass.methods
                .firstOrNull { method ->
                    method.parameterTypes.isEmpty() &&
                        (method.name.equals("getInsecure", ignoreCase = true) ||
                            method.name.equals("isInsecure", ignoreCase = true))
                }
                ?.invoke(profile) == true
        }.getOrDefault(false)
        val serialized = runCatching { profile.toString() }.getOrDefault("")
        val legacyTls = explicitInsecure ||
            containsRemovedTlsOption(rawConfig) ||
            containsRemovedTlsOption(serialized)
        val issue = if (legacyTls) "LEGACY_TLS_ALLOW_INSECURE" else ""
        compatibilityCache[key] = issue
        return issue.takeIf { it.isNotBlank() }
    }

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
        if (compatibilityIssue(profile, rawConfig) != null) return false

        return true
    }

    fun invalidateCache() {
        synchronized(this) {
            candidateCacheAt = 0L
            candidateCache = emptyList()
            contextCandidateCacheAt = 0L
            contextCandidateCache = emptyList()
            contextCandidateCacheKey = ""
            compatibilityCache.clear()
        }
    }

    fun invalidateResolvedCache() {
        synchronized(this) {
            contextCandidateCacheAt = 0L
            contextCandidateCache = emptyList()
            contextCandidateCacheKey = ""
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

        val loaded = MmkvManager.decodeAllServerList()
            .mapNotNull { guid ->
                val profile =
                    MmkvManager.decodeServerConfig(guid)
                        ?: return@mapNotNull null

                if (!isUsable(profile, MmkvManager.decodeServerRaw(guid))) {
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
        val cacheKey = BlueVpnAccountManager.entitlementPoolFingerprint(context)
        val cached = contextCandidateCache
        // Stale-while-revalidate: keep rendering the last pool while a background
        // refresh runs, but only when the entitlement fingerprint is unchanged.
        // A free→Premium transition immediately invalidates the old free pool.
        return if (
            contextCandidateCacheAt > 0L &&
            contextCandidateCacheKey == cacheKey
        ) cached else emptyList()
    }

    fun hasCandidateCache(context: Context): Boolean = cachedCandidates(context).isNotEmpty()

    fun allCandidates(
        context: Context,
        forceRefresh: Boolean = false,
    ): List<Candidate> {
        val now = SystemClock.elapsedRealtime()
        val cacheKey = BlueVpnAccountManager.entitlementPoolFingerprint(context)
        if (
            !forceRefresh &&
            contextCandidateCacheAt > 0L &&
            contextCandidateCacheKey == cacheKey &&
            now - contextCandidateCacheAt < CANDIDATE_CACHE_TTL_MS
        ) {
            return contextCandidateCache
        }
        val entitlementServerGuids = BlueVpnAccountManager
            .preferredServerGuids(context)
            .toSet()
        val resolved = allCandidates(forceRefresh)
            .filter { candidate ->
                BlueVpnAccountManager.candidateAllowed(
                    context,
                    candidate.guid,
                    candidate.profile.subscriptionId,
                    entitlementServerGuids,
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
        synchronized(this) {
            contextCandidateCache = resolved
            contextCandidateCacheAt = SystemClock.elapsedRealtime()
            contextCandidateCacheKey = cacheKey
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

        val automatic = BlueVpnPreferences.smartBalance(context)

        // Automatic mode evaluates every usable server from every location.
        // Manual mode stays restricted to the location selected by the user.
        val scoped = if (automatic || wanted.isBlank()) {
            candidates
        } else {
            candidates.filter { it.location.key == wanted }
        }

        if (scoped.isEmpty()) return emptyList()

        // Routes that failed with the current user's network are removed
        // from this foreground session. At the next app entry
        // beginHealthSession() clears the exclusion and tests them again.
        val sessionHealthy = scoped.filterNot { candidate ->
            BlueVpnPreferences.isSessionInactive(
                context,
                candidate.guid
            )
        }
        val effective = sessionHealthy.ifEmpty { scoped }

        data class RankedCandidate(
            val candidate: Candidate,
            val state: Int,
            val priority: Int,
        )

        val ranked = effective.map { candidate ->
            val inactive = BlueVpnPreferences.isSessionInactive(
                context,
                candidate.guid,
            )
            val failed = if (inactive) {
                false
            } else {
                BlueVpnPreferences.failedRecently(
                    context,
                    candidate.guid,
                )
            }

            RankedCandidate(
                candidate = candidate,
                state = when {
                    inactive -> 4
                    failed -> 3
                    candidate.delay > 0L -> 0
                    candidate.delay == 0L -> 1
                    else -> 2
                },
                priority = BlueVpnExperience.candidatePriority(
                    context,
                    candidate,
                ) + BlueVpnPreferences.successFreshnessScore(
                    context,
                    candidate.guid,
                ) * 100,
            )
        }

        return ranked.sortedWith(
            compareBy<RankedCandidate> { it.state }
                .thenByDescending { it.priority }
                .thenBy {
                    if (it.candidate.delay > 0L) {
                        it.candidate.delay
                    } else {
                        Long.MAX_VALUE
                    }
                }
                .thenBy { it.candidate.location.title }
        ).map { it.candidate }
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
        val automatic = BlueVpnPreferences.smartBalance(context)

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
                if (!automatic && wanted.isNotBlank() && location.key != wanted) continue
                result += Candidate(
                    guid = guid,
                    profile = profile,
                    location = location,
                    delay = MmkvManager.decodeServerAffiliationInfo(guid)?.testDelayMillis ?: 0L,
                )
            }
            return result
        }

        // Prefer healthy routes, but never return an empty list solely because
        // every free route was marked inactive earlier in the same session.
        val result = scan(skipSessionInactive = true).ifEmpty {
            scan(skipSessionInactive = false)
        }
        return result.sortedWith(
            compareByDescending<Candidate> {
                BlueVpnPreferences.successFreshnessScore(context, it.guid)
            }.thenBy {
                if (it.delay > 0L) it.delay else Long.MAX_VALUE
            }
        )
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
    ): String? =
        orderedCandidates(context, preferredKey)
            .firstOrNull()
            ?.guid

    data class Candidate(
        val guid: String,
        val profile: ProfileItem,
        val location: BlueVpnLocation,
        val delay: Long,
    )
}
