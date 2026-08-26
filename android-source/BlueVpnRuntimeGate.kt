package com.v2ray.ang.bluevpn

import android.content.Context
import android.os.Process
import android.os.SystemClock

/**
 * Serializes BlueVPN subscription mutation and VPN connection lifecycles.
 *
 * v2rayNG rewrites subscription server GUIDs during import.  That is safe while
 * idle, but it must never happen while a candidate is starting or while Xray is
 * using the currently selected profile.  This gate is intentionally owned by
 * BlueVPN rather than upstream so WordPress/account refreshes, Free-pool repair
 * and UI-driven connect operations all share one source of truth.
 */
object BlueVpnRuntimeGate {
    enum class ConnectionPhase { IDLE, PREPARING, CONNECTING, VERIFYING, CONNECTED, RECOVERING, FAILED }

    private const val PREFS = "bluevpn_runtime_gate"
    private const val KEY_CONNECTION_ACTIVE = "connection_active"
    private const val KEY_CONNECTION_PHASE = "connection_phase"
    private const val KEY_CONNECTION_STARTED_AT = "connection_started_at"
    private const val KEY_CONNECTION_OWNER_PID = "connection_owner_pid"

    private val monitor = Object()
    @Volatile private var connectionActiveMemory = false
    @Volatile private var subscriptionMutationActive = false

    private fun prefs(context: Context) = context.applicationContext
        .getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    fun connectionActive(context: Context): Boolean {
        if (connectionActiveMemory) return true
        val p = prefs(context)
        if (!p.getBoolean(KEY_CONNECTION_ACTIVE, false)) return false
        val ownerPid = p.getInt(KEY_CONNECTION_OWNER_PID, -1)
        if (ownerPid == Process.myPid()) return true

        // A persisted gate belongs to the process that owned the VPN lifecycle.
        // If that process died (crash, force-stop, package update), keeping the
        // boolean forever blocks subscription refresh even though the tunnel is
        // already gone. Recover only cross-process/stale ownership; an active
        // current-process connection still stays authoritative.
        p.edit()
            .putBoolean(KEY_CONNECTION_ACTIVE, false)
            .remove(KEY_CONNECTION_STARTED_AT)
            .remove(KEY_CONNECTION_OWNER_PID)
            .putString(KEY_CONNECTION_PHASE, ConnectionPhase.IDLE.name)
            .apply()
        runCatching {
            BlueVpnRuntimeAudit.record(
                context.applicationContext,
                BlueVpnRuntimeAudit.Event.RUNTIME_GATE_RECOVERY,
                "stale_owner_pid",
            )
        }
        return false
    }

    fun subscriptionMutationActive(): Boolean = subscriptionMutationActive

    fun connectionPhase(context: Context): ConnectionPhase {
        if (!connectionActive(context)) return ConnectionPhase.IDLE
        return runCatching {
            ConnectionPhase.valueOf(prefs(context).getString(KEY_CONNECTION_PHASE, ConnectionPhase.CONNECTED.name).orEmpty())
        }.getOrDefault(ConnectionPhase.CONNECTED)
    }

    private fun setPhase(context: Context, phase: ConnectionPhase, detail: String = "") {
        prefs(context).edit().putString(KEY_CONNECTION_PHASE, phase.name).apply()
        runCatching {
            BlueVpnRuntimeAudit.record(
                context.applicationContext,
                BlueVpnRuntimeAudit.Event.CONNECTION_PHASE,
                if (detail.isBlank()) phase.name else "${phase.name}:$detail",
            )
        }
    }

    fun markConnecting(context: Context) = setPhase(context, ConnectionPhase.CONNECTING)
    fun markVerifying(context: Context) = setPhase(context, ConnectionPhase.VERIFYING)
    fun markRecovering(context: Context, detail: String = "network_change") = setPhase(context, ConnectionPhase.RECOVERING, detail)
    fun markFailed(context: Context, detail: String = "runtime") = setPhase(context, ConnectionPhase.FAILED, detail)

    /**
     * Acquire connection ownership.  A subscription import that already started
     * gets a short grace period to finish; after that the caller can retry from
     * the UI instead of racing against MMKV replacement.
     */
    fun beginConnection(context: Context, timeoutMs: Long = BlueVpnNetworkRecoveryManager.connectionGateWaitMs(context)): Boolean {
        val deadline = SystemClock.elapsedRealtime() + timeoutMs.coerceIn(0L, 8_000L)
        synchronized(monitor) {
            while (subscriptionMutationActive) {
                val remaining = deadline - SystemClock.elapsedRealtime()
                if (remaining <= 0L) return false
                try {
                    monitor.wait(remaining.coerceAtMost(120L))
                } catch (_: InterruptedException) {
                    Thread.currentThread().interrupt()
                    return false
                }
            }
            connectionActiveMemory = true
            prefs(context).edit()
                .putBoolean(KEY_CONNECTION_ACTIVE, true)
                .putLong(KEY_CONNECTION_STARTED_AT, System.currentTimeMillis())
                .putInt(KEY_CONNECTION_OWNER_PID, Process.myPid())
                .putString(KEY_CONNECTION_PHASE, ConnectionPhase.PREPARING.name)
                .apply()
            setPhase(context, ConnectionPhase.PREPARING)
            return true
        }
    }

    /** Mark an already-running/recovered VPN session as owning the profile pool. */
    fun markConnectionActive(context: Context) {
        synchronized(monitor) {
            connectionActiveMemory = true
            prefs(context).edit()
                .putBoolean(KEY_CONNECTION_ACTIVE, true)
                .putLong(KEY_CONNECTION_STARTED_AT, System.currentTimeMillis())
                .putInt(KEY_CONNECTION_OWNER_PID, Process.myPid())
                .putString(KEY_CONNECTION_PHASE, ConnectionPhase.CONNECTED.name)
                .apply()
            setPhase(context, ConnectionPhase.CONNECTED)
            monitor.notifyAll()
        }
    }

    fun endConnection(context: Context) {
        synchronized(monitor) {
            connectionActiveMemory = false
            prefs(context).edit()
                .putBoolean(KEY_CONNECTION_ACTIVE, false)
                .remove(KEY_CONNECTION_STARTED_AT)
                .remove(KEY_CONNECTION_OWNER_PID)
                .putString(KEY_CONNECTION_PHASE, ConnectionPhase.IDLE.name)
                .apply()
            setPhase(context, ConnectionPhase.IDLE)
            monitor.notifyAll()
        }
    }

    /**
     * Subscription mutation is fail-fast while a tunnel owns the pool.  Callers
     * keep the current last-known-good pool and may retry once the tunnel stops.
     */
    fun beginSubscriptionMutation(context: Context): Boolean {
        synchronized(monitor) {
            if (connectionActive(context) || subscriptionMutationActive) return false
            subscriptionMutationActive = true
            return true
        }
    }

    fun endSubscriptionMutation() {
        synchronized(monitor) {
            subscriptionMutationActive = false
            monitor.notifyAll()
        }
    }
}
