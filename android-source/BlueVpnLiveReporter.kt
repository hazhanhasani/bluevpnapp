package com.v2ray.ang.bluevpn

import android.content.Context
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicBoolean

/**
 * Keeps verified live-session reporting active while the VPN foreground
 * service is running, even when BlueVpnHomeActivity is in the background.
 *
 * It never reports a connection based only on process/service state:
 * BlueVpnAi.heartbeat() performs a fresh remote request through the local
 * Xray proxy and also requires an Android VPN transport.
 */
object BlueVpnLiveReporter {
    private val started = AtomicBoolean(false)
    private val executor = Executors.newSingleThreadScheduledExecutor {
        Thread(it, "bluevpn-live-reporter").apply {
            isDaemon = true
            priority = Thread.NORM_PRIORITY - 1
        }
    }

    fun start(context: Context) {
        if (!started.compareAndSet(false, true)) return
        val app = context.applicationContext
        executor.scheduleWithFixedDelay(
            {
                runCatching {
                    if (
                        BlueVpnAccountManager.hasSession(app) &&
                        BlueVpnAi.hasActiveSession(app) &&
                        BlueVpnAi.hasVpnTransport(app)
                    ) {
                        BlueVpnAi.heartbeat(
                            app,
                            pingMs = 0L,
                            healthScore = 0,
                            downloadBytes = 0L,
                            uploadBytes = 0L,
                        )
                    }
                }
            },
            12L,
            32L,
            TimeUnit.SECONDS,
        )
    }
}
