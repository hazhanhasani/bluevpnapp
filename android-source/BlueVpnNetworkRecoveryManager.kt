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
                BlueVpnRuntimeAudit.record(
                    context.applicationContext,
                    BlueVpnRuntimeAudit.Event.NETWORK_CHANGED
                )
                // Give the active engine a chance to restore transport without
                // destroying user state.
                try {
                    BlueVpnWarpKeepAliveService.requestNetworkRecovery(
                        context.applicationContext
                    )
                } catch (_: Throwable) {
                    // Some builds do not include WARP keepalive recovery.
                }
            }

            override fun onLost(network: Network) {
                BlueVpnRuntimeAudit.record(
                    context.applicationContext,
                    BlueVpnRuntimeAudit.Event.NETWORK_LOST
                )
            }
        }

        cm.registerDefaultNetworkCallback(cb)
        callback = cb
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
