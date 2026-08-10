package com.v2ray.ang.bluevpn

import android.content.Context
import com.v2ray.ang.BuildConfig
import com.v2ray.ang.dto.SubscriptionUpdateResult
import com.v2ray.ang.dto.entities.SubscriptionCache
import com.v2ray.ang.handler.AngConfigManager
import com.v2ray.ang.handler.MmkvManager
import java.security.MessageDigest

/**
 * Resilient subscription refresh policy for BlueVPN-managed sources.
 *
 * Desktop clients have taught two useful lessons here:
 *  1) subscription servers sometimes return a different format according to
 *     User-Agent, so one hard-coded identity is unnecessarily brittle;
 *  2) a failed refresh must never destroy the last-known-good node pool.
 *
 * v2rayNG 2.2.6 already exposes SubscriptionItem.userAgent and its importer
 * only replaces a subscription server list after at least one valid profile was
 * parsed. This coordinator builds on those guarantees: it remembers the UA that
 * last worked for each source, tries a small bounded compatibility ladder when
 * needed, restores semantic selection after GUID churn, and treats the whole
 * refresh as one logical attempt rather than one failure per fallback UA.
 */
object BlueVpnSubscriptionIntelligence {
    private const val PREFS = "bluevpn_subscription_intelligence"
    private const val UA_PREFIX = "ua:"
    private const val SUCCESS_AT_PREFIX = "ok_at:"
    private const val CONFIG_COUNT_PREFIX = "count:"
    private const val FAILURE_STREAK_PREFIX = "fail_streak:"
    private const val MAX_FALLBACKS_NORMAL = 2
    private const val MAX_FALLBACKS_REPAIR = 4

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
     * A stable UA avoids leaking Android model/build data. Prefer the UA that
     * proved compatible with this exact source; otherwise start with v2rayNG
     * because the pinned importer understands that family of subscription
     * formats natively.
     */
    fun recommendedUserAgent(context: Context, url: String): String {
        val remembered = prefs(context).getString(UA_PREFIX + urlKey(url), "").orEmpty().trim()
        return remembered.ifBlank { "v2rayNG" }
    }

    fun lastGoodConfigCount(context: Context, url: String): Int =
        prefs(context).getInt(CONFIG_COUNT_PREFIX + urlKey(url), 0).coerceAtLeast(0)

    fun lastSuccessAt(context: Context, url: String): Long =
        prefs(context).getLong(SUCCESS_AT_PREFIX + urlKey(url), 0L).coerceAtLeast(0L)

    fun failureStreak(context: Context, url: String): Int =
        prefs(context).getInt(FAILURE_STREAK_PREFIX + urlKey(url), 0).coerceAtLeast(0)

    /**
     * Refresh only the rows owned by the current BlueVPN entitlement. The
     * caller decides which rows are in scope so free, expired and previous
     * Premium pools can never be mixed by an "update all subscriptions" call.
     */
    fun refresh(
        context: Context,
        rows: List<SubscriptionCache>,
        aggressiveRepair: Boolean = false,
    ): RefreshOutcome {
        if (rows.isEmpty()) return RefreshOutcome(0, 0, 0, 0, 0)

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
            val beforeCount = beforeGuids.size
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
                // Restore the caller/provider choice when no compatibility UA
                // worked. More importantly, keep the old physical pool. The
                // pinned importer only swaps rows after parsing valid configs;
                // this explicit check guards future upstream behavior changes.
                row.subscription.userAgent = originalUa
                MmkvManager.encodeSubscription(row.guid, row.subscription)
                val afterCount = runCatching { MmkvManager.decodeServerList(row.guid).size }
                    .getOrDefault(0)
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
    }

    private fun compatibilityUserAgents(
        context: Context,
        url: String,
        configured: String?,
    ): List<String> = buildList {
        val remembered = recommendedUserAgent(context, url)
        listOf(
            remembered,
            configured.orEmpty().trim(),
            "v2rayNG",
            "sing-box",
            "Clash.Meta",
            "BlueVPN/${BuildConfig.VERSION_NAME}",
        ).forEach { ua ->
            if (ua.isNotBlank() && ua !in this) add(ua)
        }
    }

    private fun rememberSuccess(
        context: Context,
        url: String,
        userAgent: String,
        configCount: Int,
    ) {
        val key = urlKey(url)
        prefs(context).edit()
            .putString(UA_PREFIX + key, userAgent)
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
