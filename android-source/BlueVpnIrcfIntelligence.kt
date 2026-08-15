package com.v2ray.ang.bluevpn

import android.content.Context
import com.v2ray.ang.dto.entities.ProfileItem
import com.v2ray.ang.handler.MmkvManager
import org.json.JSONArray
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.util.Locale
import java.util.concurrent.atomic.AtomicBoolean

/**
 * Optional IRCF-inspired intelligence layer.
 *
 * The official v2rayNG parser remains the source of truth. This layer never
 * rewrites credentials or replaces imported profiles. It adds four bounded,
 * fail-open signals around already-parsed profiles:
 *  - XrayRefiner-style inventory normalization/auditing after import;
 *  - adaptive test URL references for real tunnel verification;
 *  - Cloudflare range awareness for diagnostics/scoring metadata;
 *  - Fragment/EarlyData/Mux-aware scoring based on measured route history;
 *  - Warp/MASQUE endpoint references for diagnostics/future engines only.
 */
object BlueVpnIrcfIntelligence {
    private const val PREFS = "bluevpn_ircf_intelligence"
    private const val ADS_PREFS = "bluevpn_ads_cache"
    private const val KEY_CONFIG = "mobile_config"
    private const val KEY_REF_AT = "reference_updated_at"
    private const val KEY_TEST_URLS = "test_urls"
    private const val KEY_CF_RANGES = "cf_ranges"
    private const val KEY_ENDPOINTS = "warp_endpoints"
    private const val REFRESH_MS = 24L * 60L * 60L * 1000L
    private const val CONNECT_TIMEOUT_MS = 2_500
    private const val READ_TIMEOUT_MS = 3_000
    private val refreshing = AtomicBoolean(false)

    private const val TEST_URL_SOURCE = "https://raw.githubusercontent.com/ircfspace/testUrl/main/url.json"
    private const val CF_RANGE_SOURCE = "https://raw.githubusercontent.com/ircfspace/cf-ip-ranges/main/export.ipv4"
    private const val ENDPOINT_SOURCE = "https://raw.githubusercontent.com/ircfspace/endpoint/main/v2.json"

    data class RouteTraits(
        val valid: Boolean,
        val transport: String,
        val fragmentAware: Boolean,
        val earlyDataAware: Boolean,
        val muxAware: Boolean,
        val cloudflareIp: Boolean,
        val warpEndpointKnown: Boolean,
        val rewriteEligible: Boolean,
    )

    data class InventoryAudit(
        val total: Int,
        val decoded: Int,
        val invalid: Int,
        val duplicates: Int,
        val ipv4: Int,
        val ipv6: Int,
        val fragmentAware: Int,
        val cloudflare: Int,
    )

    private fun prefs(context: Context) = context.applicationContext
        .getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    private fun feature(context: Context, key: String, default: Boolean = true): Boolean {
        val raw = context.applicationContext
            .getSharedPreferences(ADS_PREFS, Context.MODE_PRIVATE)
            .getString(KEY_CONFIG, "").orEmpty()
        if (raw.isBlank()) return default
        return runCatching {
            val root = JSONObject(raw)
            val ircf = root.optJSONObject("ircf_intelligence") ?: return@runCatching default
            if (!ircf.has(key)) default else ircf.optBoolean(key, default)
        }.getOrDefault(default)
    }

    fun enabled(context: Context): Boolean = feature(context, "enabled", true)

    fun refreshReferenceDataAsync(context: Context, force: Boolean = false) {
        if (!enabled(context)) return
        val p = prefs(context)
        if (!force && System.currentTimeMillis() - p.getLong(KEY_REF_AT, 0L) < REFRESH_MS) return
        if (!refreshing.compareAndSet(false, true)) return
        Thread {
            try {
                val editor = p.edit()
                if (feature(context, "adaptive_test_urls", true)) {
                    fetch(TEST_URL_SOURCE)?.let { body ->
                        val urls = collectHttpStrings(body).distinct().take(24)
                        if (urls.isNotEmpty()) editor.putString(KEY_TEST_URLS, JSONArray(urls).toString())
                    }
                }
                if (feature(context, "cloudflare_intelligence", true)) {
                    fetch(CF_RANGE_SOURCE)?.let { body ->
                        val ranges = body.lineSequence().map { it.trim() }
                            .filter { it.matches(Regex("^\\d{1,3}(?:\\.\\d{1,3}){3}/\\d{1,2}$")) }
                            .distinct().take(256).toList()
                        if (ranges.isNotEmpty()) editor.putString(KEY_CF_RANGES, JSONArray(ranges).toString())
                    }
                }
                if (feature(context, "warp_endpoint_profiles", true)) {
                    fetch(ENDPOINT_SOURCE)?.let { body ->
                        val endpoints = collectEndpointStrings(body).distinct().take(256)
                        if (endpoints.isNotEmpty()) editor.putString(KEY_ENDPOINTS, JSONArray(endpoints).toString())
                    }
                }
                editor.putLong(KEY_REF_AT, System.currentTimeMillis()).apply()
            } finally {
                refreshing.set(false)
            }
        }.apply { name = "BlueVPN-IRCF-Reference"; isDaemon = true }.start()
    }

    fun adaptiveProbeUrls(context: Context): List<String> {
        if (!enabled(context) || !feature(context, "adaptive_test_urls", true)) return emptyList()
        refreshReferenceDataAsync(context)
        val raw = prefs(context).getString(KEY_TEST_URLS, "").orEmpty()
        return runCatching {
            val array = JSONArray(raw)
            (0 until array.length()).mapNotNull { array.optString(it).trim().takeIf { u -> u.startsWith("http://") || u.startsWith("https://") } }
                .distinct().take(4)
        }.getOrDefault(emptyList())
    }

    fun auditSubscription(context: Context, subscriptionGuid: String): InventoryAudit {
        if (!enabled(context) || !feature(context, "subscription_refiner", true)) return InventoryAudit(0,0,0,0,0,0,0,0)
        refreshReferenceDataAsync(context)
        val guids = runCatching { MmkvManager.decodeServerList(subscriptionGuid).toList() }.getOrDefault(emptyList())
        val fingerprints = HashSet<String>()
        var decoded = 0; var invalid = 0; var duplicates = 0; var ipv4 = 0; var ipv6 = 0; var fragment = 0; var cf = 0
        guids.filter { it.isNotBlank() }.forEach { guid ->
            val profile = MmkvManager.decodeServerConfig(guid)
            if (profile == null) { invalid++; return@forEach }
            decoded++
            val fp = BlueVpnProfileManager.fingerprint(profile, MmkvManager.decodeServerRaw(guid))
            if (!fingerprints.add(fp)) duplicates++
            val server = read(profile, "getServer")
            if (isIpv4(server)) ipv4++ else if (server.contains(':')) ipv6++
            val traits = traits(context, guid)
            if (!traits.valid) invalid++
            if (traits.fragmentAware || traits.earlyDataAware || traits.muxAware) fragment++
            if (traits.cloudflareIp) cf++
        }
        val audit = InventoryAudit(guids.size, decoded, invalid, duplicates, ipv4, ipv6, fragment, cf)
        prefs(context).edit().putString("audit:$subscriptionGuid", JSONObject()
            .put("total", audit.total).put("decoded", audit.decoded).put("invalid", audit.invalid)
            .put("duplicates", audit.duplicates).put("ipv4", audit.ipv4).put("ipv6", audit.ipv6)
            .put("fragment", audit.fragmentAware).put("cloudflare", audit.cloudflare).toString()).apply()
        return audit
    }

    fun rankingAdjustment(context: Context, guid: String): Int {
        if (!enabled(context)) return 0
        val t = traits(context, guid)
        if (!t.valid) return -18
        val history = BlueVpnRouteIntelligence.snapshot(context, guid)
        var score = 0
        if (feature(context, "fragment_scoring", true) && (t.fragmentAware || t.earlyDataAware || t.muxAware)) {
            // Features are not assumed to be universally better. Reward them only
            // after the current physical network has produced positive evidence.
            score += when {
                history.samples >= 3 && history.successRate >= 85 && history.jitterEwmaMs in 0..80 -> 5
                history.samples >= 3 && history.successRate < 45 -> -6
                else -> 0
            }
        }
        if (t.cloudflareIp && history.samples >= 2 && history.successRate >= 75) score += 2
        if (t.warpEndpointKnown) score += 1
        return score.coerceIn(-18, 8)
    }

    fun traits(context: Context, guid: String): RouteTraits {
        val profile = MmkvManager.decodeServerConfig(guid)
            ?: return RouteTraits(false,"",false,false,false,false,false,false)
        val raw = MmkvManager.decodeServerRaw(guid).orEmpty().lowercase(Locale.ROOT)
        val server = read(profile, "getServer").trim()
        val protocol = BlueVpnProfileManager.describe(profile, raw).protocol
        val transport = read(profile, "getNetwork").lowercase(Locale.ROOT)
        val path = read(profile, "getPath").lowercase(Locale.ROOT)
        val extra = read(profile, "getXhttpExtra").lowercase(Locale.ROOT)
        val chain = read(profile, "getProxyChainProfiles").lowercase(Locale.ROOT)
        val mode = read(profile, "getMode").lowercase(Locale.ROOT)
        val fragment = "fragment" in raw || "fragment" in extra || "fragment" in mode
        val early = "ed=" in raw || "earlydata" in raw || "early_data" in raw || "maxearlydata" in raw || "ed=" in path
        val mux = "mux" in raw || "mux" in chain || "mux" in mode
        val cf = feature(context, "cloudflare_intelligence", true) && isCloudflareIp(context, server)
        val endpointKnown = feature(context, "warp_endpoint_profiles", true) && isKnownWarpEndpoint(context, server)
        val rewriteEligible = cf && protocol in setOf(
            BlueVpnProfileManager.Protocol.VLESS,
            BlueVpnProfileManager.Protocol.VMESS,
            BlueVpnProfileManager.Protocol.TROJAN,
        )
        val valid = protocol != BlueVpnProfileManager.Protocol.UNKNOWN &&
            (server.isNotBlank() || protocol == BlueVpnProfileManager.Protocol.CUSTOM_JSON)
        return RouteTraits(valid, transport, fragment, early, mux, cf, endpointKnown, rewriteEligible)
    }

    private fun fetch(url: String): String? = runCatching {
        val c = URL(url).openConnection() as HttpURLConnection
        try {
            c.connectTimeout = CONNECT_TIMEOUT_MS; c.readTimeout = READ_TIMEOUT_MS
            c.instanceFollowRedirects = true; c.useCaches = true
            c.setRequestProperty("User-Agent", "BlueVPN-Intelligence/1")
            if (c.responseCode !in 200..299) return@runCatching null
            c.inputStream.bufferedReader().use { it.readText().take(512_000) }
        } finally { c.disconnect() }
    }.getOrNull()

    private fun collectHttpStrings(raw: String): List<String> = runCatching {
        val out = mutableListOf<String>()
        fun visit(v: Any?) {
            when (v) {
                is JSONObject -> { val it=v.keys(); while(it.hasNext()) visit(v.opt(it.next())) }
                is JSONArray -> for(i in 0 until v.length()) visit(v.opt(i))
                is String -> if (v.startsWith("http://") || v.startsWith("https://")) out += v.trim()
            }
        }
        val t=raw.trim(); if(t.startsWith("[")) visit(JSONArray(t)) else visit(JSONObject(t)); out
    }.getOrDefault(emptyList())

    private fun collectEndpointStrings(raw: String): List<String> = runCatching {
        val out = mutableListOf<String>()
        fun visit(v: Any?) {
            when (v) {
                is JSONObject -> { val it=v.keys(); while(it.hasNext()) visit(v.opt(it.next())) }
                is JSONArray -> for(i in 0 until v.length()) visit(v.opt(i))
                is String -> if (Regex("(?:\\d{1,3}\\.){3}\\d{1,3}(?::\\d+)?").containsMatchIn(v)) out += v.trim()
            }
        }
        val t=raw.trim(); if(t.startsWith("[")) visit(JSONArray(t)) else visit(JSONObject(t)); out
    }.getOrDefault(emptyList())

    private fun isKnownWarpEndpoint(context: Context, server: String): Boolean {
        if (server.isBlank()) return false
        val raw = prefs(context).getString(KEY_ENDPOINTS, "").orEmpty()
        return runCatching {
            val a=JSONArray(raw); (0 until a.length()).any { a.optString(it).substringBefore(':') == server }
        }.getOrDefault(false)
    }

    private fun isCloudflareIp(context: Context, server: String): Boolean {
        val ip = ipv4ToLong(server) ?: return false
        val raw = prefs(context).getString(KEY_CF_RANGES, "").orEmpty()
        return runCatching {
            val a=JSONArray(raw)
            (0 until a.length()).any { cidrContains(a.optString(it), ip) }
        }.getOrDefault(false)
    }

    private fun cidrContains(cidr: String, ip: Long): Boolean {
        val parts=cidr.split('/'); if(parts.size!=2) return false
        val base=ipv4ToLong(parts[0]) ?: return false
        val prefix=parts[1].toIntOrNull()?.coerceIn(0,32) ?: return false
        val mask=if(prefix==0) 0L else (0xffffffffL shl (32-prefix)) and 0xffffffffL
        return (base and mask) == (ip and mask)
    }

    private fun ipv4ToLong(value: String): Long? {
        val p=value.trim().split('.'); if(p.size!=4) return null
        var out=0L
        for(s in p){ val n=s.toIntOrNull() ?: return null; if(n !in 0..255) return null; out=(out shl 8) or n.toLong() }
        return out and 0xffffffffL
    }
    private fun isIpv4(value: String): Boolean = ipv4ToLong(value) != null

    private fun read(profile: ProfileItem, getter: String): String {
        val method = profile.javaClass.methods.firstOrNull { it.name == getter && it.parameterTypes.isEmpty() } ?: return ""
        return runCatching { method.invoke(profile)?.toString().orEmpty() }.getOrDefault("")
    }
}
