package com.v2ray.ang.bluevpn

import android.content.Context
import org.json.JSONArray
import org.json.JSONObject
import java.security.MessageDigest

/**
 * Privacy-safe bounded runtime audit used to correlate production lifecycle
 * failures without logging browsing data, credentials, subscription bodies or OTPs.
 */
object BlueVpnRuntimeAudit {
    private const val PREFS = "bluevpn_runtime_audit_v1"
    private const val KEY_EVENTS = "events"
    private const val MAX_EVENTS = 64

    enum class Event {
        PROCESS_START,
        SYSTEM_START_REQUEST,
        VPN_CONNECTED,
        VPN_STOP_REQUEST,
        VPN_RESTART_REQUEST,
        WARP_FOREGROUND_START,
        WARP_FOREGROUND_STOP,
        TASK_REMOVED,
        PREDICTIVE_FAILOVER,
        NETWORK_CHANGE,
        RUNTIME_FAILURE,
        RUNTIME_GATE_RECOVERY,
    }

    private fun prefs(context: Context) =
        context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    fun record(
        context: Context,
        event: Event,
        detail: String = "",
    ) {
        val arr = runCatching {
            JSONArray(prefs(context).getString(KEY_EVENTS, "[]"))
        }.getOrElse { JSONArray() }

        arr.put(
            JSONObject()
                .put("at", System.currentTimeMillis())
                .put("event", event.name)
                .put("detail", sanitize(detail)),
        )
        while (arr.length() > MAX_EVENTS) arr.remove(0)
        prefs(context).edit().putString(KEY_EVENTS, arr.toString()).apply()
    }

    fun snapshot(context: Context): JSONArray =
        runCatching {
            JSONArray(prefs(context).getString(KEY_EVENTS, "[]"))
        }.getOrElse { JSONArray() }

    private fun sanitize(raw: String): String =
        raw.replace(
            Regex("(?i)(token|authorization|password|secret|otp|license|subscription)\\s*[:=]\\s*[^\\s,;]+")
        ) { "${it.groupValues[1]}=<redacted>" }
            .replace(Regex("https?://[^\\s]+"), "<url>")
            .replace(Regex("\\b(?:\\d{1,3}\\.){3}\\d{1,3}\\b"), "<ip>")
            .take(180)
}
