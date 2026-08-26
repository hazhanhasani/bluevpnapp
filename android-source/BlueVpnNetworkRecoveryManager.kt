package com.v2ray.ang.bluevpn

import android.content.Context
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import org.json.JSONObject

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
    private const val KEY_POLICY_RECOVERY_WINDOW_MS = "policy_recovery_window_ms"
    private const val KEY_POLICY_GATE_WAIT_MS = "policy_gate_wait_ms"
    private const val KEY_POLICY_CANDIDATE_START_MS = "policy_candidate_start_ms"
    private const val KEY_POLICY_VERIFICATION_MS = "policy_verification_ms"
    private const val DEFAULT_RECOVERY_WINDOW_MS = 60_000L
    private const val DEFAULT_GATE_WAIT_MS = 2_500L
    private const val DEFAULT_CANDIDATE_START_MS = 12_000L
    private const val DEFAULT_VERIFICATION_MS = 28_000L

    data class ConnectionPolicy(
        val recoveryWindowMs: Long,
        val connectionGateWaitMs: Long,
        val candidateStartTimeoutMs: Long,
        val verificationTimeoutMs: Long,
    )

    private fun prefs(context: Context) = context.applicationContext
        .getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    fun policy(context: Context): ConnectionPolicy {
        val p = prefs(context)
        return ConnectionPolicy(
            recoveryWindowMs = p.getLong(KEY_POLICY_RECOVERY_WINDOW_MS, DEFAULT_RECOVERY_WINDOW_MS)
                .coerceIn(15_000L, 180_000L),
            connectionGateWaitMs = p.getLong(KEY_POLICY_GATE_WAIT_MS, DEFAULT_GATE_WAIT_MS)
                .coerceIn(500L, 8_000L),
            candidateStartTimeoutMs = p.getLong(KEY_POLICY_CANDIDATE_START_MS, DEFAULT_CANDIDATE_START_MS)
                .coerceIn(6_000L, 20_000L),
            verificationTimeoutMs = p.getLong(KEY_POLICY_VERIFICATION_MS, DEFAULT_VERIFICATION_MS)
                .coerceIn(10_000L, 45_000L),
        )
    }

    fun connectionGateWaitMs(context: Context): Long = policy(context).connectionGateWaitMs

    fun applyRemotePolicy(context: Context, config: JSONObject): Boolean {
        val remote = config.optJSONObject("connection_policy") ?: return false
        val recoverySeconds = remote.optLong("recovery_window_seconds", 60L).coerceIn(15L, 180L)
        val gateWaitMs = remote.optLong("connection_gate_wait_ms", DEFAULT_GATE_WAIT_MS).coerceIn(500L, 8_000L)
        val candidateStartSeconds = remote.optLong("candidate_start_timeout_seconds", 12L).coerceIn(6L, 20L)
        val verificationSeconds = remote.optLong("verification_timeout_seconds", 28L).coerceIn(10L, 45L)
        prefs(context).edit()
            .putLong(KEY_POLICY_RECOVERY_WINDOW_MS, recoverySeconds * 1_000L)
            .putLong(KEY_POLICY_GATE_WAIT_MS, gateWaitMs)
            .putLong(KEY_POLICY_CANDIDATE_START_MS, candidateStartSeconds * 1_000L)
            .putLong(KEY_POLICY_VERIFICATION_MS, verificationSeconds * 1_000L)
            .apply()
        return true
    }

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
                    val recoveryWindowMs = policy(app).recoveryWindowMs
                    if (lastLost > 0L && System.currentTimeMillis() - lastLost in 0..recoveryWindowMs) {
                        p.edit().putLong(KEY_RECOVERY_UNTIL, System.currentTimeMillis() + recoveryWindowMs).apply()
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
                    val recoveryWindowMs = policy(app).recoveryWindowMs
                    prefs(app).edit()
                        .putLong(KEY_LAST_LOST_AT, now)
                        .putLong(KEY_RECOVERY_UNTIL, now + recoveryWindowMs)
                        .apply()
                    BlueVpnRuntimeAudit.record(
                        app,
                        BlueVpnRuntimeAudit.Event.NETWORK_CHANGE,
                        "lost"
                    )
                    if (BlueVpnRuntimeGate.connectionActive(app)) {
                        BlueVpnRuntimeGate.markRecovering(app, "physical_network_lost")
                    }
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
