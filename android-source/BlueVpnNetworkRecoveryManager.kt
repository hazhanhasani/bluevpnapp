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

    @Synchronized
    fun start(context: Context) {
        if (callback != null) return
        val cm = context.applicationContext
            .getSystemService(ConnectivityManager::class.java) ?: return

        val cb = object : ConnectivityManager.NetworkCallback() {
            override fun onAvailable(network: Network) {
                runCatching {
                    BlueVpnRuntimeAudit.record(
                        context.applicationContext,
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
                    BlueVpnRuntimeAudit.record(
                        context.applicationContext,
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
