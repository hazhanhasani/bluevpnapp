package com.v2ray.ang.bluevpn

import android.content.Context

/**
 * Persistent, process-safe source of truth for connection ownership.
 *
 * Runtime callbacks, network recovery and delayed failover work may finish after
 * the user pressed Disconnect. They must never interpret a running/stale core as
 * permission to reconnect. Every explicit start receives a new generation and
 * every explicit stop revokes all older work.
 */
object BlueVpnConnectionIntent {
    private const val PREFS = "bluevpn_connection_intent"
    private const val KEY_DESIRED = "desired_connected"
    private const val KEY_GENERATION = "generation"

    private fun prefs(context: Context) = context.applicationContext
        .getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    @Synchronized
    fun requestConnect(context: Context): Long {
        val p = prefs(context)
        val generation = p.getLong(KEY_GENERATION, 0L) + 1L
        p.edit().putBoolean(KEY_DESIRED, true).putLong(KEY_GENERATION, generation).commit()
        return generation
    }

    @Synchronized
    fun requestDisconnect(context: Context): Long {
        val p = prefs(context)
        val generation = p.getLong(KEY_GENERATION, 0L) + 1L
        p.edit().putBoolean(KEY_DESIRED, false).putLong(KEY_GENERATION, generation).commit()
        return generation
    }

    fun isConnectionDesired(context: Context): Boolean =
        prefs(context).getBoolean(KEY_DESIRED, false)

    fun isCurrent(context: Context, generation: Long): Boolean {
        val p = prefs(context)
        return p.getBoolean(KEY_DESIRED, false) && p.getLong(KEY_GENERATION, 0L) == generation
    }
}
