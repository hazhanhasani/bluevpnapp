package com.v2ray.ang.bluevpn

import android.content.Context
import android.net.TrafficStats
import android.os.PowerManager
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicBoolean

/**
 * Low-power verified live-session reporter.
 *
 * The previous implementation performed a heavy remote proof every 32 seconds
 * and the home screen also triggered another proof. This reporter is now the
 * single owner of background heartbeats. It adapts to screen/power state and
 * BlueVpnAi reuses a recent verified proof before creating a new socket.
 * Guest/Free sessions are first-class citizens: live telemetry does not require
 * an authenticated account and is rate-limited server-side by device identity.
 */
object BlueVpnLiveReporter {
    // A fresh RTT must reach BlueAI quickly after CONNECTED. The payload is
    // tiny; the expensive multi-sample RTT probe remains bounded to 2 samples
    // on low-end devices and 3 elsewhere.
    private const val INITIAL_DELAY_SECONDS = 2L
    private const val ACTIVE_DELAY_SECONDS = 10L
    private const val SCREEN_OFF_DELAY_SECONDS = 30L
    private const val POWER_SAVE_DELAY_SECONDS = 45L
    private const val IDLE_DELAY_SECONDS = 30L

    private val started = AtomicBoolean(false)
    private val executor = Executors.newSingleThreadScheduledExecutor {
        Thread(it, "bluevpn-live-reporter").apply {
            isDaemon = true
            priority = Thread.NORM_PRIORITY - 2
        }
    }

    fun start(context: Context) {
        if (!started.compareAndSet(false, true)) return
        schedule(context.applicationContext, INITIAL_DELAY_SECONDS)
    }

    /** Push a one-shot fresh heartbeat shortly after CONNECTED. */
    fun kick(context: Context, delayMs: Long = 900L) {
        val app = context.applicationContext
        executor.schedule(
            { runCatching { reportOnce(app) } },
            delayMs.coerceIn(150L, 5_000L),
            TimeUnit.MILLISECONDS,
        )
    }

    private fun schedule(app: Context, delaySeconds: Long) {
        executor.schedule(
            {
                runCatching { reportOnce(app) }
                schedule(app, nextDelaySeconds(app))
            },
            delaySeconds,
            TimeUnit.SECONDS,
        )
    }

    private fun reportOnce(app: Context) {
        if (
            !BlueVpnAi.hasActiveSession(app) ||
            !BlueVpnAi.hasVpnTransport(app)
        ) {
            return
        }

        val uid = app.applicationInfo.uid
        val rx = TrafficStats.getUidRxBytes(uid).takeIf { it >= 0L } ?: 0L
        val tx = TrafficStats.getUidTxBytes(uid).takeIf { it >= 0L } ?: 0L
        val latency = BlueVpnAi.measureLiveTunnelLatency(
            app,
            requestedSamples = if (BlueVpnPerformance.isLowEnd(app)) 2 else 3,
        )
        val selectedGuid = com.v2ray.ang.handler.MmkvManager.getSelectServer().orEmpty()
        val health = BlueVpnIntelligenceCore.observeHealth(
            context = app,
            guid = selectedGuid,
            pingMs = latency?.averageMs ?: 0L,
            jitterMs = latency?.jitterMs ?: 0L,
            packetLossX100 = latency?.packetLossX100 ?: 0,
        )
        if (health.shouldWarmFailover && selectedGuid.isNotBlank()) {
            BlueVpnRouteIntelligence.recordFailure(
                app,
                selectedGuid,
                "PREDICTIVE_DEGRADATION_CONFIRMED:${health.reason}",
            )
            BlueVpnSystemController.predictiveFailover(app)
        }

        BlueVpnAi.heartbeat(
            app,
            pingMs = latency?.averageMs ?: 0L,
            pingMinMs = latency?.minMs ?: 0L,
            pingMaxMs = latency?.maxMs ?: 0L,
            jitterMs = latency?.jitterMs ?: 0L,
            packetLossX100 = latency?.packetLossX100 ?: 0,
            pingSamples = latency?.samples ?: 0,
            healthScore = health.score,
            downloadBytes = rx,
            uploadBytes = tx,
        )
    }

    private fun nextDelaySeconds(app: Context): Long {
        if (!BlueVpnAi.hasActiveSession(app)) return IDLE_DELAY_SECONDS
        if (BlueVpnPerformance.isLowEnd(app)) return 20L
        val power = app.getSystemService(Context.POWER_SERVICE) as PowerManager
        return when {
            power.isPowerSaveMode -> POWER_SAVE_DELAY_SECONDS
            !power.isInteractive -> SCREEN_OFF_DELAY_SECONDS
            else -> ACTIVE_DELAY_SECONDS
        }
    }
}
