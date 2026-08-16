package com.v2ray.ang.bluevpn

import android.content.Context
import org.json.JSONObject
import java.util.Locale
import kotlin.math.max
import kotlin.math.min

/**
 * BlueVPN-native network adaptation.
 *
 * Inspired by the useful idea of per-network/per-operator tuning, but it does
 * not import MahsaNG/Mahsa-Core code, binaries, remote presets or dependencies.
 * It learns only from BlueVPN's own verified outcomes on the current privacy-
 * safe network fingerprint.
 */
object BlueVpnNativeNetworkAdaptation {
    private const val PREFS = "bluevpn_native_network_adaptation_v1"
    private const val MAX_COUNTER = 32
    private const val STALE_MS = 7L * 24L * 60L * 60L * 1000L

    private fun prefs(context: Context) =
        context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    private fun networkKey(context: Context): String =
        BlueVpnIntelligenceCore.networkFingerprint(context).id

    private fun routeTraits(context: Context, guid: String) =
        BlueVpnIrcfIntelligence.traits(context, guid)

    fun observeSuccess(context: Context, guid: String) {
        if (guid.isBlank()) return
        val traits = routeTraits(context, guid)
        if (!traits.valid) return
        val net = networkKey(context)
        val transport = traits.transport.ifBlank { "unknown" }
        val p = prefs(context)
        val prefix = "route:$net:$transport:${if (traits.fragmentAware) 1 else 0}"
        val ok = min(MAX_COUNTER, p.getInt("$prefix:ok", 0) + 1)
        val fail = max(0, p.getInt("$prefix:fail", 0) - 1)
        p.edit()
            .putInt("$prefix:ok", ok)
            .putInt("$prefix:fail", fail)
            .putLong("$prefix:at", System.currentTimeMillis())
            .apply()
    }

    fun observeFailure(context: Context, guid: String, reason: String) {
        if (guid.isBlank()) return
        val traits = routeTraits(context, guid)
        if (!traits.valid) return
        val klass = BlueVpnIntelligenceCore.classifyFailure(reason)
        val net = networkKey(context)
        val transport = traits.transport.ifBlank { "unknown" }
        val p = prefs(context)
        val prefix = "route:$net:$transport:${if (traits.fragmentAware) 1 else 0}"
        val fail = min(MAX_COUNTER, p.getInt("$prefix:fail", 0) + 1)
        val editor = p.edit()
            .putInt("$prefix:fail", fail)
            .putLong("$prefix:at", System.currentTimeMillis())

        val failureKey = "failure:$net:${klass.name}"
        editor.putInt(failureKey, min(MAX_COUNTER, p.getInt(failureKey, 0) + 1))
        editor.putLong("$failureKey:at", System.currentTimeMillis())
        editor.apply()
    }

    fun rankingAdjustment(context: Context, guid: String): Int {
        if (guid.isBlank()) return 0
        val traits = routeTraits(context, guid)
        if (!traits.valid) return -20

        val net = networkKey(context)
        val p = prefs(context)
        val now = System.currentTimeMillis()
        val transport = traits.transport.ifBlank { "unknown" }
        val prefix = "route:$net:$transport:${if (traits.fragmentAware) 1 else 0}"
        val at = p.getLong("$prefix:at", 0L)

        var score = 0
        if (at > 0L && now - at <= STALE_MS) {
            val ok = p.getInt("$prefix:ok", 0)
            val fail = p.getInt("$prefix:fail", 0)
            val samples = ok + fail
            if (samples >= 3) {
                val successRate = ok * 100 / max(1, samples)
                score += when {
                    successRate >= 85 -> 7
                    successRate >= 65 -> 3
                    successRate < 35 -> -12
                    successRate < 50 -> -6
                    else -> 0
                }
            }
        }

        val udpBlocked = freshFailureCount(
            p, net, BlueVpnIntelligenceCore.FailureClass.UDP_BLOCKED, now
        )
        val tlsFailures = freshFailureCount(
            p, net, BlueVpnIntelligenceCore.FailureClass.TLS, now
        )
        val tcpTimeouts = freshFailureCount(
            p, net, BlueVpnIntelligenceCore.FailureClass.TCP_TIMEOUT, now
        )
        val dnsFailures = freshFailureCount(
            p, net, BlueVpnIntelligenceCore.FailureClass.DNS, now
        )

        val lowerTransport = transport.lowercase(Locale.ROOT)
        val udpLike =
            lowerTransport.contains("quic") ||
            lowerTransport.contains("kcp") ||
            lowerTransport.contains("h3")

        if (udpBlocked >= 2) {
            score += if (udpLike) -18 else 4
        }

        // Fragment is never assumed to be universally better. It receives a
        // bounded preference only after the current network has shown repeated
        // TLS/TCP difficulty; route-specific verified history still dominates.
        if (traits.fragmentAware && (tlsFailures + tcpTimeouts) >= 2) {
            score += 5
        }

        // DNS failures should favor previously successful routes but must not
        // rewrite DNS settings or credentials behind the user's back.
        if (dnsFailures >= 2 && at > 0L && p.getInt("$prefix:ok", 0) >= 2) {
            score += 3
        }

        return score.coerceIn(-24, 12)
    }

    fun diagnostics(context: Context): JSONObject {
        val net = networkKey(context)
        val p = prefs(context)
        val now = System.currentTimeMillis()
        val failures = JSONObject()
        BlueVpnIntelligenceCore.FailureClass.values().forEach { klass ->
            val count = freshFailureCount(p, net, klass, now)
            if (count > 0) failures.put(klass.name, count)
        }
        return JSONObject()
            .put("network", net)
            .put("failure_signals", failures)
            .put("policy", "bluevpn-native-learned")
    }

    private fun freshFailureCount(
        p: android.content.SharedPreferences,
        net: String,
        klass: BlueVpnIntelligenceCore.FailureClass,
        now: Long,
    ): Int {
        val key = "failure:$net:${klass.name}"
        val at = p.getLong("$key:at", 0L)
        if (at <= 0L || now - at > STALE_MS) return 0
        return p.getInt(key, 0).coerceIn(0, MAX_COUNTER)
    }
}
