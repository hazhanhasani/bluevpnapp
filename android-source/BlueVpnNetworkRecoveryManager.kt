package com.v2ray.ang.bluevpn

import android.content.Context
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities

/**
 * Lightweight network recovery observer.
 *
 * Keeps the VPN runtime aware of network transitions. It intentionally does not
 * force restart the tunnel: the existing engine decides the safest recovery path.
 */
object BlueVpnNetworkRecoveryManager {
    private var callback: ConnectivityManager.NetworkCallback? = null
    private const val PREFS = "bluevpn_network_recovery"
    private const val KEY_LAST_LOST_AT = "last_lost_at"
    private const val KEY_RECOVERY_UNTIL = "recovery_until"
    private const val RECOVERY_WINDOW_MS = 60_000L

    private fun prefs(context: Context) = context.applicationContext
        .getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    /**
     * During a short physical-network handover window, retry the last verified
     * route first if it is still eligible. This mirrors resilient clients that
     * reconnect to the just-working endpoint before rescanning the whole pool.
     * It never restarts the VPN by itself; the normal connection state machine
     * remains the single owner of service mutation.
     */
    fun recoveryWindowActive(context: Context): Boolean =
        prefs(context).getLong(KEY_RECOVERY_UNTIL, 0L) > System.currentTimeMillis()

    @Synchronized
    fun start(context: Context) {
        if (callback != null) return
        val cm = context.applicationContext
            .getSystemService(ConnectivityManager::class.java) ?: return

        val cb = object : ConnectivityManager.NetworkCallback() {
            override fun onAvailable(network: Network) {
                runCatching {
                    val app = context.applicationContext
                    val p = prefs(app)
                    val lastLost = p.getLong(KEY_LAST_LOST_AT, 0L)
                    if (lastLost > 0L && System.currentTimeMillis() - lastLost in 0..RECOVERY_WINDOW_MS) {
                        p.edit().putLong(KEY_RECOVERY_UNTIL, System.currentTimeMillis() + RECOVERY_WINDOW_MS).apply()
                    }
                    BlueVpnRuntimeAudit.record(
                        app,
                        BlueVpnRuntimeAudit.Event.NETWORK_CHANGE,
                        "available"
                    )
                }
                // Do not restart the VPN from a ConnectivityManager callback.
                // onAvailable is also fired for the initial/default network and
                // during noisy handovers; restarting here created connect loops and
                // could kill a session while it was still VERIFYING. The active
                // engine/state machine is the single owner of reconnect behavior.
            }

            override fun onLost(network: Network) {
                runCatching {
                    val app = context.applicationContext
                    val now = System.currentTimeMillis()
                    prefs(app).edit()
                        .putLong(KEY_LAST_LOST_AT, now)
                        .putLong(KEY_RECOVERY_UNTIL, now + RECOVERY_WINDOW_MS)
                        .apply()
                    BlueVpnRuntimeAudit.record(
                        app,
                        BlueVpnRuntimeAudit.Event.NETWORK_CHANGE,
                        "lost"
                    )
                }
            }
        }

        try {
            cm.registerDefaultNetworkCallback(cb)
            callback = cb
        } catch (_: Throwable) {
            // Network observation is optional telemetry/recovery input. A vendor
            // ROM/security exception must never crash the BlueVPN process.
            callback = null
        }
    }

    @Synchronized
    fun stop(context: Context) {
        val cm = context.applicationContext
            .getSystemService(ConnectivityManager::class.java) ?: return
        callback?.let {
            try { cm.unregisterNetworkCallback(it) } catch (_: Throwable) {}
        }
        callback = null
    }
}
