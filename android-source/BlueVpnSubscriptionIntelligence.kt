package com.v2ray.ang.bluevpn

import android.content.Context
import com.v2ray.ang.BuildConfig
import com.v2ray.ang.dto.SubscriptionUpdateResult
import com.v2ray.ang.dto.entities.SubscriptionCache
import com.v2ray.ang.handler.AngConfigManager
import com.v2ray.ang.handler.MmkvManager
import java.security.MessageDigest

/**
 * Subscription refresh coordinator that keeps v2rayNG as the compatibility
 * authority.
 *
 * The first request deliberately uses a null User-Agent. In pinned v2rayNG
 * 2.2.6 that means HttpUtil sends its native "v2rayNG/<version>" identity.
 * Earlier BlueVPN builds forced the shorter literal "v2rayNG" and then tried
 * several desktop UAs synchronously. Some providers return different payloads
 * for those identities and four sequential retries can keep the locations page
 * loading for a very long time.
 *
 * Rule now:
 *  1) native upstream v2rayNG behavior first;
 *  2) a small compatibility ladder only when the pool is actually empty;
 *  3) never delete a last-known-good pool when parsing fails;
 *  4) preserve semantic selection across GUID churn.
 */
object BlueVpnSubscriptionIntelligence {
    private const val PREFS = "bluevpn_subscription_intelligence"
    private const val UA_PREFIX = "ua:"
    private const val SUCCESS_AT_PREFIX = "ok_at:"
    private const val CONFIG_COUNT_PREFIX = "count:"
    private const val FAILURE_STREAK_PREFIX = "fail_streak:"
    private const val UPSTREAM_DEFAULT_UA = "__v2rayng_default__"

    // A healthy refresh gets exactly the upstream-compatible attempt. Repair is
    // bounded so a bad provider cannot block the locations screen for minutes.
    private const val MAX_FALLBACKS_NORMAL = 1
    private const val MAX_FALLBACKS_REPAIR = 3

    data class RefreshOutcome(
        val configCount: Int,
        val successCount: Int,
        val failureCount: Int,
        val preservedPools: Int,
        val usedFallbacks: Int,
    ) {
        fun asUpstreamResult(): SubscriptionUpdateResult = SubscriptionUpdateResult(
            configCount = configCount,
            successCount = successCount,
            failureCount = failureCount,
        )
    }

    private fun prefs(context: Context) = context.applicationContext
        .getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    private fun urlKey(url: String): String = MessageDigest.getInstance("SHA-256")
        .digest(url.trim().toByteArray(Charsets.UTF_8))
        .joinToString("") { "%02x".format(it) }
        .take(32)

    /**
     * Return only a previously proven explicit UA. null intentionally means
     * "let v2rayNG choose its own native User-Agent" and is the safest default.
     */
    fun recommendedUserAgent(context: Context, url: String): String? {
        val remembered = prefs(context).getString(UA_PREFIX + urlKey(url), "").orEmpty().trim()
        return remembered
            .takeUnless { it.isBlank() || it == UPSTREAM_DEFAULT_UA }
    }

    fun lastGoodConfigCount(context: Context, url: String): Int =
        prefs(context).getInt(CONFIG_COUNT_PREFIX + urlKey(url), 0).coerceAtLeast(0)

    fun lastSuccessAt(context: Context, url: String): Long =
        prefs(context).getLong(SUCCESS_AT_PREFIX + urlKey(url), 0L).coerceAtLeast(0L)

    fun failureStreak(context: Context, url: String): Int =
        prefs(context).getInt(FAILURE_STREAK_PREFIX + urlKey(url), 0).coerceAtLeast(0)

    fun refresh(
        context: Context,
        rows: List<SubscriptionCache>,
        aggressiveRepair: Boolean = false,
    ): RefreshOutcome {
        if (rows.isEmpty()) return RefreshOutcome(0, 0, 0, 0, 0)

        // Never let v2rayNG replace MMKV profile GUIDs while a candidate is
        // starting or while Xray owns the current profile.  The current pool is
        // already usable, so a blocked refresh is a preserved/deferred refresh,
        // not a provider failure.
        if (!BlueVpnRuntimeGate.beginSubscriptionMutation(context)) {
            val currentCount = rows.sumOf { row ->
                runCatching {
                    MmkvManager.decodeServerList(row.guid).count { guid ->
                        guid.isNotBlank() && MmkvManager.decodeServerConfig(guid) != null
                    }
                }.getOrDefault(0)
            }
            return RefreshOutcome(
                configCount = currentCount,
                successCount = 0,
                failureCount = 0,
                preservedPools = rows.size,
                usedFallbacks = 0,
            )
        }

        try {
        var totalConfigs = 0
        var successes = 0
        var failures = 0
        var preservedPools = 0
        var fallbackUses = 0

        rows.filter { it.guid.isNotBlank() && it.subscription.enabled }.forEach { row ->
            val url = row.subscription.url.trim()
            if (url.isBlank()) {
                failures += 1
                return@forEach
            }

            val beforeGuids = runCatching { MmkvManager.decodeServerList(row.guid).toList() }
                .getOrDefault(emptyList())
            val beforeCount = beforeGuids.count { guid ->
                guid.isNotBlank() && MmkvManager.decodeServerConfig(guid) != null
            }
            val selectedFingerprint = BlueVpnProfileManager.captureSelectedFingerprint(setOf(row.guid))
            val originalUa = row.subscription.userAgent
            val agents = compatibilityUserAgents(context, url, originalUa)
            val maxAttempts = if (aggressiveRepair || beforeCount == 0) {
                MAX_FALLBACKS_REPAIR
            } else {
                MAX_FALLBACKS_NORMAL
            }

            var success = false
            var usedAttempts = 0
            for (ua in agents.take(maxAttempts)) {
                usedAttempts += 1
                row.subscription.userAgent = ua
                MmkvManager.encodeSubscription(row.guid, row.subscription)

                // Keep parsing/fetching behavior inside pinned v2rayNG. It parses
                // the complete payload before replacing the old subscription
                // server list, so a bad response does not erase a healthy pool.
                val result = runCatching { AngConfigManager.updateConfigViaSub(row) }
                    .getOrDefault(SubscriptionUpdateResult(failureCount = 1))
                if (result.successCount > 0 && result.configCount > 0) {
                    success = true
                    totalConfigs += result.configCount
                    successes += 1
                    if (usedAttempts > 1) fallbackUses += 1
                    rememberSuccess(context, url, ua, result.configCount)
                    val refreshed = runCatching { MmkvManager.decodeServerList(row.guid).toList() }
                        .getOrDefault(emptyList())
                    BlueVpnProfileManager.restoreSelectedFingerprint(selectedFingerprint, refreshed)
                    break
                }
            }

            if (!success) {
                failures += 1
                rememberFailure(context, url)
                row.subscription.userAgent = originalUa
                MmkvManager.encodeSubscription(row.guid, row.subscription)
                val afterCount = runCatching {
                    MmkvManager.decodeServerList(row.guid).count { guid ->
                        guid.isNotBlank() && MmkvManager.decodeServerConfig(guid) != null
                    }
                }.getOrDefault(0)
                if (beforeCount > 0 && afterCount > 0) {
                    preservedPools += 1
                }
            }
        }

        return RefreshOutcome(
            configCount = totalConfigs,
            successCount = successes,
            failureCount = failures,
            preservedPools = preservedPools,
            usedFallbacks = fallbackUses,
        )
        } finally {
            BlueVpnRuntimeGate.endSubscriptionMutation()
        }
    }

    private fun compatibilityUserAgents(
        context: Context,
        url: String,
        configured: String?,
    ): List<String?> {
        val remembered = recommendedUserAgent(context, url)
        val values = mutableListOf<String?>(null) // native v2rayNG/<version> first
        listOf(
            configured?.trim()?.takeIf { it.isNotBlank() },
            remembered?.trim()?.takeIf { it.isNotBlank() },
            "v2rayNG",
            "sing-box",
            "Clash.Meta",
            "BlueVPN/${BuildConfig.VERSION_NAME}",
        ).forEach { ua ->
            if (ua != null && ua !in values) values.add(ua)
        }
        return values
    }

    private fun rememberSuccess(
        context: Context,
        url: String,
        userAgent: String?,
        configCount: Int,
    ) {
        val key = urlKey(url)
        prefs(context).edit()
            .putString(UA_PREFIX + key, userAgent ?: UPSTREAM_DEFAULT_UA)
            .putLong(SUCCESS_AT_PREFIX + key, System.currentTimeMillis())
            .putInt(CONFIG_COUNT_PREFIX + key, configCount.coerceAtLeast(0))
            .putInt(FAILURE_STREAK_PREFIX + key, 0)
            .apply()
    }

    private fun rememberFailure(context: Context, url: String) {
        val key = urlKey(url)
        val storage = prefs(context)
        val next = (storage.getInt(FAILURE_STREAK_PREFIX + key, 0) + 1).coerceAtMost(1000)
        storage.edit().putInt(FAILURE_STREAK_PREFIX + key, next).apply()
    }
}
