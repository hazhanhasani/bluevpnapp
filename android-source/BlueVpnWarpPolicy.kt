package com.v2ray.ang.bluevpn

import kotlin.math.max
import kotlin.math.min

/** Pure policy logic kept Android-free so regression tests can execute it on the JVM. */
object BlueVpnWarpPolicy {
    const val LKG_TTL_MS: Long = 6 * 60 * 60_000L

    fun lkgFresh(nowMs: Long, savedAtMs: Long, ttlMs: Long = LKG_TTL_MS): Boolean =
        savedAtMs > 0L && nowMs >= savedAtMs && nowMs - savedAtMs <= ttlMs

    fun effectiveIpMode(configured: String, ipv4: Boolean, ipv6: Boolean): String = when (configured) {
        "v4" -> "v4"
        "dual" -> "dual"
        // Iranian ISPs often advertise an IPv6 capability that cannot reach the
        // WARP edge. Auto therefore stays IPv4-first; dual is explicit opt-in.
        else -> "v4"
    }

    fun candidateScore(successes: Int, failures: Int, consecutiveFailures: Int, latencyMs: Long, freshLkg: Boolean): Double {
        val total = max(1, successes + failures)
        val successRate = successes.toDouble() / total.toDouble()
        val lkgBonus = if (freshLkg) 35.0 else 0.0
        return successRate * 55.0 + lkgBonus - min(30.0, consecutiveFailures * 8.0) - min(25.0, latencyMs / 160.0)
    }

    fun backoffMs(code: String, count: Int): Long = when (code) {
        "EXIT_IRAN", "WARP_EXIT_COUNTRY_BLOCKED" -> 30 * 60_000L
        "CONFIG_INVALID" -> 60 * 60_000L
        "NETWORK_CHANGED", "WARP_NETWORK_CHANGED" -> 2_000L
        "DNS_FAILED" -> min(10 * 60_000L, 30_000L * (1L shl min(4, count)))
        "PORT_IN_USE", "WARP_PORT_OCCUPIED" -> 3_000L
        "AETHER_CRASHED", "WARP_PROCESS_EXITED" -> min(15 * 60_000L, 60_000L * max(1, count))
        else -> min(15 * 60_000L, 30_000L * (1L shl min(5, count)))
    }
}
