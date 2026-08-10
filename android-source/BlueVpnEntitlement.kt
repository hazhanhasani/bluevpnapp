package com.v2ray.ang.bluevpn

import android.content.Context

enum class BlueVpnPlanTier {
    PREMIUM,
    FREE,
    UNAVAILABLE,
}

data class BlueVpnEntitlementSnapshot(
    val tier: BlueVpnPlanTier,
    val identity: String,
    val accountLabel: String,
    val poolLabel: String,
    val connectionNotice: String,
    val sessionMinutes: Int,
    val canConnect: Boolean,
    val poolReady: Boolean,
    val manualSelectionAllowed: Boolean,
    val timeLimited: Boolean,
    val serverGuids: List<String>,
) {
    val isPremium: Boolean get() = tier == BlueVpnPlanTier.PREMIUM
    val isFree: Boolean get() = tier == BlueVpnPlanTier.FREE
    val isUnavailable: Boolean get() = tier == BlueVpnPlanTier.UNAVAILABLE
}

/**
 * Single source of truth for every Free/Premium decision in the Android UI.
 *
 * Older builds independently read `active`, free-access preferences and MMKV
 * subscriptions from several Activities. That let stale Free UI, timers and
 * server pools survive after a Premium activation. All user-facing plan logic
 * now goes through this immutable snapshot.
 */
object BlueVpnEntitlement {
    private const val PREFS = "bluevpn_entitlement_runtime"
    private const val KEY_IDENTITY = "last_identity"
    private const val KEY_TIER = "last_tier"

    fun resolve(context: Context): BlueVpnEntitlementSnapshot {
        val account = BlueVpnAccountManager.snapshot(context)
        val free = BlueVpnAccountManager.freeAccessSnapshot(context)
        val premiumReady = account.subscriptionActive &&
            account.subscriptionUrl.trim().startsWith("http")
        val freeReady = !premiumReady && free.enabled && free.subscriptions.isNotEmpty()

        val tier = when {
            premiumReady -> BlueVpnPlanTier.PREMIUM
            freeReady -> BlueVpnPlanTier.FREE
            else -> BlueVpnPlanTier.UNAVAILABLE
        }
        val identity = when (tier) {
            BlueVpnPlanTier.PREMIUM -> "premium|${account.subscriptionUrl.trim()}"
            BlueVpnPlanTier.FREE -> "free|" + free.subscriptions
                .sortedWith(compareBy<BlueVpnFreeSubscription> { it.priority }.thenBy { it.id })
                .joinToString("|") { "${it.id}:${it.url.trim()}" }
            BlueVpnPlanTier.UNAVAILABLE -> "unavailable"
        }
        val guids = when (tier) {
            BlueVpnPlanTier.PREMIUM,
            BlueVpnPlanTier.FREE -> BlueVpnAccountManager.preferredServerGuids(context)
            BlueVpnPlanTier.UNAVAILABLE -> emptyList()
        }
        val label = account.email.ifBlank { "کاربر مهمان" }

        return when (tier) {
            BlueVpnPlanTier.PREMIUM -> BlueVpnEntitlementSnapshot(
                tier = tier,
                identity = identity,
                accountLabel = "Premium فعال • $label",
                poolLabel = "سرورهای اختصاصی اشتراک",
                connectionNotice = "اتصال Premium بدون محدودیت زمانی و فقط از سرورهای اشتراک شما برقرار می‌شود.",
                sessionMinutes = 0,
                canConnect = true,
                poolReady = guids.isNotEmpty(),
                manualSelectionAllowed = true,
                timeLimited = false,
                serverGuids = guids,
            )
            BlueVpnPlanTier.FREE -> BlueVpnEntitlementSnapshot(
                tier = tier,
                identity = identity,
                accountLabel = if (account.email.isBlank()) {
                    "مهمان • دسترسی رایگان"
                } else {
                    "${account.email} • دسترسی رایگان"
                },
                poolLabel = "سرورهای رایگان",
                connectionNotice = "هر اتصال رایگان تا ${free.sessionMinutes} دقیقه فعال است و فقط از Pool رایگان استفاده می‌کند.",
                sessionMinutes = free.sessionMinutes,
                canConnect = true,
                poolReady = guids.isNotEmpty(),
                manualSelectionAllowed = false,
                timeLimited = true,
                serverGuids = guids,
            )
            BlueVpnPlanTier.UNAVAILABLE -> BlueVpnEntitlementSnapshot(
                tier = tier,
                identity = identity,
                accountLabel = if (account.email.isBlank()) "حساب آماده نیست" else "${account.email} • بدون اشتراک فعال",
                poolLabel = "بدون سرور مجاز",
                connectionNotice = "برای اتصال، دسترسی رایگان فعال یا اشتراک Premium تهیه کنید.",
                sessionMinutes = 0,
                canConnect = false,
                poolReady = false,
                manualSelectionAllowed = false,
                timeLimited = false,
                serverGuids = emptyList(),
            )
        }
    }

    /**
     * Reconcile a plan transition once. This clears UI/AI state that belongs to
     * the previous entitlement, but never logs the user out.
     */
    fun reconcile(context: Context): BlueVpnEntitlementSnapshot {
        val app = context.applicationContext
        val current = resolve(app)
        val storage = app.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val previous = storage.getString(KEY_IDENTITY, "").orEmpty()
        val previousTier = storage.getString(KEY_TIER, "").orEmpty()
        if (previous != current.identity) {
            if (current.isPremium) {
                BlueVpnAccountManager.stopFreeSession(app, expired = false)
            }

            val tierChanged = previousTier.isNotBlank() && previousTier != current.tier.name
            val mode = BlueVpnPreferences.selectionMode(app)
            val manualServerStillAllowed = mode == BlueVpnSelectionMode.MANUAL_SERVER &&
                BlueVpnPreferences.manualServerGuid(app).let { guid ->
                    guid.isNotBlank() && current.serverGuids.contains(guid)
                }
            val manualLocationStillAllowed = mode == BlueVpnSelectionMode.MANUAL_LOCATION &&
                current.isPremium && BlueVpnPreferences.preferredLocation(app).isNotBlank()

            // A background entitlement refresh must never silently flip an explicit
            // Premium manual choice back to AUTO. Only a real tier transition, Free
            // mode, or a no-longer-valid manual target resets selection ownership.
            if (current.isFree || current.isUnavailable || tierChanged ||
                (!manualServerStillAllowed && !manualLocationStillAllowed && mode != BlueVpnSelectionMode.AUTO)
            ) {
                BlueVpnPreferences.setAutomaticSelection(app)
            }

            BlueVpnAi.onEntitlementChanged(app, current.identity)
            BlueVpnTapsellManager.onEntitlementChanged(app)
            BlueVpnLocationUtil.invalidateCache()
            storage.edit()
                .putString(KEY_IDENTITY, current.identity)
                .putString(KEY_TIER, current.tier.name)
                .commit()
        } else if (previousTier.isBlank()) {
            storage.edit().putString(KEY_TIER, current.tier.name).apply()
        }
        return current
    }

    fun candidateAllowed(
        context: Context,
        candidate: BlueVpnLocationUtil.Candidate,
    ): Boolean {
        val snapshot = resolve(context)
        if (!snapshot.canConnect || candidate.guid.isBlank()) return false
        return candidate.guid in snapshot.serverGuids &&
            BlueVpnAccountManager.candidateAllowed(
                context,
                candidate.guid,
                candidate.profile.subscriptionId,
                snapshot.serverGuids.toSet(),
            )
    }
}
