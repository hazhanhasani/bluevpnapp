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
 */
object BlueVpnLiveReporter {
    private const val INITIAL_DELAY_SECONDS = 12L
    private const val ACTIVE_DELAY_SECONDS = 75L
    private const val SCREEN_OFF_DELAY_SECONDS = 90L
    private const val POWER_SAVE_DELAY_SECONDS = 120L
    private const val IDLE_DELAY_SECONDS = 60L

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
            !BlueVpnAccountManager.hasSession(app) ||
            !BlueVpnAi.hasActiveSession(app) ||
            !BlueVpnAi.hasVpnTransport(app)
        ) {
            return
        }

        val uid = app.applicationInfo.uid
        val rx = TrafficStats.getUidRxBytes(uid).takeIf { it >= 0L } ?: 0L
        val tx = TrafficStats.getUidTxBytes(uid).takeIf { it >= 0L } ?: 0L
        BlueVpnAi.heartbeat(
            app,
            pingMs = 0L,
            healthScore = 0,
            downloadBytes = rx,
            uploadBytes = tx,
        )
    }

    private fun nextDelaySeconds(app: Context): Long {
        if (!BlueVpnAi.hasActiveSession(app)) return IDLE_DELAY_SECONDS
        val power = app.getSystemService(Context.POWER_SERVICE) as PowerManager
        return when {
            power.isPowerSaveMode -> POWER_SAVE_DELAY_SECONDS
            !power.isInteractive -> SCREEN_OFF_DELAY_SECONDS
            else -> ACTIVE_DELAY_SECONDS
        }
    }
}
