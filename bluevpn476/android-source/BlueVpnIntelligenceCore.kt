package com.v2ray.ang.bluevpn

import android.content.Context
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.os.Build
import android.telephony.TelephonyManager
import org.json.JSONArray
import org.json.JSONObject
import java.security.MessageDigest
import java.util.Locale
import kotlin.math.abs
import kotlin.math.max
import kotlin.math.min

/**
 * BlueAI local control plane.
 *
 * The AI layer never owns destructive actions. It produces bounded scores,
 * classifications and recommendations. VPN lifecycle/state-machine code remains
 * the final authority.
 */
object BlueVpnIntelligenceCore {
    private const val PREFS = "bluevpn_intelligence_core_v1"
    private const val EVENT_KEY = "events"
    private const val SHADOW_KEY = "shadow"
    private const val MAX_EVENTS = 96
    private const val MAX_SHADOW = 48
    private const val QUARANTINE_MAX_MS = 30 * 60_000L
    private const val HISTORY_TTL_MS = 7 * 24 * 60 * 60_000L

    enum class FailureClass {
        DNS, TCP_TIMEOUT, UDP_BLOCKED, TLS, AUTH, EXIT_IRAN, EXIT_VALIDATION,
        SOCKS, AETHER, XRAY, TUN, NO_INTERNET, NETWORK_CHANGED, BACKEND,
        SUBSCRIPTION, PROVISIONING, PAYMENT, PROCESS_KILLED, UNKNOWN
    }

    data class NetworkFingerprint(
        val id: String,
        val transport: String,
        val ipv4: Boolean,
        val ipv6: Boolean,
        val validated: Boolean,
        val metered: Boolean,
        val roaming: Boolean,
        val operatorHash: String,
    )

    data class DecisionEvidence(
        val scoreAdjustment: Int,
        val confidence: Int,
        val failureClass: FailureClass?,
        val quarantined: Boolean,
        val reason: String,
    )

    data class HealthSignal(
        val score: Int,
        val degraded: Boolean,
        val shouldWarmFailover: Boolean,
        val reason: String,
    )

    private fun prefs(context: Context) =
        context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    private fun sha(value: String): String = MessageDigest.getInstance("SHA-256")
        .digest(value.toByteArray(Charsets.UTF_8))
        .joinToString("") { "%02x".format(it) }

    fun networkFingerprint(context: Context): NetworkFingerprint {
        val app = context.applicationContext
        val cm = app.getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
        val active = cm.activeNetwork
        val caps = active?.let { cm.getNetworkCapabilities(it) }
        val transport = when {
            caps?.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) == true -> "wifi"
            caps?.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR) == true -> "cellular"
            caps?.hasTransport(NetworkCapabilities.TRANSPORT_ETHERNET) == true -> "ethernet"
            else -> "unknown"
        }
        val tm = app.getSystemService(Context.TELEPHONY_SERVICE) as? TelephonyManager
        val rawOperator = runCatching { tm?.networkOperator.orEmpty() }.getOrDefault("")
        val roaming = runCatching { tm?.isNetworkRoaming == true }.getOrDefault(false)
        val operatorHash = if (rawOperator.isBlank()) "" else sha("op:$rawOperator").take(12)
        val linkAddresses = runCatching {
            active?.let { cm.getLinkProperties(it) }?.linkAddresses.orEmpty()
        }.getOrDefault(emptyList())
        val ipv4 = linkAddresses.any { it.address.hostAddress?.contains(".") == true }
        val ipv6 = linkAddresses.any { it.address.hostAddress?.contains(":") == true }
        val validated = caps?.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED) == true
        val metered = cm.isActiveNetworkMetered
        val raw = listOf(transport, ipv4, ipv6, validated, metered, roaming, operatorHash).joinToString("|")
        return NetworkFingerprint(
            id = sha(raw).take(20),
            transport = transport,
            ipv4 = ipv4,
            ipv6 = ipv6,
            validated = validated,
            metered = metered,
            roaming = roaming,
            operatorHash = operatorHash,
        )
    }

    fun classifyFailure(raw: String?): FailureClass {
        val s = raw.orEmpty().uppercase(Locale.US)
        return when {
            "EXIT_IRAN" in s || "COUNTRY: IR" in s -> FailureClass.EXIT_IRAN
            "EXIT_VALIDATION" in s || "TRACE" in s -> FailureClass.EXIT_VALIDATION
            "DNS" in s -> FailureClass.DNS
            "UDP_BLOCKED" in s || "QUIC" in s -> FailureClass.UDP_BLOCKED
            "TCP_TIMEOUT" in s || "CONNECT TIMEOUT" in s -> FailureClass.TCP_TIMEOUT
            "TLS" in s || "CERTIFICATE" in s || "REALITY" in s -> FailureClass.TLS
            "AUTH" in s || "REJECTED" in s || "FORBIDDEN" in s -> FailureClass.AUTH
            "SOCKS" in s -> FailureClass.SOCKS
            "AETHER" in s || "MASQUE" in s || "WIREGUARD" in s -> FailureClass.AETHER
            "XRAY" in s || "CORE" in s -> FailureClass.XRAY
            "TUN" in s || "VPNSERVICE" in s -> FailureClass.TUN
            "NO_INTERNET" in s || "INTERNET" in s -> FailureClass.NO_INTERNET
            "NETWORK_CHANGED" in s || "NETWORK LOST" in s -> FailureClass.NETWORK_CHANGED
            "SUBSCRIPTION" in s || "ENTITLEMENT" in s -> FailureClass.SUBSCRIPTION
            "PROVISION" in s || "PANEL" in s -> FailureClass.PROVISIONING
            "PAYMENT" in s || "BLUEPAY" in s -> FailureClass.PAYMENT
            "PROCESS_KILLED" in s || "BACKGROUND_KILL" in s -> FailureClass.PROCESS_KILLED
            "BACKEND" in s || "HTTP 5" in s -> FailureClass.BACKEND
            else -> FailureClass.UNKNOWN
        }
    }

    private fun routeKey(context: Context, guid: String): String {
        val fp = BlueVpnProfileManager.fingerprintGuid(guid)
            ?: sha("guid:$guid").take(40)
        return "route:${networkFingerprint(context).id}:$fp"
    }

    private fun routeState(context: Context, guid: String): JSONObject {
        if (guid.isBlank()) return JSONObject()
        return runCatching {
            JSONObject(prefs(context).getString(routeKey(context, guid), "").orEmpty())
        }.getOrElse { JSONObject() }
    }

    private fun saveRouteState(context: Context, guid: String, json: JSONObject) {
        if (guid.isBlank()) return
        prefs(context).edit().putString(routeKey(context, guid), json.toString()).apply()
    }

    fun recordRouteOutcome(
        context: Context,
        guid: String,
        success: Boolean,
        latencyMs: Long = 0L,
        jitterMs: Long = 0L,
        packetLossX100: Int = 0,
        reason: String = "",
        exitCountry: String = "",
    ) {
        if (guid.isBlank()) return
        val old = routeState(context, guid)
        val now = System.currentTimeMillis()
        val ok = old.optInt("ok", 0)
        val fail = old.optInt("fail", 0)
        val streak = if (success) 0 else (old.optInt("streak", 0) + 1).coerceAtMost(12)
        val klass = if (success) null else classifyFailure(reason)
        val quarantine = if (success) 0L else now + quarantineMs(klass ?: FailureClass.UNKNOWN, streak)
        old.put("ok", if (success) (ok + 1).coerceAtMost(64) else ok)
        old.put("fail", if (!success) (fail + 1).coerceAtMost(64) else fail)
        old.put("streak", streak)
        old.put("at", now)
        old.put("lat", ewma(old.optLong("lat", 0L), latencyMs, 35))
        old.put("jit", ewma(old.optLong("jit", 0L), jitterMs, 30))
        old.put("loss", packetLossX100.coerceIn(0, 10_000))
        old.put("failure", klass?.name.orEmpty())
        old.put("reason", sanitize(reason))
        old.put("exit", exitCountry.take(8).uppercase(Locale.US))
        old.put("quarantine", quarantine)
        saveRouteState(context, guid, old)
        appendEvent(context, "route_outcome", guid, success, latencyMs, jitterMs, packetLossX100, klass, exitCountry)
    }

    private fun ewma(old: Long, sample: Long, weight: Int): Long {
        if (sample <= 0L) return old
        if (old <= 0L) return sample
        return (old * (100 - weight) + sample * weight) / 100
    }

    private fun quarantineMs(type: FailureClass, streak: Int): Long {
        val base = when (type) {
            FailureClass.EXIT_IRAN -> 30 * 60_000L
            FailureClass.AUTH -> 15 * 60_000L
            FailureClass.TLS -> 5 * 60_000L
            FailureClass.DNS -> 60_000L
            FailureClass.TCP_TIMEOUT, FailureClass.UDP_BLOCKED -> 90_000L
            FailureClass.AETHER, FailureClass.XRAY, FailureClass.TUN -> 2 * 60_000L
            FailureClass.NETWORK_CHANGED -> 0L
            else -> 45_000L
        }
        return min(QUARANTINE_MAX_MS, base * max(1, streak).coerceAtMost(4))
    }

    fun routeEvidence(context: Context, guid: String): DecisionEvidence {
        val state = routeState(context, guid)
        if (state.length() == 0) return DecisionEvidence(0, 35, null, false, "بدون سابقه محلی")
        val now = System.currentTimeMillis()
        val at = state.optLong("at", 0L)
        if (at <= 0L || now - at > HISTORY_TTL_MS) return DecisionEvidence(0, 30, null, false, "سابقه منقضی")
        val ok = state.optInt("ok", 0)
        val fail = state.optInt("fail", 0)
        val total = max(1, ok + fail)
        val rate = ok * 100 / total
        val lat = state.optLong("lat", 0L)
        val jit = state.optLong("jit", 0L)
        val loss = state.optInt("loss", 0)
        val failure = state.optString("failure").takeIf { it.isNotBlank() }?.let {
            runCatching { FailureClass.valueOf(it) }.getOrNull()
        }
        val quarantined = state.optLong("quarantine", 0L) > now
        var adjustment = when {
            rate >= 95 && total >= 4 -> 12
            rate >= 80 -> 7
            rate < 40 && total >= 3 -> -12
            else -> 0
        }
        adjustment += when (lat) {
            in 1..90 -> 7
            in 91..180 -> 4
            in 401..800 -> -5
            in 801..Long.MAX_VALUE -> -10
            else -> 0
        }
        adjustment += when {
            jit in 1..30 -> 3
            jit > 150 -> -5
            else -> 0
        }
        if (loss > 1500) adjustment -= 8
        if (quarantined) adjustment -= 35
        if (failure == FailureClass.EXIT_IRAN) adjustment -= 22
        val confidence = (35 + min(55, total * 7)).coerceIn(35, 95)
        val reason = buildList {
            add("موفقیت $rate%")
            if (lat > 0) add("RTT ${lat}ms")
            if (jit > 0) add("Jitter ${jit}ms")
            if (loss > 0) add("Loss ${loss / 100.0}%")
            if (quarantined) add("قرنطینه")
            failure?.let { add(it.name) }
        }.joinToString(" • ")
        return DecisionEvidence(adjustment.coerceIn(-45, 25), confidence, failure, quarantined, reason)
    }

    fun observeHealth(
        context: Context,
        guid: String,
        pingMs: Long,
        jitterMs: Long,
        packetLossX100: Int,
    ): HealthSignal {
        val score = (
            100
                - (pingMs / 12).toInt().coerceAtMost(35)
                - (jitterMs / 8).toInt().coerceAtMost(25)
                - (packetLossX100 / 200).coerceAtMost(40)
        ).coerceIn(0, 100)
        val fingerprint = networkFingerprint(context).id
        val healthKey = "health:$fingerprint:$guid"
        val streakKey = "health_bad_streak:$fingerprint:$guid"
        val previous = prefs(context).getInt(healthKey, score)
        val drop = previous - score
        val badSample =
            score < 45 ||
            (previous > 0 && drop >= 25) ||
            packetLossX100 >= 2500
        val previousStreak = prefs(context).getInt(streakKey, 0)
        val badStreak = if (badSample) (previousStreak + 1).coerceAtMost(8) else 0
        prefs(context).edit()
            .putInt(healthKey, score)
            .putInt(streakKey, badStreak)
            .apply()

        // A single noisy RTT/loss probe must never restart a working VPN.
        // Require repeated evidence and give a newly-connected tunnel time to warm up.
        val connectedAt = BlueVpnPreferences.connectedAt(context)
        val sessionAgeMs = if (connectedAt > 0L) {
            (System.currentTimeMillis() - connectedAt).coerceAtLeast(0L)
        } else {
            0L
        }
        val degraded = badStreak >= 3
        val warm = BlueVpnAi.predictiveFailoverEnabled(context) &&
            badStreak >= 3 &&
            sessionAgeMs >= 25_000L &&
            (score < 60 || jitterMs >= 150 || packetLossX100 >= 1500)

        if (badSample) {
            appendEvent(
                context,
                if (degraded) "health_degradation_confirmed" else "health_degradation_sample",
                guid,
                !degraded,
                pingMs,
                jitterMs,
                packetLossX100,
                null,
                "",
            )
        }
        return HealthSignal(
            score = score,
            degraded = degraded,
            shouldWarmFailover = warm,
            reason = "health=$score previous=$previous streak=$badStreak age_ms=$sessionAgeMs ping=$pingMs jitter=$jitterMs loss=$packetLossX100",
        )
    }

    fun recordShadowDecision(
        context: Context,
        actualGuid: String,
        shadowGuid: String,
        actualScore: Int,
        shadowScore: Int,
        reason: String,
    ) {
        if (!BlueVpnAi.shadowModeEnabled(context)) return
        val arr = readArray(prefs(context).getString(SHADOW_KEY, "[]"))
        arr.put(
            JSONObject()
                .put("at", System.currentTimeMillis())
                .put("network", networkFingerprint(context).id)
                .put("actual", hashGuid(actualGuid))
                .put("shadow", hashGuid(shadowGuid))
                .put("actual_score", actualScore)
                .put("shadow_score", shadowScore)
                .put("reason", sanitize(reason)),
        )
        trimArray(arr, MAX_SHADOW)
        prefs(context).edit().putString(SHADOW_KEY, arr.toString()).apply()
    }

    fun shadowSummary(context: Context): JSONObject {
        val arr = readArray(prefs(context).getString(SHADOW_KEY, "[]"))
        var different = 0
        for (i in 0 until arr.length()) {
            val row = arr.optJSONObject(i) ?: continue
            if (row.optString("actual") != row.optString("shadow")) different++
        }
        return JSONObject()
            .put("samples", arr.length())
            .put("different_decisions", different)
            .put("network", networkFingerprint(context).id)
    }

    private fun appendEvent(
        context: Context,
        type: String,
        guid: String,
        success: Boolean,
        latencyMs: Long,
        jitterMs: Long,
        packetLossX100: Int,
        failure: FailureClass?,
        exitCountry: String,
    ) {
        val arr = readArray(prefs(context).getString(EVENT_KEY, "[]"))
        arr.put(
            JSONObject()
                .put("at", System.currentTimeMillis())
                .put("type", type.take(40))
                .put("network", networkFingerprint(context).id)
                .put("route", hashGuid(guid))
                .put("success", success)
                .put("latency_ms", latencyMs.coerceIn(0, 60_000))
                .put("jitter_ms", jitterMs.coerceIn(0, 60_000))
                .put("loss_x100", packetLossX100.coerceIn(0, 10_000))
                .put("failure", failure?.name.orEmpty())
                .put("exit_country", exitCountry.take(8).uppercase(Locale.US)),
        )
        trimArray(arr, MAX_EVENTS)
        prefs(context).edit().putString(EVENT_KEY, arr.toString()).apply()
    }

    fun claimPredictiveFailover(context: Context, cooldownMs: Long = 120_000L): Boolean {
        if (!BlueVpnAi.predictiveFailoverEnabled(context)) return false
        val key = "failover_claim:${networkFingerprint(context).id}"
        val now = System.currentTimeMillis()
        val last = prefs(context).getLong(key, 0L)
        if (last > 0L && now - last < cooldownMs) return false
        prefs(context).edit().putLong(key, now).apply()
        return true
    }

    fun beginDecision(
        context: Context,
        guid: String,
        score: Int,
        confidence: Int,
        reason: String,
    ) {
        if (guid.isBlank()) return
        prefs(context).edit().putString(
            "pending_decision",
            JSONObject()
                .put("guid", hashGuid(guid))
                .put("raw_guid_hash", sha("pending:$guid").take(24))
                .put("score", score.coerceIn(0, 100))
                .put("confidence", confidence.coerceIn(0, 100))
                .put("reason", sanitize(reason))
                .put("network", networkFingerprint(context).id)
                .put("at", System.currentTimeMillis())
                .toString(),
        ).apply()
    }

    fun resolveDecision(
        context: Context,
        guid: String,
        success: Boolean,
        latencyMs: Long = 0L,
        failureReason: String = "",
    ) {
        val raw = prefs(context).getString("pending_decision", "").orEmpty()
        if (raw.isBlank()) return
        val pending = runCatching { JSONObject(raw) }.getOrNull() ?: return
        val expected = sha("pending:$guid").take(24)
        if (pending.optString("raw_guid_hash") != expected) return
        val age = System.currentTimeMillis() - pending.optLong("at", 0L)
        if (age !in 0..10 * 60_000L) {
            prefs(context).edit().remove("pending_decision").apply()
            return
        }
        val predicted = pending.optInt("score", 50)
        val confidence = pending.optInt("confidence", 50)
        val reward = if (success) {
            (100 - (latencyMs / 10L).toInt().coerceAtMost(45)).coerceIn(40, 100)
        } else {
            0
        }
        val calibrationError = abs(predicted - reward)
        val key = "calibration:${networkFingerprint(context).id}"
        val old = prefs(context).getInt(key, 25)
        val updated = ((old * 70) + (calibrationError * 30)) / 100
        prefs(context).edit()
            .putInt(key, updated.coerceIn(0, 100))
            .remove("pending_decision")
            .apply()
        appendEvent(
            context = context,
            type = "decision_outcome",
            guid = guid,
            success = success,
            latencyMs = latencyMs,
            jitterMs = 0L,
            packetLossX100 = 0,
            failure = if (success) null else classifyFailure(failureReason),
            exitCountry = "",
        )
    }

    fun calibratedConfidence(context: Context, base: Int): Int {
        val error = prefs(context).getInt("calibration:${networkFingerprint(context).id}", 25)
        return (base - error / 3).coerceIn(20, 98)
    }

    fun diagnostics(context: Context): JSONObject = JSONObject()
        .put("network", JSONObject().apply {
            val n = networkFingerprint(context)
            put("id", n.id)
            put("transport", n.transport)
            put("ipv4", n.ipv4)
            put("ipv6", n.ipv6)
            put("validated", n.validated)
            put("metered", n.metered)
            put("roaming", n.roaming)
            put("operator_hash", n.operatorHash)
        })
        .put("shadow", shadowSummary(context))
        .put("events", readArray(prefs(context).getString(EVENT_KEY, "[]")))
        .put("runtime_audit", BlueVpnRuntimeAudit.snapshot(context))
        .put("native_network_adaptation", BlueVpnNativeNetworkAdaptation.diagnostics(context))

    private fun readArray(raw: String?): JSONArray =
        runCatching { JSONArray(raw ?: "[]") }.getOrElse { JSONArray() }

    private fun trimArray(arr: JSONArray, max: Int) {
        while (arr.length() > max) arr.remove(0)
    }

    private fun hashGuid(guid: String): String =
        if (guid.isBlank()) "" else sha("route:$guid").take(16)

    private fun sanitize(raw: String): String =
        raw.replace(Regex("(?i)(token|authorization|password|secret|otp|license)\\s*[:=]\\s*[^\\s,;]+")) {
            "${it.groupValues[1]}=<redacted>"
        }.replace(Regex("https?://[^\\s]+"), "<url>").take(180)
}
