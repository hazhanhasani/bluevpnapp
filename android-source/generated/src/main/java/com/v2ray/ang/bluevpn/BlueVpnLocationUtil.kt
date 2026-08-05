package com.v2ray.ang.bluevpn

import android.content.Context
import android.os.SystemClock
import com.v2ray.ang.dto.entities.ProfileItem
import com.v2ray.ang.handler.MmkvManager
import java.util.Locale

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

    private const val CANDIDATE_CACHE_TTL_MS = 1_200L

    @Volatile
    private var candidateCacheAt = 0L

    @Volatile
    private var candidateCache: List<Candidate> = emptyList()

    private data class Rule(
        val location: BlueVpnLocation,
        val flags: List<String>,
        val codes: List<String>,
        val aliases: List<String>,
    )

    private val rules = listOf(
        rule("ca", "کانادا", "🇨🇦", "ca", "can", "canada", "کانادا", "toronto", "montreal", "vancouver"),
        rule("de", "آلمان", "🇩🇪", "de", "ger", "germany", "deutschland", "آلمان", "frankfurt", "berlin"),
        rule("nl", "هلند", "🇳🇱", "nl", "nld", "netherlands", "holland", "هلند", "amsterdam"),
        rule("fi", "فنلاند", "🇫🇮", "fi", "fin", "finland", "فنلاند", "helsinki"),
        rule("fr", "فرانسه", "🇫🇷", "fr", "fra", "france", "فرانسه", "paris"),
        rule("gb", "انگلیس", "🇬🇧", "gb", "uk", "united kingdom", "england", "انگلیس", "britain", "london"),
        rule("us", "آمریکا", "🇺🇸", "us", "usa", "united states", "america", "آمریکا", "new york", "los angeles", "miami"),
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

    private fun detectKnown(remarks: String): BlueVpnLocation? {
        if (remarks.isBlank()) return null

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
        title = "لوکیشن ناشناخته",
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

    fun isUsable(profile: ProfileItem): Boolean {
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

                if (!isUsable(profile)) {
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

    fun orderedCandidates(
        context: Context,
        preferredKey: String? = null,
    ): List<Candidate> {
        val candidates = allCandidates()
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
                ),
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
        allCandidates().count {
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
