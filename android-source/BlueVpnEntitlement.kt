package com.v2ray.ang.bluevpn

import android.content.Context
import android.os.Looper

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
 * IMPORTANT: resolving the exact server GUID pool touches v2rayNG MMKV and may
 * decode many rows.  UI rendering must use [resolveUi], while workers/connection
 * preparation use [resolve].  This keeps the main thread completely out of the
 * subscription database and prevents ANRs on the locations/home screens.
 */
object BlueVpnEntitlement {
    private const val PREFS = "bluevpn_entitlement_runtime"
    private const val KEY_IDENTITY = "last_identity"
    private const val KEY_TIER = "last_tier"

    @Volatile
    private var lastDeepSnapshot: BlueVpnEntitlementSnapshot? = null

    private data class Base(
        val tier: BlueVpnPlanTier,
        val identity: String,
        val account: BlueVpnAccountSnapshot,
        val free: BlueVpnFreeAccessSnapshot,
    )

    private fun base(context: Context): Base {
        val account = BlueVpnAccountManager.snapshot(context)
        val free = BlueVpnAccountManager.freeAccessSnapshot(context)
        val premiumEntitled = BlueVpnAccountManager.premiumEntitlementActive(context) &&
            account.subscriptionActive
        val premiumReady = premiumEntitled &&
            account.subscriptionUrl.trim().startsWith("http")
        val freeConfigKnown = BlueVpnAccountManager.freeAccessConfigured(context)
        // Free is the default plan whenever there is no live Premium entitlement.
        // On first launch mobile config may not be cached yet, so present FREE
        // immediately and let the existing preparation pipeline fetch the pool.
        // Once the server has explicitly disabled Free access, honor that state.
        val freePlanEligible = !premiumEntitled && (!freeConfigKnown || free.enabled)
        val tier = when {
            premiumReady -> BlueVpnPlanTier.PREMIUM
            freePlanEligible -> BlueVpnPlanTier.FREE
            else -> BlueVpnPlanTier.UNAVAILABLE
        }
        val identity = when (tier) {
            BlueVpnPlanTier.PREMIUM -> "premium|${account.poolIdentity.ifBlank { account.subscriptionUrl.trim() }}"
            BlueVpnPlanTier.FREE -> "free|" + free.subscriptions
                .sortedWith(compareBy<BlueVpnFreeSubscription> { it.priority }.thenBy { it.id })
                .joinToString("|") { "${it.id}:${it.url.trim()}" }
            BlueVpnPlanTier.UNAVAILABLE -> "unavailable"
        }
        return Base(tier, identity, account, free)
    }

    private fun build(base: Base, guids: List<String>): BlueVpnEntitlementSnapshot {
        val account = base.account
        val free = base.free
        val label = account.email.ifBlank { "کاربر مهمان" }
        return when (base.tier) {
            BlueVpnPlanTier.PREMIUM -> BlueVpnEntitlementSnapshot(
                tier = base.tier,
                identity = base.identity,
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
                tier = base.tier,
                identity = base.identity,
                accountLabel = if (account.email.isBlank()) {
                    "پلن رایگان • مهمان"
                } else {
                    "پلن رایگان • ${account.email}"
                },
                poolLabel = "Cloudflare WARP",
                connectionNotice = "هر اتصال رایگان تا ${free.sessionMinutes} دقیقه فعال است؛ مسیر اصلی Cloudflare WARP است و Pool رایگان فقط پشتیبان باقی می‌ماند.",
                sessionMinutes = free.sessionMinutes,
                // A FREE entitlement may be visible before the first pool fetch.
                // Connect then enters prepareFreeAccess() instead of incorrectly
                // treating the user as having no plan at all.
                canConnect = true,
                poolReady = guids.isNotEmpty(),
                manualSelectionAllowed = false,
                timeLimited = true,
                serverGuids = guids,
            )
            BlueVpnPlanTier.UNAVAILABLE -> BlueVpnEntitlementSnapshot(
                tier = base.tier,
                identity = base.identity,
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
     * Full entitlement resolution. This may scan/decode v2rayNG MMKV and must be
     * called from Dispatchers.IO/Default or another worker thread.
     */
    fun resolve(context: Context): BlueVpnEntitlementSnapshot {
        if (Looper.myLooper() == Looper.getMainLooper()) {
            return resolveUi(context)
        }
        val base = base(context.applicationContext)
        val guids = when (base.tier) {
            BlueVpnPlanTier.PREMIUM,
            BlueVpnPlanTier.FREE -> BlueVpnAccountManager.preferredServerGuids(context.applicationContext)
            BlueVpnPlanTier.UNAVAILABLE -> emptyList()
        }
        return build(base, guids).also { lastDeepSnapshot = it }
    }

    /**
     * Main-thread safe entitlement snapshot. It never enumerates subscriptions,
     * server lists or server configs. If a deep worker snapshot for the same
     * identity exists, its already-resolved GUIDs are reused without touching MMKV.
     */
    fun resolveUi(context: Context): BlueVpnEntitlementSnapshot {
        val base = base(context.applicationContext)
        val cached = lastDeepSnapshot
            ?.takeIf { it.identity == base.identity && it.tier == base.tier }
        return build(base, cached?.serverGuids.orEmpty())
    }

    /**
     * Reconcile a plan transition once. This is deliberately the deep/background
     * variant because manual-server validity depends on the exact entitlement pool.
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
                .apply()
        } else if (previousTier.isBlank()) {
            storage.edit().putString(KEY_TIER, current.tier.name).apply()
        }
        return current
    }

    fun candidateAllowed(
        context: Context,
        candidate: BlueVpnLocationUtil.Candidate,
    ): Boolean = candidateAllowed(context, candidate, resolve(context))

    fun candidateAllowed(
        context: Context,
        candidate: BlueVpnLocationUtil.Candidate,
        snapshot: BlueVpnEntitlementSnapshot,
    ): Boolean {
        if (!snapshot.canConnect || candidate.guid.isBlank()) return false
        val allowed = snapshot.serverGuids.toSet()
        return candidate.guid in allowed &&
            BlueVpnAccountManager.candidateAllowed(
                context,
                candidate.guid,
                candidate.profile.subscriptionId,
                allowed,
            )
    }
}
