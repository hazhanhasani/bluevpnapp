package com.v2ray.ang.bluevpn

import android.content.Context
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.os.Build
import android.telephony.SubscriptionManager
import android.telephony.TelephonyManager
import com.v2ray.ang.BuildConfig
import com.v2ray.ang.handler.MmkvManager
import com.v2ray.ang.handler.SettingsManager
import org.json.JSONArray
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.InetSocketAddress
import java.net.Proxy
import java.net.URL
import java.security.MessageDigest
import java.util.Calendar
import java.util.Locale
import java.util.UUID
import kotlin.math.max

object BlueVpnAi {
    private const val PREFS = "bluevpn_ai"
    private const val KEY_ENABLED = "enabled"
    private const val KEY_RECOMMENDATIONS = "recommendations"
    private const val KEY_LAST_SYNC = "last_sync"
    private const val KEY_SESSION = "active_session"
    private const val KEY_PERSONAL = "personal_routes"
    private const val KEY_LAST_HEARTBEAT = "last_heartbeat"
    private const val KEY_LAST_PROBE_AT = "last_probe_at"
    private const val KEY_LAST_PROBE_SOURCE = "last_probe_source"
    private const val KEY_LAST_PROBE_LATENCY = "last_probe_latency"
    private const val SYNC_INTERVAL = 5 * 60 * 1000L
    private const val HEARTBEAT_INTERVAL = 60 * 1000L
    private const val PROBE_CACHE_MAX_AGE_MS = 125 * 1000L

    data class NetworkSnapshot(
        val operator: String,
        val networkType: String,
    )

    data class TunnelVerification(
        val latencyMs: Long,
        val source: String,
    )

    private data class ProbeTarget(
        val url: String,
        val source: String,
    )

    fun hasVpnTransport(context: Context): Boolean {
        val connectivity = context.getSystemService(
            Context.CONNECTIVITY_SERVICE
        ) as ConnectivityManager
        return connectivity.allNetworks.any { network ->
            val capabilities = connectivity.getNetworkCapabilities(network)
                ?: return@any false
            capabilities.hasTransport(NetworkCapabilities.TRANSPORT_VPN) &&
                capabilities.hasCapability(
                    NetworkCapabilities.NET_CAPABILITY_INTERNET
                )
        }
    }

    fun hasActiveSession(context: Context): Boolean =
        prefs(context).getString(KEY_SESSION, "").orEmpty().isNotBlank()

    /**
     * Returns a recently verified tunnel proof without waking radios or
     * creating new sockets. The Android VPN transport is still checked on
     * every call, so a stopped VPN can never reuse an old proof.
     */
    fun recentTunnelVerification(
        context: Context,
        maxAgeMs: Long = PROBE_CACHE_MAX_AGE_MS,
    ): TunnelVerification? {
        if (!hasVpnTransport(context)) return null
        val storage = prefs(context)
        val verifiedAt = storage.getLong(KEY_LAST_PROBE_AT, 0L)
        val age = System.currentTimeMillis() - verifiedAt
        if (verifiedAt <= 0L || age !in 0..maxAgeMs) return null
        val source = storage.getString(KEY_LAST_PROBE_SOURCE, "").orEmpty()
        val latency = storage.getLong(KEY_LAST_PROBE_LATENCY, 0L)
        if (source.isBlank() || latency <= 0L) return null
        return TunnelVerification(latency, source)
    }

    /**
     * Performs a real request through the local Xray HTTP proxy. Targets are
     * tried sequentially and the first success wins; unlike the old version,
     * this does not launch four concurrent sockets every few seconds.
     */
    fun verifyTunnel(context: Context): TunnelVerification? {
        if (!hasVpnTransport(context)) return null
        val httpPort = SettingsManager.getHttpPort()
        if (httpPort !in 1..65535) return null

        val apiHealth = BlueVpnAccountManager.apiBaseUrl()
            .takeIf { it.startsWith("http") }
            ?.let { "${it.trimEnd('/')}/health" }
        val targets = buildList {
            add(
                ProbeTarget(
                    "http://cp.cloudflare.com/generate_204",
                    "cloudflare-204",
                )
            )
            add(
                ProbeTarget(
                    "http://connectivitycheck.gstatic.com/generate_204",
                    "google-204",
                )
            )
            if (!apiHealth.isNullOrBlank()) {
                add(ProbeTarget(apiHealth, "bluevpn-health"))
            }
            add(
                ProbeTarget(
                    "http://1.1.1.1/cdn-cgi/trace",
                    "cloudflare-trace",
                )
            )
        }

        for (target in targets) {
            val result = requestTunnelProof(target, httpPort) ?: continue
            prefs(context).edit()
                .putLong(KEY_LAST_PROBE_AT, System.currentTimeMillis())
                .putString(KEY_LAST_PROBE_SOURCE, result.source)
                .putLong(KEY_LAST_PROBE_LATENCY, result.latencyMs)
                .apply()
            return result
        }
        return null
    }

    private fun requestTunnelProof(
        target: ProbeTarget,
        httpPort: Int,
    ): TunnelVerification? = runCatching {
        val proxy = Proxy(
            Proxy.Type.HTTP,
            InetSocketAddress("127.0.0.1", httpPort),
        )
        val connection =
            URL(target.url).openConnection(proxy) as HttpURLConnection
        val startedAt = android.os.SystemClock.elapsedRealtime()
        try {
            connection.instanceFollowRedirects = false
            connection.connectTimeout = 2_100
            connection.readTimeout = 2_100
            connection.requestMethod = "GET"
            connection.useCaches = false
            connection.setRequestProperty("Connection", "close")
            connection.setRequestProperty(
                "User-Agent",
                "BlueVPN/${BuildConfig.VERSION_NAME}",
            )

            val code = connection.responseCode
            val body = if (code == 200) {
                runCatching {
                    connection.inputStream.bufferedReader().use {
                        it.readText().take(4096)
                    }
                }.getOrDefault("")
            } else {
                ""
            }
            val valid = when (target.source) {
                "bluevpn-health" ->
                    code == 200 &&
                        body.contains("bluevpn-platform", ignoreCase = true)
                "cloudflare-204", "google-204" -> code == 204
                "cloudflare-trace" ->
                    code == 200 &&
                        body.lineSequence().any { it.startsWith("ip=") }
                else -> false
            }
            if (!valid) null
            else TunnelVerification(
                latencyMs = (
                    android.os.SystemClock.elapsedRealtime() - startedAt
                ).coerceAtLeast(1L),
                source = target.source,
            )
        } finally {
            connection.disconnect()
        }
    }.getOrNull()

    private fun prefs(context: Context) =
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    fun enabled(context: Context): Boolean =
        prefs(context).getBoolean(KEY_ENABLED, true)

    fun setEnabled(context: Context, enabled: Boolean) {
        prefs(context).edit().putBoolean(KEY_ENABLED, enabled).apply()
    }

    private fun physicalCapabilities(
        connectivity: ConnectivityManager,
    ): NetworkCapabilities? {
        val active = connectivity.getNetworkCapabilities(
            connectivity.activeNetwork
        )
        if (
            active != null &&
            !active.hasTransport(NetworkCapabilities.TRANSPORT_VPN) &&
            (
                active.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) ||
                active.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR) ||
                active.hasTransport(NetworkCapabilities.TRANSPORT_ETHERNET)
            )
        ) {
            return active
        }

        return connectivity.allNetworks
            .mapNotNull { connectivity.getNetworkCapabilities(it) }
            .filter {
                !it.hasTransport(NetworkCapabilities.TRANSPORT_VPN) &&
                    (
                        it.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) ||
                        it.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR) ||
                        it.hasTransport(NetworkCapabilities.TRANSPORT_ETHERNET)
                    )
            }
            .sortedByDescending {
                if (it.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED)) 1 else 0
            }
            .firstOrNull()
    }

    private fun activeDataTelephony(
        context: Context,
    ): TelephonyManager {
        val base = context.getSystemService(
            Context.TELEPHONY_SERVICE
        ) as TelephonyManager

        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.N) {
            return base
        }

        val subscriptionId = runCatching {
            SubscriptionManager.getActiveDataSubscriptionId()
        }.getOrDefault(SubscriptionManager.INVALID_SUBSCRIPTION_ID)

        return if (
            subscriptionId != SubscriptionManager.INVALID_SUBSCRIPTION_ID
        ) {
            runCatching {
                base.createForSubscriptionId(subscriptionId)
            }.getOrDefault(base)
        } else {
            base
        }
    }

    private fun canonicalOperator(
        networkName: String,
        simName: String,
        numeric: String,
    ): String {
        val primary = networkName.trim()
        val secondary = simName.trim()
        val joined = "$primary|$secondary"
            .lowercase(Locale.ROOT)
            .replace("‌", "")

        return when {
            joined.contains("irancell") ||
                joined.contains("mtn") ||
                joined.contains("ایرانسل") -> "ایرانسل"

            joined.contains("hamrah") ||
                joined.contains("mci") ||
                joined.contains("همراه اول") ||
                joined.contains("همراه‌اول") -> "همراه اول"

            joined.contains("rightel") ||
                joined.contains("rightel") ||
                joined.contains("رایتل") -> "رایتل"

            joined.contains("shatel") ||
                joined.contains("شاتل") -> "شاتل موبایل"

            joined.contains("samantel") ||
                joined.contains("saman tel") ||
                joined.contains("سامانتل") -> "سامانتل"

            joined.contains("aptel") ||
                joined.contains("آپتل") -> "آپتل"

            joined.contains("taliya") ||
                joined.contains("تالیا") -> "تالیا"

            numeric == "43235" -> "ایرانسل"
            numeric == "43211" || numeric == "43212" -> "همراه اول"
            numeric == "43220" -> "رایتل"
            primary.isNotBlank() -> primary.take(80)
            secondary.isNotBlank() -> secondary.take(80)
            else -> "ناشناخته"
        }
    }

    fun network(context: Context): NetworkSnapshot {
        val connectivity = context.getSystemService(
            Context.CONNECTIVITY_SERVICE
        ) as ConnectivityManager
        val capabilities = physicalCapabilities(connectivity)
        val networkType = when {
            capabilities?.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) == true -> "wifi"
            capabilities?.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR) == true -> "mobile"
            capabilities?.hasTransport(NetworkCapabilities.TRANSPORT_ETHERNET) == true -> "ethernet"
            else -> "unknown"
        }

        if (networkType == "wifi") {
            return NetworkSnapshot("Wi-Fi", networkType)
        }

        if (networkType != "mobile") {
            return NetworkSnapshot("ناشناخته", networkType)
        }

        val telephony = activeDataTelephony(context)
        val networkName = runCatching {
            telephony.networkOperatorName.orEmpty()
        }.getOrDefault("")
        val simName = runCatching {
            telephony.simOperatorName.orEmpty()
        }.getOrDefault("")
        val numeric = runCatching {
            telephony.networkOperator.orEmpty()
        }.getOrDefault("")

        return NetworkSnapshot(
            canonicalOperator(networkName, simName, numeric),
            networkType,
        )
    }

    private fun digest(value: String): String =
        MessageDigest.getInstance("SHA-256")
            .digest(value.toByteArray())
            .joinToString("") { "%02x".format(it) }
            .take(40)

    fun fingerprint(candidate: BlueVpnLocationUtil.Candidate): String =
        digest(
            listOf(
                candidate.profile.server,
                candidate.profile.remarks,
                candidate.location.key,
            ).joinToString("|")
        )

    fun fingerprintGuid(guid: String): String {
        val profile = MmkvManager.decodeServerConfig(guid)
            ?: return digest(guid)
        val location = BlueVpnLocationUtil.detect(
            profile.remarks,
            profile.server,
        )
        return digest(
            listOf(
                profile.server,
                profile.remarks,
                location.key,
            ).joinToString("|")
        )
    }

    fun cloudScore(
        context: Context,
        candidate: BlueVpnLocationUtil.Candidate,
    ): Int {
        val raw = prefs(context).getString(
            KEY_RECOMMENDATIONS,
            "{}",
        ).orEmpty()
        return runCatching {
            val objectValue = JSONObject(raw)
            val exact = objectValue.optInt(
                fingerprint(candidate),
                -1,
            )
            if (exact >= 0) exact
            else objectValue.optInt(
                "location:${candidate.location.key}",
                50,
            )
        }.getOrDefault(50)
    }

    fun personalScore(
        context: Context,
        candidate: BlueVpnLocationUtil.Candidate,
    ): Int {
        val key = fingerprint(candidate)
        val raw = prefs(context).getString(KEY_PERSONAL, "{}").orEmpty()
        return runCatching {
            val row = JSONObject(raw).optJSONObject(key)
                ?: return@runCatching 50
            val duration = row.optLong("duration", 0L)
            val success = row.optInt("success", 0)
            val failure = row.optInt("failure", 0)
            val reliability = success * 100 / max(1, success + failure)
            val loyalty = (duration / 600L).coerceAtMost(20L).toInt()
            (reliability * 8 / 10 + loyalty).coerceIn(0, 100)
        }.getOrDefault(50)
    }

    fun combinedScore(
        context: Context,
        candidate: BlueVpnLocationUtil.Candidate,
        baseScore: Int,
    ): Int {
        if (!enabled(context)) return baseScore
        val cloud = cloudScore(context, candidate)
        val personal = personalScore(context, candidate)
        return (
            baseScore * 50 +
                cloud * 30 +
                personal * 20
            ) / 100
    }

    fun priorityBoost(
        context: Context,
        candidate: BlueVpnLocationUtil.Candidate,
    ): Int {
        if (!enabled(context)) return 0
        val cloud = cloudScore(context, candidate)
        val personal = personalScore(context, candidate)
        return (cloud - 50) * 16 + (personal - 50) * 11
    }

    fun refreshRecommendations(
        context: Context,
        force: Boolean = false,
    ): Result<Int> = runCatching {
        if (!enabled(context)) return@runCatching 0
        val storage = prefs(context)
        if (!force && System.currentTimeMillis() - storage.getLong(KEY_LAST_SYNC, 0L) < SYNC_INTERVAL) {
            return@runCatching JSONObject(
                storage.getString(KEY_RECOMMENDATIONS, "{}") ?: "{}"
            ).length()
        }

        // Local intelligence is authoritative and works for both Free and Premium.
        // The cloud model enriches it when an authenticated account is available;
        // a server/network failure can never disable automatic selection.
        val candidates = BlueVpnLocationUtil.cachedCandidates(context).ifEmpty {
            BlueVpnLocationUtil.allCandidates(context, forceRefresh = force)
        }
        val scores = JSONObject()
        val locationScores = mutableMapOf<String, MutableList<Int>>()
        candidates
            .filter { BlueVpnEntitlement.candidateAllowed(context, it) }
            .forEach { candidate ->
                val latency = when {
                    candidate.delay in 1..60 -> 92
                    candidate.delay in 61..120 -> 82
                    candidate.delay in 121..220 -> 68
                    candidate.delay > 220 -> 48
                    candidate.delay == 0L -> 55
                    else -> 20
                }
                val personal = personalScore(context, candidate)
                val freshness = BlueVpnPreferences.successFreshnessScore(context, candidate.guid)
                val failedPenalty = if (BlueVpnPreferences.failedRecently(context, candidate.guid)) 24 else 0
                val local = (latency * 70 / 100 + personal * 20 / 100 + freshness.coerceIn(0, 34) * 10 / 34 - failedPenalty)
                    .coerceIn(0, 100)
                scores.put(fingerprint(candidate), local)
                locationScores.getOrPut(candidate.location.key) { mutableListOf() }.add(local)
            }

        if (BlueVpnAccountManager.hasSession(context)) {
            val network = network(context)
            BlueVpnAccountManager.aiRecommendations(
                context,
                network.operator,
                network.networkType,
                BlueVpnExperience.mode(context).key,
            ).getOrNull()?.optJSONArray("recommendations")?.let { rows ->
                for (index in 0 until rows.length()) {
                    val row = rows.optJSONObject(index) ?: continue
                    val key = row.optString("config_key")
                    val cloud = row.optInt("score", 50).coerceIn(0, 100)
                    if (key.isNotBlank()) {
                        val local = scores.optInt(key, 50)
                        scores.put(key, (local * 70 + cloud * 30) / 100)
                    }
                    val location = row.optString("location_key")
                    if (location.isNotBlank()) {
                        locationScores.getOrPut(location) { mutableListOf() }.add(cloud)
                    }
                }
            }
        }
        locationScores.forEach { (key, values) ->
            scores.put("location:$key", values.maxOrNull() ?: 50)
        }
        storage.edit()
            .putString(KEY_RECOMMENDATIONS, scores.toString())
            .putLong(KEY_LAST_SYNC, System.currentTimeMillis())
            .apply()
        scores.length()
    }

    fun startSession(
        context: Context,
        candidate: BlueVpnLocationUtil.Candidate,
        pingMs: Long,
        healthScore: Int,
    ) {
        if (!enabled(context)) return
        val network = network(context)
        val session = JSONObject()
            .put("session_id", UUID.randomUUID().toString())
            .put("heartbeat_seq", 0L)
            .put("started_at", System.currentTimeMillis())
            .put("config_key", fingerprint(candidate))
            .put("guid", candidate.guid)
            .put("location_key", candidate.location.key)
            .put("location_title", candidate.location.title)
            .put("operator", network.operator)
            .put("network_type", network.networkType)
            .put("ping_ms", pingMs.coerceAtLeast(0L))
            .put("health_score", healthScore)
            .put("mode", BlueVpnExperience.mode(context).key)
        prefs(context).edit()
            .putString(KEY_SESSION, session.toString())
            .remove(KEY_LAST_HEARTBEAT)
            .remove(KEY_LAST_PROBE_AT)
            .remove(KEY_LAST_PROBE_SOURCE)
            .remove(KEY_LAST_PROBE_LATENCY)
            .apply()
    }

    fun finishSession(
        context: Context,
        reason: String,
        success: Boolean = true,
        downloadBytes: Long = 0L,
        uploadBytes: Long = 0L,
    ): Result<JSONObject> = runCatching {
        val storage = prefs(context)
        val raw = storage.getString(KEY_SESSION, "").orEmpty()
        if (raw.isBlank()) return@runCatching JSONObject().put("accepted", false)
        val session = JSONObject(raw)
        storage.edit()
            .remove(KEY_SESSION)
            .remove(KEY_LAST_HEARTBEAT)
            .remove(KEY_LAST_PROBE_AT)
            .remove(KEY_LAST_PROBE_SOURCE)
            .remove(KEY_LAST_PROBE_LATENCY)
            .apply()
        val duration = (
            System.currentTimeMillis() -
                session.optLong("started_at", System.currentTimeMillis())
            ).coerceAtLeast(0L) / 1000L
        updatePersonal(
            context,
            session.optString("config_key"),
            duration,
            success,
        )
        val payload = basePayload(context)
            .put("consent", enabled(context))
            .put("event_type", "session")
            .put("live_state", "disconnected")
            .put("connected", false)
            .put("success", success)
            .put("duration_seconds", duration)
            .put("download_bytes", downloadBytes.coerceAtLeast(0L))
            .put("upload_bytes", uploadBytes.coerceAtLeast(0L))
            .put("failure_reason", if (success) "" else reason.take(400))
        session.keys().forEach { key -> payload.put(key, session.opt(key)) }
        BlueVpnAccountManager.postAiEvent(context, payload).getOrThrow()
    }


    fun heartbeat(
        context: Context,
        pingMs: Long,
        healthScore: Int,
        downloadBytes: Long,
        uploadBytes: Long,
    ): Result<JSONObject> = runCatching {
        if (!enabled(context)) {
            return@runCatching JSONObject().put("accepted", false)
        }

        val storage = prefs(context)
        val raw = storage.getString(KEY_SESSION, "").orEmpty()
        if (raw.isBlank()) {
            return@runCatching JSONObject().put("accepted", false)
        }

        val now = System.currentTimeMillis()
        val last = storage.getLong(KEY_LAST_HEARTBEAT, 0L)
        if (now - last < HEARTBEAT_INTERVAL) {
            return@runCatching JSONObject()
                .put("accepted", false)
                .put("reason", "throttled")
        }
        storage.edit().putLong(KEY_LAST_HEARTBEAT, now).apply()

        val session = JSONObject(raw)
        val verification = recentTunnelVerification(context)
            ?: verifyTunnel(context)
            ?: return@runCatching JSONObject()
                .put("accepted", false)
                .put("live", false)
                .put("reason", "tunnel_not_verified")
        val probeAgeMs = (
            now - storage.getLong(KEY_LAST_PROBE_AT, now)
        ).coerceAtLeast(0L)

        val network = network(context)
        val sequence = session.optLong("heartbeat_seq", 0L) + 1L
        val previousDownload = session.optLong(
            "last_download_bytes",
            0L,
        )
        val previousUpload = session.optLong(
            "last_upload_bytes",
            0L,
        )
        val safeDownload = downloadBytes.coerceAtLeast(0L)
        val safeUpload = uploadBytes.coerceAtLeast(0L)
        val trafficActive =
            safeDownload > previousDownload ||
                safeUpload > previousUpload

        session.put("heartbeat_seq", sequence)
        session.put("last_download_bytes", safeDownload)
        session.put("last_upload_bytes", safeUpload)
        session.put("last_verified_at", now)
        storage.edit()
            .putString(KEY_SESSION, session.toString())
            .apply()

        val payload = basePayload(context)
            .put("consent", true)
            .put("event_type", "heartbeat")
            .put("success", true)
            .put("connected", true)
            .put("tunnel_running", true)
            .put("vpn_transport", true)
            .put("internet_verified", true)
            .put("verification_source", verification.source)
            .put("probe_age_ms", probeAgeMs)
            .put("heartbeat_seq", sequence)
            .put("traffic_active", trafficActive)
            .put(
                "duration_seconds",
                ((now - session.optLong("started_at", now)) / 1000L)
                    .coerceAtLeast(0L),
            )
            .put(
                "ping_ms",
                verification.latencyMs
                    .takeIf { it > 0L }
                    ?: pingMs.coerceAtLeast(0L),
            )
            .put("health_score", healthScore.coerceIn(0, 100))
            .put("download_bytes", safeDownload)
            .put("upload_bytes", safeUpload)

        session.keys().forEach { key ->
            payload.put(key, session.opt(key))
        }
        payload.put("operator", network.operator)
        payload.put("network_type", network.networkType)
        BlueVpnAccountManager.postAiEvent(
            context,
            payload,
        ).getOrThrow()
    }


    fun recordFailure(
        context: Context,
        guid: String,
        reason: String,
    ): Result<JSONObject> = runCatching {
        if (!enabled(context)) return@runCatching JSONObject().put("accepted", false)
        val profile = MmkvManager.decodeServerConfig(guid)
        val location = profile?.let {
            BlueVpnLocationUtil.detect(it.remarks, it.server)
        } ?: BlueVpnLocation("unknown", "نامشخص", "🌐")
        val network = network(context)
        updatePersonal(context, fingerprintGuid(guid), 0L, false)
        BlueVpnAccountManager.postAiEvent(
            context,
            basePayload(context)
                .put("consent", true)
                .put("event_type", "failure")
                .put("success", false)
                .put("config_key", fingerprintGuid(guid))
                .put("location_key", location.key)
                .put("location_title", location.title)
                .put("operator", network.operator)
                .put("network_type", network.networkType)
                .put("mode", BlueVpnExperience.mode(context).key)
                .put("failure_reason", reason.take(400)),
        ).getOrThrow()
    }

    private fun basePayload(context: Context): JSONObject =
        JSONObject()
            .put("device_id", BlueVpnAccountManager.deviceId(context))
            .put("device_model", BlueVpnAccountManager.deviceName())
            .put("android_version", Build.VERSION.RELEASE ?: "")
            .put("app_version", BuildConfig.VERSION_NAME)
            .put("hour_bucket", Calendar.getInstance().get(Calendar.HOUR_OF_DAY))

    private fun updatePersonal(
        context: Context,
        key: String,
        duration: Long,
        success: Boolean,
    ) {
        if (key.isBlank()) return
        val storage = prefs(context)
        val root = runCatching {
            JSONObject(storage.getString(KEY_PERSONAL, "{}") ?: "{}")
        }.getOrElse { JSONObject() }
        val row = root.optJSONObject(key) ?: JSONObject()
        row.put("duration", row.optLong("duration", 0L) + duration)
        row.put("success", row.optInt("success", 0) + if (success) 1 else 0)
        row.put("failure", row.optInt("failure", 0) + if (success) 0 else 1)
        root.put(key, row)
        storage.edit().putString(KEY_PERSONAL, root.toString()).apply()
    }

    fun localSummary(context: Context): String {
        if (!enabled(context)) return "انتخاب‌گر هوشمند غیرفعال است"
        val network = network(context)
        val learned = runCatching {
            JSONObject(prefs(context).getString(KEY_RECOMMENDATIONS, "{}") ?: "{}").length()
        }.getOrDefault(0)
        val decision = BlueVpnSmartSelector.lastSummary(context)
        return if (learned > 0) {
            "$decision\n${network.operator} • ${network.networkType} • $learned سیگنال"
        } else {
            "انتخاب‌گر هوشمند آماده • ${network.operator} • ${network.networkType}"
        }
    }

    fun onEntitlementChanged(context: Context, identity: String) {
        val storage = prefs(context)
        val previous = storage.getString("entitlement_identity", "").orEmpty()
        if (previous == identity) return
        storage.edit()
            .putString("entitlement_identity", identity)
            .remove(KEY_RECOMMENDATIONS)
            .remove(KEY_SESSION)
            .remove(KEY_LAST_SYNC)
            .remove(KEY_LAST_HEARTBEAT)
            .remove(KEY_LAST_PROBE_AT)
            .remove(KEY_LAST_PROBE_SOURCE)
            .remove(KEY_LAST_PROBE_LATENCY)
            .apply()
        BlueVpnSmartSelector.clear(context)
    }

    fun cachedTopRoutes(context: Context): List<Pair<String, Int>> {
        val raw = prefs(context).getString(KEY_RECOMMENDATIONS, "{}").orEmpty()
        return runCatching {
            val obj = JSONObject(raw)
            val rows = mutableListOf<Pair<String, Int>>()
            obj.keys().forEach { key ->
                if (key.startsWith("location:")) {
                    rows += key.removePrefix("location:") to obj.optInt(key, 50)
                }
            }
            rows.sortedByDescending { it.second }.take(8)
        }.getOrDefault(emptyList())
    }

    fun clearLearning(context: Context) {
        prefs(context).edit()
            .remove(KEY_RECOMMENDATIONS)
            .remove(KEY_PERSONAL)
            .remove(KEY_SESSION)
            .remove(KEY_LAST_SYNC)
            .remove(KEY_LAST_HEARTBEAT)
            .apply()
    }
}
