package com.v2ray.ang.bluevpn

/**
 * Presentation state for a server latency sample.
 *
 * A numeric delay alone is not enough: the UI must know whether it is from the
 * current measurement cycle, an older cached sample, still measuring, or timed out.
 */
enum class BlueVpnLatencyPhase {
    UNKNOWN,
    MEASURING,
    FRESH,
    STALE,
    TIMEOUT,
    OFFLINE,
}

data class BlueVpnLatencySnapshot(
    val phase: BlueVpnLatencyPhase,
    val latencyMs: Long,
    val measuredAtMs: Long,
) {
    val hasLatency: Boolean get() = latencyMs > 0L
}

object BlueVpnLatencyPolicy {
    const val FRESH_FOR_MS = 90_000L
    const val STALE_FOR_MS = 15 * 60_000L
    const val MEASUREMENT_TIMEOUT_MS = 30_000L

    fun resolve(
        latencyMs: Long,
        measuredAtMs: Long,
        nowMs: Long,
        measuringSinceMs: Long = 0L,
        inactive: Boolean = false,
    ): BlueVpnLatencySnapshot {
        if (inactive) {
            return BlueVpnLatencySnapshot(
                BlueVpnLatencyPhase.OFFLINE,
                latencyMs.coerceAtLeast(0L),
                measuredAtMs,
            )
        }

        val measuringAge = if (measuringSinceMs > 0L) nowMs - measuringSinceMs else -1L
        if (measuringAge in 0L until MEASUREMENT_TIMEOUT_MS) {
            return BlueVpnLatencySnapshot(
                BlueVpnLatencyPhase.MEASURING,
                latencyMs.coerceAtLeast(0L),
                measuredAtMs,
            )
        }
        if (measuringAge >= MEASUREMENT_TIMEOUT_MS && latencyMs <= 0L) {
            return BlueVpnLatencySnapshot(
                BlueVpnLatencyPhase.TIMEOUT,
                0L,
                measuredAtMs,
            )
        }

        if (latencyMs <= 0L || measuredAtMs <= 0L) {
            return BlueVpnLatencySnapshot(
                BlueVpnLatencyPhase.UNKNOWN,
                0L,
                measuredAtMs,
            )
        }

        val age = (nowMs - measuredAtMs).coerceAtLeast(0L)
        val phase = when {
            age <= FRESH_FOR_MS -> BlueVpnLatencyPhase.FRESH
            age <= STALE_FOR_MS -> BlueVpnLatencyPhase.STALE
            else -> BlueVpnLatencyPhase.UNKNOWN
        }
        return BlueVpnLatencySnapshot(phase, latencyMs, measuredAtMs)
    }
}
