package com.v2ray.ang.bluevpn

import android.content.Context
import com.v2ray.ang.dto.SubscriptionUpdateResult
import com.v2ray.ang.dto.entities.SubscriptionCache
import com.v2ray.ang.handler.AngConfigManager
import com.v2ray.ang.handler.MmkvManager

/**
 * Thin synchronization coordinator around the stock v2rayNG subscription parser.
 *
 * BlueVPN deliberately does not rewrite User-Agent, retry with Clash/BlueVPN
 * identities, transform payloads, or parse subscriptions itself. A managed
 * subscription is fetched and decoded exactly once by AngConfigManager using
 * the SubscriptionItem that is already stored in v2rayNG MMKV.
 */
object BlueVpnSubscriptionIntelligence {

    data class RefreshOutcome(
        val configCount: Int,
        val successCount: Int,
        val failureCount: Int,
        val preservedPools: Int,
        val usedFallbacks: Int = 0,
    ) {
        fun asUpstreamResult(): SubscriptionUpdateResult = SubscriptionUpdateResult(
            configCount = configCount,
            successCount = successCount,
            failureCount = failureCount,
        )
    }

    fun refresh(
        context: Context,
        rows: List<SubscriptionCache>,
        aggressiveRepair: Boolean = false,
    ): RefreshOutcome = refreshInternal(
        context = context,
        rows = rows,
        mutationAlreadyOwned = false,
    )

    /**
     * Account/free-pool reconciliation already owns the subscription mutation
     * gate. Reuse that transaction rather than acquiring a nested gate.
     */
    internal fun refreshWithinMutation(
        context: Context,
        rows: List<SubscriptionCache>,
        aggressiveRepair: Boolean = false,
    ): RefreshOutcome = refreshInternal(
        context = context,
        rows = rows,
        mutationAlreadyOwned = true,
    )

    private fun refreshInternal(
        context: Context,
        rows: List<SubscriptionCache>,
        mutationAlreadyOwned: Boolean,
    ): RefreshOutcome {
        if (rows.isEmpty()) return RefreshOutcome(0, 0, 0, 0)

        val ownsMutation = if (mutationAlreadyOwned) {
            if (!BlueVpnRuntimeGate.subscriptionMutationActive()) {
                error("SUBSCRIPTION_MUTATION_GATE_NOT_OWNED")
            }
            false
        } else {
            BlueVpnRuntimeGate.beginSubscriptionMutation(context)
        }

        if (!mutationAlreadyOwned && !ownsMutation) {
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
            )
        }

        try {
            var totalConfigs = 0
            var successes = 0
            var failures = 0
            var preservedPools = 0

            rows.filter { it.guid.isNotBlank() && it.subscription.enabled }.forEach { row ->
                if (row.subscription.url.isBlank()) {
                    failures += 1
                    return@forEach
                }

                val beforeCount = runCatching {
                    MmkvManager.decodeServerList(row.guid).count { guid ->
                        guid.isNotBlank() && MmkvManager.decodeServerConfig(guid) != null
                    }
                }.getOrDefault(0)
                val selectedFingerprint =
                    BlueVpnProfileManager.captureSelectedFingerprint(setOf(row.guid))

                // This is intentionally the only network/parser call. It is the
                // exact update path used by pinned v2rayNG 2.2.6.
                val result = runCatching { AngConfigManager.updateConfigViaSub(row) }
                    .getOrDefault(SubscriptionUpdateResult(failureCount = 1))

                if (result.successCount > 0 && result.configCount > 0) {
                    totalConfigs += result.configCount
                    successes += result.successCount.coerceAtLeast(1)
                    val refreshed = runCatching {
                        MmkvManager.decodeServerList(row.guid).toList()
                    }.getOrDefault(emptyList())
                    BlueVpnProfileManager.restoreSelectedFingerprint(
                        selectedFingerprint,
                        refreshed,
                    )
                } else {
                    failures += result.failureCount.coerceAtLeast(1)
                    val afterCount = runCatching {
                        MmkvManager.decodeServerList(row.guid).count { guid ->
                            guid.isNotBlank() && MmkvManager.decodeServerConfig(guid) != null
                        }
                    }.getOrDefault(0)
                    if (beforeCount > 0 && afterCount > 0) preservedPools += 1
                }
            }

            // Rebuild the BlueAI pool inventory from the exact profiles that
            // v2rayNG has just imported. Selection never trusts stale GUID lists;
            // every profile is re-owned by its producing subscription and exact
            // cross-tier duplicates are quarantined before the next connect.
            BlueVpnPoolOrchestrator.reconcile(context)

            return RefreshOutcome(
                configCount = totalConfigs,
                successCount = successes,
                failureCount = failures,
                preservedPools = preservedPools,
            )
        } finally {
            if (ownsMutation) BlueVpnRuntimeGate.endSubscriptionMutation()
        }
    }
}
