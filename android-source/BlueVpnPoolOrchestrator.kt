package com.v2ray.ang.bluevpn

import android.content.Context
import com.v2ray.ang.handler.MmkvManager
import org.json.JSONArray
import org.json.JSONObject
import java.util.Locale

/**
 * BlueAI subscription-pool orchestrator v2.
 *
 * The producing subscription row is the authority for tier ownership. GUIDs are
 * volatile identities, but they still tell us which exact imported profile came
 * from which subscription *right now*. Semantic fingerprints are used to detect
 * duplicates/collisions, never to move a profile from one tier to another.
 *
 * Security policy:
 *  - a candidate is connectable only when its current GUID belongs to the active
 *    tier's subscription inventory;
 *  - exact Free/Premium semantic collisions never merge the two pools;
 *  - on an exact collision the Premium copy remains usable and the Free copy is
 *    blocked, so a paid entitlement cannot silently degrade while Free cannot
 *    inherit paid credentials;
 *  - endpoint-only overlap is diagnostic because the same host may legitimately
 *    serve different UUID/password credentials;
 *  - every reconcile is rebuilt from v2rayNG's current decodeServerList(row.guid)
 *    output, so stale historical ownership cannot poison a future subscription.
 */
object BlueVpnPoolOrchestrator {
    enum class Tier { FREE, PREMIUM }

    data class Snapshot(
        val freeProfiles: Int,
        val premiumProfiles: Int,
        val blockedFreeCollisions: Int,
        val endpointOverlapWarnings: Int,
        val scannedSubscriptions: Int,
        val builtAt: Long,
    ) {
        // Compatibility for existing diagnostics/UI keys.
        val quarantinedProfiles: Int get() = blockedFreeCollisions
    }

    private const val PREFS = "bluevpn_pool_orchestrator_v2"
    private const val KEY_GUID_OWNERS = "guid_owners_json"
    private const val KEY_FINGERPRINT_TIERS = "fingerprint_tiers_json"
    private const val KEY_BLOCKED_FREE_GUIDS = "blocked_free_collision_guids"
    private const val KEY_ENDPOINT_OVERLAPS = "endpoint_overlap_fingerprints"
    private const val KEY_LAST_SCAN_AT = "last_scan_at"
    private const val KEY_LAST_SUMMARY = "last_summary_json"
    private const val FREE_REMARK = "BlueVPN Free"
    private const val PREMIUM_REMARK = "BlueVPN Account"
    private const val MAX_SCAN_AGE_MS = 8_000L

    private val lock = Any()
    @Volatile private var memoryOwners: Map<String, Tier> = emptyMap()
    @Volatile private var memoryBlockedFree: Set<String> = emptySet()
    @Volatile private var memoryBuiltAt: Long = 0L

    fun reconcile(context: Context): Snapshot = synchronized(lock) {
        val app = context.applicationContext
        val guidOwner = linkedMapOf<String, Tier>()
        val fingerprintTiers = linkedMapOf<String, MutableSet<Tier>>()
        val freeGuidsByFingerprint = linkedMapOf<String, MutableSet<String>>()
        val endpointTiers = linkedMapOf<String, MutableSet<Tier>>()
        var freeProfiles = 0
        var premiumProfiles = 0
        var scannedSubscriptions = 0

        MmkvManager.decodeSubscriptions().forEach { row ->
            val tier = tierForRemarks(row.subscription.remarks) ?: return@forEach
            scannedSubscriptions += 1

            // Scan managed rows even while disabled. During Free/Premium swaps the
            // opposite row is deliberately disabled, but we still need to know its
            // imported profiles to detect a true current cross-tier collision.
            val guids = runCatching { MmkvManager.decodeServerList(row.guid) }
                .getOrDefault(emptyList())
            guids.forEach guidLoop@{ rawGuid ->
                val guid = rawGuid.trim()
                if (guid.isBlank() || MmkvManager.decodeServerConfig(guid) == null) return@guidLoop

                // A GUID should belong to one subscription in stock v2rayNG. If a
                // broken MMKV state exposes it under two rows, never overwrite a
                // Premium owner with Free. The connect gate will stay fail-closed.
                val previous = guidOwner[guid]
                guidOwner[guid] = when {
                    previous == Tier.PREMIUM -> Tier.PREMIUM
                    tier == Tier.PREMIUM -> Tier.PREMIUM
                    else -> tier
                }

                val fingerprint = BlueVpnProfileManager.fingerprintGuid(guid) ?: return@guidLoop
                fingerprintTiers.getOrPut(fingerprint) { linkedSetOf() }.add(tier)
                if (tier == Tier.FREE) {
                    freeGuidsByFingerprint.getOrPut(fingerprint) { linkedSetOf() }.add(guid)
                    freeProfiles += 1
                } else {
                    premiumProfiles += 1
                }

                BlueVpnProfileManager.endpointFingerprintGuid(guid)?.let { endpoint ->
                    endpointTiers.getOrPut(endpoint) { linkedSetOf() }.add(tier)
                }
            }
        }

        val exactCollisions = fingerprintTiers
            .filterValues { Tier.FREE in it && Tier.PREMIUM in it }
            .keys
        val blockedFreeGuids = exactCollisions
            .flatMapTo(linkedSetOf()) { fingerprint -> freeGuidsByFingerprint[fingerprint].orEmpty() }
        val endpointOverlaps = endpointTiers
            .filterValues { Tier.FREE in it && Tier.PREMIUM in it }
            .keys
            .toSet()

        val guidOwnersJson = JSONObject()
        guidOwner.forEach { (guid, tier) ->
            guidOwnersJson.put(guid, tier.name.lowercase(Locale.ROOT))
        }
        val fingerprintTiersJson = JSONObject()
        fingerprintTiers.forEach { (fingerprint, tiers) ->
            fingerprintTiersJson.put(
                fingerprint,
                JSONArray(tiers.map { it.name.lowercase(Locale.ROOT) }),
            )
        }

        val now = System.currentTimeMillis()
        val summary = Snapshot(
            freeProfiles = freeProfiles,
            premiumProfiles = premiumProfiles,
            blockedFreeCollisions = blockedFreeGuids.size,
            endpointOverlapWarnings = endpointOverlaps.size,
            scannedSubscriptions = scannedSubscriptions,
            builtAt = now,
        )
        // Publish one immutable in-memory ownership snapshot for the whole pool.
        // Hot candidate filtering must not re-read/re-parse SharedPreferences JSON
        // for every GUID.
        memoryOwners = guidOwner.toMap()
        memoryBlockedFree = blockedFreeGuids.toSet()
        memoryBuiltAt = System.currentTimeMillis()

        app.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
            .putString(KEY_GUID_OWNERS, guidOwnersJson.toString())
            .putString(KEY_FINGERPRINT_TIERS, fingerprintTiersJson.toString())
            .putStringSet(KEY_BLOCKED_FREE_GUIDS, blockedFreeGuids)
            .putStringSet(KEY_ENDPOINT_OVERLAPS, endpointOverlaps)
            .putLong(KEY_LAST_SCAN_AT, now)
            .putString(
                KEY_LAST_SUMMARY,
                JSONObject()
                    .put("free_profiles", summary.freeProfiles)
                    .put("premium_profiles", summary.premiumProfiles)
                    .put("blocked_free_collisions", summary.blockedFreeCollisions)
                    .put("quarantined_profiles", summary.blockedFreeCollisions)
                    .put("endpoint_overlap_warnings", summary.endpointOverlapWarnings)
                    .put("scanned_subscriptions", summary.scannedSubscriptions)
                    .put("built_at", summary.builtAt)
                    .toString(),
            )
            .commit()
        summary
    }

    fun allowed(context: Context, serverGuid: String, desiredTier: Tier): Boolean {
        val guid = serverGuid.trim()
        if (guid.isBlank()) return false
        ensureMemoryFresh(context, guid)
        if (memoryOwners[guid] != desiredTier) return false
        if (desiredTier == Tier.FREE && guid in memoryBlockedFree) return false
        return true
    }

    fun filterAllowed(context: Context, guids: Collection<String>, desiredTier: Tier): List<String> {
        if (guids.isEmpty()) return emptyList()
        ensureMemoryFresh(context, guids.firstOrNull().orEmpty())
        val owners = memoryOwners
        val blocked = memoryBlockedFree
        return guids.asSequence()
            .map { it.trim() }
            .filter { it.isNotBlank() && owners[it] == desiredTier }
            .filter { desiredTier != Tier.FREE || it !in blocked }
            .distinct()
            .toList()
    }

    fun snapshot(context: Context): Snapshot {
        val prefs = context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val raw = prefs.getString(KEY_LAST_SUMMARY, "").orEmpty()
        if (raw.isBlank()) return reconcile(context)
        return runCatching {
            val json = JSONObject(raw)
            Snapshot(
                freeProfiles = json.optInt("free_profiles"),
                premiumProfiles = json.optInt("premium_profiles"),
                blockedFreeCollisions = json.optInt(
                    "blocked_free_collisions",
                    json.optInt("quarantined_profiles"),
                ),
                endpointOverlapWarnings = json.optInt("endpoint_overlap_warnings"),
                scannedSubscriptions = json.optInt("scanned_subscriptions"),
                builtAt = json.optLong("built_at"),
            )
        }.getOrElse { reconcile(context) }
    }

    fun reset(context: Context) {
        synchronized(lock) {
            memoryOwners = emptyMap()
            memoryBlockedFree = emptySet()
            memoryBuiltAt = 0L
            BlueVpnProfileManager.invalidateCaches()
            context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
                .edit().clear().commit()
        }
    }

    private fun ensureMemoryFresh(context: Context, probeGuid: String) {
        val now = System.currentTimeMillis()
        if (memoryOwners.isNotEmpty() && now - memoryBuiltAt <= MAX_SCAN_AGE_MS &&
            (probeGuid.isBlank() || memoryOwners.containsKey(probeGuid))) return
        reconcile(context)
    }

    private fun ensureFresh(context: Context, probeGuid: String) {
        val prefs = context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val stale = System.currentTimeMillis() - prefs.getLong(KEY_LAST_SCAN_AT, 0L) > MAX_SCAN_AGE_MS
        val known = ownerForGuid(
            prefs.getString(KEY_GUID_OWNERS, "{}").orEmpty(),
            probeGuid.trim(),
        ) != null
        if (stale || !known) reconcile(context)
    }

    private fun tierForRemarks(raw: String): Tier? {
        val remarks = raw.trim()
        return when {
            remarks.startsWith(FREE_REMARK) -> Tier.FREE
            remarks == PREMIUM_REMARK -> Tier.PREMIUM
            else -> null
        }
    }

    private fun ownerForGuid(raw: String, guid: String): Tier? = runCatching {
        when (JSONObject(raw).optString(guid).lowercase(Locale.ROOT)) {
            "free" -> Tier.FREE
            "premium" -> Tier.PREMIUM
            else -> null
        }
    }.getOrNull()
}
