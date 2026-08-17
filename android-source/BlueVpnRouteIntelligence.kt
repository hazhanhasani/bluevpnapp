package com.v2ray.ang.bluevpn

import android.content.Context
import com.v2ray.ang.handler.MmkvManager
import org.json.JSONObject
import java.net.InetAddress
import java.security.MessageDigest
import java.util.Locale
import kotlin.math.abs

/**
 * Local route intelligence inspired by mature desktop proxy clients.
 *
 * The important design rule is that a test result belongs to the semantic
 * endpoint + current physical network, not to a volatile v2rayNG MMKV GUID.
 * That lets history survive subscription refreshes while preventing Wi-Fi
 * results from poisoning mobile-network selection (and vice versa).
 *
 * This layer never permanently deletes a profile. A route can be hard-excluded
 * for the current connect cycle by BlueVpnPreferences and is only *deprioritized*
 * here on later cycles via bounded circuit-breaker history.
 */
object BlueVpnRouteIntelligence {
    private const val PREFS = "bluevpn_route_intelligence"
    private const val ROUTE_PREFIX = "route:"
    private const val STICKY_PREFIX = "sticky:"
    private const val MAX_LAST_REASON = 160
    private const val STICKY_MAX_AGE_MS = 6 * 60 * 60 * 1000L
    private const val HISTORY_STALE_MS = 7 * 24 * 60 * 60 * 1000L

    data class RouteSnapshot(
        val successCount: Int = 0,
        val failureCount: Int = 0,
        val consecutiveFailures: Int = 0,
        val latencyEwmaMs: Long = 0L,
        val jitterEwmaMs: Long = 0L,
        val lastLatencyMs: Long = 0L,
        val lastSuccessAt: Long = 0L,
        val lastFailureAt: Long = 0L,
        val cooldownUntil: Long = 0L,
        val lastFailureReason: String = "",
        val exitIp: String = "",
        val exitCountry: String = "",
        val exitColo: String = "",
        val exitChangedAt: Long = 0L,
        val throughputEwmaBps: Long = 0L,
        val throughputSampleAt: Long = 0L,
    ) {
        val samples: Int get() = successCount + failureCount
        val successRate: Int
            get() = if (samples <= 0) 0 else (successCount * 100 / samples).coerceIn(0, 100)
    }

    private fun prefs(context: Context) = context.applicationContext
        .getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    private fun digest(value: String): String = MessageDigest.getInstance("SHA-256")
        .digest(value.toByteArray(Charsets.UTF_8))
        .joinToString("") { "%02x".format(it) }

    private fun networkKey(context: Context): String {
        val network = BlueVpnAi.network(context.applicationContext)
        val raw = listOf(
            network.networkType.trim().lowercase(Locale.ROOT),
            network.operator.trim().lowercase(Locale.ROOT),
        ).joinToString("|")
        return digest(raw).take(16)
    }

    private fun semanticFingerprint(guid: String): String {
        if (guid.isBlank()) return ""
        return BlueVpnProfileManager.fingerprintGuid(guid)
            ?: digest("guid:$guid").take(40)
    }

    private fun key(context: Context, guid: String): String {
        val route = semanticFingerprint(guid)
        if (route.isBlank()) return ""
        return "$ROUTE_PREFIX${networkKey(context)}:$route"
    }

    fun snapshot(context: Context, guid: String): RouteSnapshot {
        val key = key(context, guid)
        if (key.isBlank()) return RouteSnapshot()
        val raw = prefs(context).getString(key, "").orEmpty()
        if (raw.isBlank()) return RouteSnapshot()
        return runCatching {
            val json = JSONObject(raw)
            RouteSnapshot(
                successCount = json.optInt("s", 0).coerceAtLeast(0),
                failureCount = json.optInt("f", 0).coerceAtLeast(0),
                consecutiveFailures = json.optInt("cf", 0).coerceAtLeast(0),
                latencyEwmaMs = json.optLong("lat", 0L).coerceAtLeast(0L),
                jitterEwmaMs = json.optLong("jit", 0L).coerceAtLeast(0L),
                lastLatencyMs = json.optLong("last", 0L).coerceAtLeast(0L),
                lastSuccessAt = json.optLong("sa", 0L).coerceAtLeast(0L),
                lastFailureAt = json.optLong("fa", 0L).coerceAtLeast(0L),
                cooldownUntil = json.optLong("cool", 0L).coerceAtLeast(0L),
                lastFailureReason = json.optString("reason").take(MAX_LAST_REASON),
                exitIp = json.optString("ip").take(80),
                exitCountry = json.optString("cc").take(8),
                exitColo = json.optString("colo").take(12),
                exitChangedAt = json.optLong("ica", 0L).coerceAtLeast(0L),
                throughputEwmaBps = json.optLong("tp", 0L).coerceAtLeast(0L),
                throughputSampleAt = json.optLong("tpa", 0L).coerceAtLeast(0L),
            )
        }.getOrDefault(RouteSnapshot())
    }

    private fun save(context: Context, guid: String, value: RouteSnapshot) {
        val key = key(context, guid)
        if (key.isBlank()) return
        val json = JSONObject()
            .put("s", value.successCount)
            .put("f", value.failureCount)
            .put("cf", value.consecutiveFailures)
            .put("lat", value.latencyEwmaMs)
            .put("jit", value.jitterEwmaMs)
            .put("last", value.lastLatencyMs)
            .put("sa", value.lastSuccessAt)
            .put("fa", value.lastFailureAt)
            .put("cool", value.cooldownUntil)
            .put("reason", value.lastFailureReason)
            .put("ip", value.exitIp)
            .put("cc", value.exitCountry)
            .put("colo", value.exitColo)
            .put("ica", value.exitChangedAt)
            .put("tp", value.throughputEwmaBps)
            .put("tpa", value.throughputSampleAt)
        prefs(context).edit().putString(key, json.toString()).apply()
    }

    /**
     * Keep a bounded recent sample window. Lifetime success/failure counters make
     * recovered routes carry old failures forever; mature observatories instead
     * reason over recent samples. Halving at 64 observations gives BlueVPN a
     * cheap rolling history without a per-probe database.
     */
    private fun recentCounts(old: RouteSnapshot): Pair<Int, Int> =
        if (old.samples >= 64) {
            ((old.successCount + 1) / 2) to ((old.failureCount + 1) / 2)
        } else {
            old.successCount to old.failureCount
        }

    fun recordSuccess(context: Context, guid: String, latencyMs: Long) {
        if (guid.isBlank()) return
        val old = snapshot(context, guid)
        val sample = latencyMs.coerceIn(1L, 60_000L)
        val latency = if (old.latencyEwmaMs <= 0L) {
            sample
        } else {
            (old.latencyEwmaMs * 65L + sample * 35L) / 100L
        }
        val delta = if (old.lastLatencyMs > 0L) abs(sample - old.lastLatencyMs) else 0L
        val jitter = when {
            delta <= 0L -> old.jitterEwmaMs
            old.jitterEwmaMs <= 0L -> delta
            else -> (old.jitterEwmaMs * 70L + delta * 30L) / 100L
        }
        val (baseSuccess, baseFailure) = recentCounts(old)
        val now = System.currentTimeMillis()
        save(
            context,
            guid,
            old.copy(
                successCount = (baseSuccess + 1).coerceAtMost(50_000),
                failureCount = baseFailure,
                consecutiveFailures = 0,
                latencyEwmaMs = latency,
                jitterEwmaMs = jitter,
                lastLatencyMs = sample,
                lastSuccessAt = now,
                cooldownUntil = 0L,
                lastFailureReason = "",
            ),
        )
        rememberSticky(context, guid, now)
        BlueVpnIntelligenceCore.recordRouteOutcome(
            context = context,
            guid = guid,
            success = true,
            latencyMs = sample,
            jitterMs = jitter,
        )
        BlueVpnNativeNetworkAdaptation.observeSuccess(context, guid)
    }

    /**
     * Learn real user-visible throughput without running a blocking speed test.
     * Only meaningful transfer samples are recorded, so idle browsing does not
     * make a healthy route look slow. The value is scoped to the physical network.
     */
    fun recordThroughput(context: Context, guid: String, bytesPerSecond: Long) {
        if (guid.isBlank()) return
        val sample = bytesPerSecond.coerceIn(64L * 1024L, 250L * 1024L * 1024L)
        if (sample < 64L * 1024L) return
        val old = snapshot(context, guid)
        val learned = when {
            old.throughputEwmaBps <= 0L -> sample
            sample >= old.throughputEwmaBps ->
                (old.throughputEwmaBps * 70L + sample * 30L) / 100L
            sample >= old.throughputEwmaBps / 3L ->
                (old.throughputEwmaBps * 90L + sample * 10L) / 100L
            else -> old.throughputEwmaBps
        }
        save(
            context,
            guid,
            old.copy(
                throughputEwmaBps = learned.coerceAtLeast(0L),
                throughputSampleAt = System.currentTimeMillis(),
            ),
        )
    }

    fun recordFailure(context: Context, guid: String, reason: String) {
        if (guid.isBlank()) return
        val old = snapshot(context, guid)
        val (baseSuccess, baseFailure) = recentCounts(old)
        val streak = (old.consecutiveFailures + 1).coerceAtMost(12)
        val now = System.currentTimeMillis()

        // First failure never prevents the next explicit user attempt. Repeated
        // failures introduce a short bounded backoff like a circuit breaker,
        // but the profile remains in the catalogue and can recover naturally.
        val backoffMs = when (streak) {
            0, 1 -> 0L
            2 -> 20_000L
            3 -> 60_000L
            4 -> 2 * 60_000L
            5 -> 5 * 60_000L
            else -> 10 * 60_000L
        }
        save(
            context,
            guid,
            old.copy(
                successCount = baseSuccess,
                failureCount = (baseFailure + 1).coerceAtMost(50_000),
                consecutiveFailures = streak,
                lastFailureAt = now,
                cooldownUntil = now + backoffMs,
                lastFailureReason = reason.trim().take(MAX_LAST_REASON),
            ),
        )
        BlueVpnIntelligenceCore.recordRouteOutcome(
            context = context,
            guid = guid,
            success = false,
            reason = reason,
        )
        BlueVpnNativeNetworkAdaptation.observeFailure(context, guid, reason)
    }

    /**
     * Score adjustment based on measurements from this exact physical network.
     * It is deliberately bounded so a stale local history can never outweigh a
     * fresh real latency result or permanently bury a recovered route.
     */
    fun rankingAdjustment(context: Context, guid: String): Int {
        val s = snapshot(context, guid)
        if (s.samples <= 0) return 0
        val now = System.currentTimeMillis()
        if (maxOf(s.lastSuccessAt, s.lastFailureAt) > 0L &&
            now - maxOf(s.lastSuccessAt, s.lastFailureAt) > HISTORY_STALE_MS) {
            return 0
        }

        var adjustment = 0
        if (s.successCount > 0) {
            adjustment += when {
                s.successRate >= 95 -> 10
                s.successRate >= 80 -> 7
                s.successRate >= 65 -> 3
                s.successRate < 40 && s.samples >= 3 -> -7
                else -> 0
            }
            adjustment += when (s.latencyEwmaMs) {
                in 1..80 -> 7
                in 81..150 -> 5
                in 151..250 -> 2
                in 401..650 -> -4
                in 651..Long.MAX_VALUE -> -8
                else -> 0
            }
            adjustment += when (s.jitterEwmaMs) {
                in 1..20 -> 4
                in 21..50 -> 2
                in 121..250 -> -4
                in 251..Long.MAX_VALUE -> -7
                else -> 0
            }
        }
        if (s.throughputEwmaBps > 0L &&
            System.currentTimeMillis() - s.throughputSampleAt in 0..HISTORY_STALE_MS) {
            adjustment += when {
                s.throughputEwmaBps >= 20L * 1024L * 1024L -> 10
                s.throughputEwmaBps >= 8L * 1024L * 1024L -> 8
                s.throughputEwmaBps >= 3L * 1024L * 1024L -> 6
                s.throughputEwmaBps >= 1L * 1024L * 1024L -> 4
                s.throughputEwmaBps >= 256L * 1024L -> 2
                else -> 0
            }
        }
        adjustment -= (s.consecutiveFailures * 8).coerceAtMost(32)
        if (s.cooldownUntil > now) adjustment -= 14
        return adjustment.coerceIn(-36, 30)
    }

    fun evidence(context: Context, guid: String): String? {
        val s = snapshot(context, guid)
        if (s.samples <= 0) return null
        val pieces = mutableListOf<String>()
        if (s.successCount > 0) pieces += "پایداری ${s.successRate}%"
        if (s.latencyEwmaMs > 0L) pieces += "میانگین ${s.latencyEwmaMs}ms"
        if (s.jitterEwmaMs > 0L) pieces += "نوسان ${s.jitterEwmaMs}ms"
        if (s.throughputEwmaBps > 0L) {
            val mbps = (s.throughputEwmaBps * 8.0 / 1_000_000.0)
            pieces += "سرعت واقعی %.1fMbps".format(Locale.US, mbps)
        }
        if (s.consecutiveFailures > 0) pieces += "${s.consecutiveFailures} خطای پیاپی"
        return pieces.take(3).joinToString(" • ").takeIf { it.isNotBlank() }
    }

    fun isCoolingDown(context: Context, guid: String): Boolean =
        snapshot(context, guid).cooldownUntil > System.currentTimeMillis()

    private fun stickyKey(context: Context): String = "$STICKY_PREFIX${networkKey(context)}"

    private fun rememberSticky(context: Context, guid: String, at: Long = System.currentTimeMillis()) {
        val fingerprint = semanticFingerprint(guid)
        if (fingerprint.isBlank()) return
        prefs(context).edit()
            .putString(stickyKey(context), "$at|$fingerprint")
            .apply()
    }

    /**
     * Prefer the last verified route while it remains close to the current best.
     * This mirrors URL-test hysteresis/tolerance used by desktop clients and
     * prevents needless server flapping when two nodes differ by only a few ms.
     */
    fun stickyCandidate(
        context: Context,
        ranked: List<BlueVpnSmartSelector.ScoredCandidate>,
        scoreTolerance: Int = 7,
        latencyToleranceMs: Long = 60L,
    ): BlueVpnSmartSelector.ScoredCandidate? {
        if (ranked.size < 2) return null
        val raw = prefs(context).getString(stickyKey(context), "").orEmpty()
        val at = raw.substringBefore('|').toLongOrNull() ?: return null
        val fingerprint = raw.substringAfter('|', "")
        if (fingerprint.isBlank() || System.currentTimeMillis() - at !in 0..STICKY_MAX_AGE_MS) {
            return null
        }
        val sticky = ranked.firstOrNull { item ->
            semanticFingerprint(item.candidate.guid) == fingerprint
        } ?: return null
        if (BlueVpnPreferences.isSessionInactive(context, sticky.candidate.guid)) return null
        if (sticky.score <= 0) return null

        val best = ranked.first()
        if (sticky.candidate.guid == best.candidate.guid) return sticky
        val closeByScore = sticky.score >= best.score - scoreTolerance
        val stickyDelay = sticky.candidate.delay
        val bestDelay = best.candidate.delay
        val closeByLatency = stickyDelay > 0L && bestDelay > 0L &&
            stickyDelay <= bestDelay + latencyToleranceMs
        return sticky.takeIf { closeByScore || closeByLatency }
    }

    /** Store public exit identity observed through the active proxy/core. */
    fun recordExitTrace(context: Context, guid: String, traceBody: String) {
        if (guid.isBlank() || traceBody.isBlank()) return
        val values = traceBody.lineSequence()
            .mapNotNull { line ->
                val key = line.substringBefore('=', "").trim().lowercase(Locale.ROOT)
                if (key.isBlank() || '=' !in line) return@mapNotNull null
                key to line.substringAfter('=').trim()
            }
            .toMap()
        val ip = values["ip"].orEmpty().takeIf(::isPublicIp).orEmpty()
        val country = values["loc"].orEmpty()
            .trim()
            .lowercase(Locale.ROOT)
            .takeIf { it.length == 2 }
            .orEmpty()
        val colo = values["colo"].orEmpty()
            .trim()
            .uppercase(Locale.ROOT)
            .takeIf { it.length in 3..6 && it.all(Char::isLetterOrDigit) }
            .orEmpty()
        if (ip.isBlank() && country.isBlank() && colo.isBlank()) return

        val old = snapshot(context, guid)
        val now = System.currentTimeMillis()
        val changedAt = if (ip.isNotBlank() && old.exitIp.isNotBlank() && ip != old.exitIp) {
            now
        } else {
            old.exitChangedAt
        }
        save(
            context,
            guid,
            old.copy(
                exitIp = ip.ifBlank { old.exitIp },
                exitCountry = country.ifBlank { old.exitCountry },
                exitColo = colo.ifBlank { old.exitColo },
                exitChangedAt = changedAt,
            ),
        )
    }

    fun exitSummary(context: Context, guid: String): String? {
        val s = snapshot(context, guid)
        if (s.exitIp.isBlank() && s.exitCountry.isBlank() && s.exitColo.isBlank()) return null
        return listOf(s.exitCountry.uppercase(Locale.ROOT), s.exitIp, s.exitColo)
            .filter { it.isNotBlank() }
            .joinToString(" • ")
    }

    private fun isPublicIp(value: String): Boolean = runCatching {
        val address = InetAddress.getByName(value)
        if (address.isAnyLocalAddress || address.isLoopbackAddress ||
            address.isLinkLocalAddress || address.isSiteLocalAddress ||
            address.isMulticastAddress) {
            return@runCatching false
        }
        val bytes = address.address
        if (bytes.size == 4) {
            val first = bytes[0].toInt() and 0xff
            val second = bytes[1].toInt() and 0xff
            // Carrier-grade NAT 100.64.0.0/10 and documentation ranges are not
            // valid public exit identities for BlueVPN health purposes.
            if (first == 100 && second in 64..127) return@runCatching false
            if (first == 192 && second == 0) return@runCatching false
            if (first == 198 && second in 18..19) return@runCatching false
        }
        true
    }.getOrDefault(false)

    fun clearRoute(context: Context, guid: String) {
        val key = key(context, guid)
        if (key.isNotBlank()) prefs(context).edit().remove(key).apply()
    }

    /** Remove stale per-network records without touching active route state. */
    fun prune(context: Context) {
        val storage = prefs(context)
        val now = System.currentTimeMillis()
        val editor = storage.edit()
        storage.all.forEach { (key, value) ->
            if (!key.startsWith(ROUTE_PREFIX)) return@forEach
            val json = runCatching { JSONObject(value?.toString().orEmpty()) }.getOrNull()
                ?: return@forEach
            val touched = maxOf(json.optLong("sa", 0L), json.optLong("fa", 0L), json.optLong("ica", 0L))
            if (touched > 0L && now - touched > HISTORY_STALE_MS) editor.remove(key)
        }
        editor.apply()
    }
}
