package com.v2ray.ang.bluevpn

import android.content.Context
import android.os.SystemClock
import com.v2ray.ang.AppConfig
import com.v2ray.ang.dto.TestServiceMessage
import com.v2ray.ang.core.CoreServiceManager
import com.v2ray.ang.handler.MmkvManager
import com.v2ray.ang.util.MessageUtil
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject
import java.security.MessageDigest
import kotlin.math.abs
import kotlin.math.roundToLong

/**
 * Progressive full-pool optimizer.
 *
 * This is intentionally independent from an Activity. When Android background
 * restrictions are cleared, every candidate in the active entitlement pool is
 * measured against the user's current physical-network fingerprint. Testing is
 * progressive/batched and never tears down a live VPN.
 *
 * Two fresh v2rayNG TestService passes are used per route. This gives us more
 * than a one-shot ping: average RTT, simple jitter and repeatability. The result
 * is persisted per network + entitlement and feeds SmartSelector on later
 * connections.
 */
object BlueVpnBackgroundOptimizer {
    enum class Bucket(val key: String) {
        FAST("fast"),
        STABLE("stable"),
        RESERVE("reserve"),
        FAILED("failed"),
    }

    data class RouteResult(
        val guid: String,
        val averageMs: Long,
        val jitterMs: Long,
        val successSamples: Int,
        val attempts: Int,
        val bucket: Bucket,
        val score: Int,
    ) {
        val lossX100: Int
            get() = (((attempts - successSamples).coerceAtLeast(0) * 10_000.0) /
                attempts.coerceAtLeast(1).toDouble()).toInt().coerceIn(0, 10_000)
    }

    data class Snapshot(
        val networkId: String,
        val entitlementId: String,
        val completedAt: Long,
        val total: Int,
        val tested: Int,
        val fast: Int,
        val stable: Int,
        val reserve: Int,
        val failed: Int,
        val results: Map<String, RouteResult>,
    )

    private const val PREFS = "bluevpn_background_optimizer_v1"
    private const val KEY_LAST = "last_snapshot"
    private const val KEY_PENDING = "pending"
    private const val TTL_MS = 6L * 60L * 60L * 1000L
    private const val BATCH_SIZE = 10
    private const val PASS_TIMEOUT_MS = 8_500L
    private const val POLL_MS = 240L
    private const val INTER_BATCH_DELAY_MS = 650L

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Default)
    @Volatile private var activeJob: Job? = null

    private fun prefs(context: Context) =
        context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    private fun entitlementId(context: Context): String =
        BlueVpnAccountManager.entitlementIdentityFingerprint(context)

    private fun key(context: Context): String {
        val network = BlueVpnIntelligenceCore.networkFingerprint(context).id
        return "$network|${entitlementId(context)}"
    }

    fun markPending(context: Context) {
        prefs(context).edit().putBoolean(KEY_PENDING, true).apply()
    }

    fun isRunning(): Boolean = activeJob?.isActive == true

    fun maybeStart(context: Context, force: Boolean = false): Boolean {
        val app = context.applicationContext
        if (!BlueVpnBackgroundReliability.state(app).fullyReady) return false

        val currentKey = key(app)
        val last = snapshot(app)
        val fresh =
            last != null &&
                "${last.networkId}|${last.entitlementId}" == currentKey &&
                System.currentTimeMillis() - last.completedAt in 0L..TTL_MS

        val pending = prefs(app).getBoolean(KEY_PENDING, false)
        if (!force && fresh && !pending) return false
        if (activeJob?.isActive == true) return true

        prefs(app).edit().putBoolean(KEY_PENDING, true).apply()
        activeJob = scope.launch {
            runOptimizer(app)
        }
        return true
    }

    fun forceStart(context: Context): Boolean = maybeStart(context, force = true)

    /**
     * Route-specific bonus/penalty used by SmartSelector. A result is only valid
     * for the current physical network + active entitlement pool.
     */

    fun bucketFor(context: Context, guid: String): Bucket? {
        val shot = snapshot(context) ?: return null
        if ("${shot.networkId}|${shot.entitlementId}" != key(context)) return null
        if (System.currentTimeMillis() - shot.completedAt > TTL_MS) return null
        return shot.results[guid]?.bucket
    }

    fun bestBucket(context: Context, guids: List<String>): Bucket? {
        val buckets = guids.mapNotNull { bucketFor(context, it) }
        return when {
            Bucket.FAST in buckets -> Bucket.FAST
            Bucket.STABLE in buckets -> Bucket.STABLE
            Bucket.RESERVE in buckets -> Bucket.RESERVE
            Bucket.FAILED in buckets -> Bucket.FAILED
            else -> null
        }
    }

    fun bucketLabel(bucket: Bucket?): String = when (bucket) {
        Bucket.FAST -> "سریع"
        Bucket.STABLE -> "پایدار"
        Bucket.RESERVE -> "ذخیره"
        Bucket.FAILED -> "ناموفق"
        null -> "در انتظار تست"
    }

    fun rankingAdjustment(context: Context, guid: String): Int {
        val shot = snapshot(context) ?: return 0
        if ("${shot.networkId}|${shot.entitlementId}" != key(context)) return 0
        if (System.currentTimeMillis() - shot.completedAt > TTL_MS) return 0
        val result = shot.results[guid] ?: return 0
        return when (result.bucket) {
            Bucket.FAST -> 14
            Bucket.STABLE -> 9
            Bucket.RESERVE -> -2
            Bucket.FAILED -> -38
        } + when {
            result.jitterMs <= 18L && result.successSamples == result.attempts -> 3
            result.jitterMs >= 160L -> -6
            else -> 0
        }
    }

    fun evidence(context: Context, guid: String): String? {
        val shot = snapshot(context) ?: return null
        if ("${shot.networkId}|${shot.entitlementId}" != key(context)) return null
        val result = shot.results[guid] ?: return null
        val label = when (result.bucket) {
            Bucket.FAST -> "سریع"
            Bucket.STABLE -> "پایدار"
            Bucket.RESERVE -> "ذخیره"
            Bucket.FAILED -> "ناموفق"
        }
        return "Background AI: $label • ${result.averageMs}ms • Jitter ${result.jitterMs}ms"
    }

    fun snapshot(context: Context): Snapshot? {
        val raw = prefs(context).getString(KEY_LAST, "").orEmpty()
        if (raw.isBlank()) return null
        return runCatching {
            val json = JSONObject(raw)
            val map = linkedMapOf<String, RouteResult>()
            val rows = json.optJSONArray("results") ?: JSONArray()
            for (i in 0 until rows.length()) {
                val row = rows.optJSONObject(i) ?: continue
                val guid = row.optString("guid")
                if (guid.isBlank()) continue
                val bucket = runCatching {
                    Bucket.valueOf(row.optString("bucket"))
                }.getOrDefault(Bucket.RESERVE)
                map[guid] = RouteResult(
                    guid = guid,
                    averageMs = row.optLong("avg", 0L),
                    jitterMs = row.optLong("jitter", 0L),
                    successSamples = row.optInt("success", 0),
                    attempts = row.optInt("attempts", 2),
                    bucket = bucket,
                    score = row.optInt("score", 0),
                )
            }
            Snapshot(
                networkId = json.optString("network_id"),
                entitlementId = json.optString("entitlement_id"),
                completedAt = json.optLong("completed_at", 0L),
                total = json.optInt("total", map.size),
                tested = json.optInt("tested", map.size),
                fast = json.optInt("fast", 0),
                stable = json.optInt("stable", 0),
                reserve = json.optInt("reserve", 0),
                failed = json.optInt("failed", 0),
                results = map,
            )
        }.getOrNull()
    }

    private suspend fun runOptimizer(context: Context) {
        try {
            // Testing while a VPN is active can measure the tunnel instead of the
            // user's underlying network. Keep the request pending and wait for an
            // idle window; never disconnect an active user just to benchmark.
            var idleWaits = 0
            while (CoreServiceManager.isRunning()) {
                delay(5_000L)
                idleWaits++
                if (idleWaits >= 72) return // leave pending; retry on next resume
            }

            val network = BlueVpnIntelligenceCore.networkFingerprint(context)
            if (!network.validated) return

            val candidates = withContext(Dispatchers.Default) {
                BlueVpnLocationUtil.allCandidates(context, forceRefresh = true)
                    .distinctBy { it.guid }
            }
            if (candidates.isEmpty()) return

            val entitlement = entitlementId(context)
            val results = linkedMapOf<String, RouteResult>()

            for (batch in candidates.chunked(BATCH_SIZE)) {
                if (CoreServiceManager.isRunning()) return
                val guids = batch.map { it.guid }
                val first = measurePass(context, guids)
                delay(450L)
                val second = measurePass(context, guids)

                for (candidate in batch) {
                    val samples = listOfNotNull(
                        first[candidate.guid]?.takeIf { it > 0L },
                        second[candidate.guid]?.takeIf { it > 0L },
                    )
                    val attempts = 2
                    val average = if (samples.isNotEmpty()) samples.average().roundToLong() else 0L
                    val jitter = if (samples.size >= 2) abs(samples[1] - samples[0]) else 0L
                    val lossX100 =
                        (((attempts - samples.size) * 10_000.0) / attempts).toInt()
                            .coerceIn(0, 10_000)
                    val bucket = classify(average, jitter, samples.size, attempts)
                    val score = qualityScore(average, jitter, lossX100, bucket)
                    val result = RouteResult(
                        guid = candidate.guid,
                        averageMs = average,
                        jitterMs = jitter,
                        successSamples = samples.size,
                        attempts = attempts,
                        bucket = bucket,
                        score = score,
                    )
                    results[candidate.guid] = result

                    // Feed the same network-specific intelligence store used by
                    // live post-connect health, so future ranking has one source
                    // of route history rather than disconnected ping databases.
                    BlueVpnIntelligenceCore.recordRouteOutcome(
                        context = context,
                        guid = candidate.guid,
                        success = bucket != Bucket.FAILED,
                        latencyMs = average,
                        jitterMs = jitter,
                        packetLossX100 = lossX100,
                        reason = if (bucket == Bucket.FAILED) {
                            "BACKGROUND_POOL_PROBE_FAILED"
                        } else {
                            "BACKGROUND_POOL_PROBE_${bucket.name}"
                        },
                    )
                }
                persistPartial(context, network.id, entitlement, candidates.size, results)
                delay(INTER_BATCH_DELAY_MS)
            }

            persistFinal(context, network.id, entitlement, candidates.size, results)
            prefs(context).edit().putBoolean(KEY_PENDING, false).apply()
        } finally {
            activeJob = null
        }
    }

    private suspend fun measurePass(
        context: Context,
        guids: List<String>,
    ): Map<String, Long> {
        if (guids.isEmpty()) return emptyMap()
        MmkvManager.clearAllTestDelayResults(guids)
        MessageUtil.sendMsg2TestService(
            context,
            TestServiceMessage(key = AppConfig.MSG_MEASURE_CONFIG_CANCEL),
        )
        MessageUtil.sendMsg2TestService(
            context,
            TestServiceMessage(
                key = AppConfig.MSG_MEASURE_CONFIG_START,
                serverGuids = guids,
            ),
        )

        val started = SystemClock.elapsedRealtime()
        while (SystemClock.elapsedRealtime() - started < PASS_TIMEOUT_MS) {
            val rows = guids.associateWith { guid ->
                MmkvManager.decodeServerAffiliationInfo(guid)?.testDelayMillis ?: 0L
            }
            val finished = rows.values.count { it != 0L }
            if (finished >= guids.size) {
                MessageUtil.sendMsg2TestService(
                    context,
                    TestServiceMessage(key = AppConfig.MSG_MEASURE_CONFIG_CANCEL),
                )
                return rows
            }
            delay(POLL_MS)
        }

        MessageUtil.sendMsg2TestService(
            context,
            TestServiceMessage(key = AppConfig.MSG_MEASURE_CONFIG_CANCEL),
        )
        return guids.associateWith { guid ->
            MmkvManager.decodeServerAffiliationInfo(guid)?.testDelayMillis ?: 0L
        }
    }

    private fun classify(
        averageMs: Long,
        jitterMs: Long,
        success: Int,
        attempts: Int,
    ): Bucket = when {
        success == 0 -> Bucket.FAILED
        success < attempts -> Bucket.RESERVE
        averageMs in 1L..140L && jitterMs <= 35L -> Bucket.FAST
        averageMs in 1L..320L && jitterMs <= 95L -> Bucket.STABLE
        averageMs > 0L -> Bucket.RESERVE
        else -> Bucket.FAILED
    }

    private fun qualityScore(
        averageMs: Long,
        jitterMs: Long,
        lossX100: Int,
        bucket: Bucket,
    ): Int {
        var score = when {
            averageMs in 1L..60L -> 100
            averageMs in 61L..100L -> 94
            averageMs in 101L..160L -> 86
            averageMs in 161L..240L -> 75
            averageMs in 241L..350L -> 63
            averageMs in 351L..500L -> 48
            averageMs > 500L -> 32
            else -> 0
        }
        score -= (jitterMs / 12L).toInt().coerceAtMost(24)
        score -= (lossX100 / 250).coerceAtMost(40)
        if (bucket == Bucket.FAILED) score = 0
        return score.coerceIn(0, 100)
    }

    private fun persistPartial(
        context: Context,
        networkId: String,
        entitlementId: String,
        total: Int,
        results: Map<String, RouteResult>,
    ) {
        persist(
            context = context,
            networkId = networkId,
            entitlementId = entitlementId,
            total = total,
            results = results,
            completed = false,
        )
    }

    private fun persistFinal(
        context: Context,
        networkId: String,
        entitlementId: String,
        total: Int,
        results: Map<String, RouteResult>,
    ) {
        persist(
            context = context,
            networkId = networkId,
            entitlementId = entitlementId,
            total = total,
            results = results,
            completed = true,
        )
    }

    private fun persist(
        context: Context,
        networkId: String,
        entitlementId: String,
        total: Int,
        results: Map<String, RouteResult>,
        completed: Boolean,
    ) {
        val rows = JSONArray()
        results.values.forEach { result ->
            rows.put(
                JSONObject()
                    .put("guid", result.guid)
                    .put("avg", result.averageMs)
                    .put("jitter", result.jitterMs)
                    .put("success", result.successSamples)
                    .put("attempts", result.attempts)
                    .put("bucket", result.bucket.name)
                    .put("score", result.score)
            )
        }
        val counts = results.values.groupingBy { it.bucket }.eachCount()
        val json = JSONObject()
            .put("network_id", networkId)
            .put("entitlement_id", entitlementId)
            .put("completed_at", if (completed) System.currentTimeMillis() else 0L)
            .put("partial_at", System.currentTimeMillis())
            .put("total", total)
            .put("tested", results.size)
            .put("fast", counts[Bucket.FAST] ?: 0)
            .put("stable", counts[Bucket.STABLE] ?: 0)
            .put("reserve", counts[Bucket.RESERVE] ?: 0)
            .put("failed", counts[Bucket.FAILED] ?: 0)
            .put("results", rows)

        prefs(context).edit().putString(KEY_LAST, json.toString()).apply()
    }
}
