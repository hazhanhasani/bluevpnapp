package com.v2ray.ang.bluevpn

import android.content.Context
import com.v2ray.ang.handler.MmkvManager
import org.json.JSONArray
import org.json.JSONObject
import java.security.MessageDigest
import java.util.Locale

/**
 * BlueAI subscription-pool orchestrator.
 *
 * v2rayNG remains the only parser/importer. This layer runs after an upstream
 * subscription refresh, inventories every imported profile, assigns it to the
 * Free or Premium domain from the subscription that actually produced it, and
 * quarantines exact cross-tier collisions before BlueVPN can select them.
 *
 * Important properties:
 *  - GUIDs are never trusted as identity because v2rayNG may regenerate them.
 *  - the semantic profile fingerprint is the security identity;
 *  - endpoint fingerprints are diagnostic only (same host can legitimately
 *    serve different credentials), while exact semantic collisions are blocked;
 *  - every connect candidate is checked against the latest inventory again;
 *  - inventory is rebuilt from the currently stored subscriptions, not from a
 *    time-limited logout cache.
 */
object BlueVpnPoolOrchestrator {
    enum class Tier { FREE, PREMIUM }

    data class Snapshot(
        val freeProfiles: Int,
        val premiumProfiles: Int,
        val quarantinedProfiles: Int,
        val endpointOverlapWarnings: Int,
        val scannedSubscriptions: Int,
        val builtAt: Long,
    )

    private const val PREFS = "bluevpn_pool_orchestrator_v1"
    private const val KEY_FINGERPRINT_OWNERS = "fingerprint_owners_json"
    private const val KEY_QUARANTINED = "quarantined_fingerprints"
    private const val KEY_ENDPOINT_OVERLAPS = "endpoint_overlap_fingerprints"
    private const val KEY_LAST_SCAN_AT = "last_scan_at"
    private const val KEY_LAST_SUMMARY = "last_summary_json"
    private const val FREE_REMARK = "BlueVPN Free"
    private const val PREMIUM_REMARK = "BlueVPN Account"
    private const val MAX_SCAN_AGE_MS = 15_000L

    private val lock = Any()

    fun reconcile(context: Context): Snapshot = synchronized(lock) {
        val app = context.applicationContext
        val ownerMap = linkedMapOf<String, MutableSet<Tier>>()
        val endpointMap = linkedMapOf<String, MutableSet<Tier>>()
        var freeProfiles = 0
        var premiumProfiles = 0
        var scannedSubscriptions = 0

        MmkvManager.decodeSubscriptions().forEach { row ->
            val remarks = row.subscription.remarks.trim()
            val tier = when {
                remarks.startsWith(FREE_REMARK) -> Tier.FREE
                remarks == PREMIUM_REMARK -> Tier.PREMIUM
                else -> null
            } ?: return@forEach

            // Scan managed rows even when temporarily disabled. During an
            // entitlement transition BlueVPN deliberately disables the opposite
            // row but its imported profiles must still remain known to the
            // isolation engine so they cannot leak into the active tier.
            scannedSubscriptions += 1
            val guids = runCatching { MmkvManager.decodeServerList(row.guid) }
                .getOrDefault(emptyList())
            guids.forEach { guid ->
                if (guid.isBlank() || MmkvManager.decodeServerConfig(guid) == null) return@forEach
                val fingerprint = BlueVpnProfileManager.fingerprintGuid(guid) ?: return@forEach
                ownerMap.getOrPut(fingerprint) { linkedSetOf() }.add(tier)
                BlueVpnProfileManager.endpointFingerprintGuid(guid)?.let { endpoint ->
                    endpointMap.getOrPut(endpoint) { linkedSetOf() }.add(tier)
                }
                if (tier == Tier.FREE) freeProfiles += 1 else premiumProfiles += 1
            }
        }

        val quarantined = ownerMap
            .filterValues { Tier.FREE in it && Tier.PREMIUM in it }
            .keys
            .toSet()
        val endpointOverlaps = endpointMap
            .filterValues { Tier.FREE in it && Tier.PREMIUM in it }
            .keys
            .toSet()

        val ownersJson = JSONObject()
        ownerMap.forEach { (fingerprint, tiers) ->
            ownersJson.put(
                fingerprint,
                JSONArray(tiers.map { it.name.lowercase(Locale.ROOT) }),
            )
        }
        val now = System.currentTimeMillis()
        val summary = Snapshot(
            freeProfiles = freeProfiles,
            premiumProfiles = premiumProfiles,
            quarantinedProfiles = quarantined.size,
            endpointOverlapWarnings = endpointOverlaps.size,
            scannedSubscriptions = scannedSubscriptions,
            builtAt = now,
        )
        app.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
            .putString(KEY_FINGERPRINT_OWNERS, ownersJson.toString())
            .putStringSet(KEY_QUARANTINED, quarantined)
            .putStringSet(KEY_ENDPOINT_OVERLAPS, endpointOverlaps)
            .putLong(KEY_LAST_SCAN_AT, now)
            .putString(
                KEY_LAST_SUMMARY,
                JSONObject()
                    .put("free_profiles", summary.freeProfiles)
                    .put("premium_profiles", summary.premiumProfiles)
                    .put("quarantined_profiles", summary.quarantinedProfiles)
                    .put("endpoint_overlap_warnings", summary.endpointOverlapWarnings)
                    .put("scanned_subscriptions", summary.scannedSubscriptions)
                    .put("built_at", summary.builtAt)
                    .toString(),
            )
            .commit()
        summary
    }

    fun allowed(context: Context, serverGuid: String, desiredTier: Tier): Boolean {
        if (serverGuid.isBlank() || MmkvManager.decodeServerConfig(serverGuid) == null) return false
        ensureFresh(context, serverGuid)
        val fingerprint = BlueVpnProfileManager.fingerprintGuid(serverGuid) ?: return false
        val prefs = context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        if (fingerprint in prefs.getStringSet(KEY_QUARANTINED, emptySet()).orEmpty()) return false
        val owners = ownersForFingerprint(prefs.getString(KEY_FINGERPRINT_OWNERS, "{}").orEmpty(), fingerprint)
        return desiredTier in owners && owners.size == 1
    }

    fun filterAllowed(context: Context, guids: Collection<String>, desiredTier: Tier): List<String> {
        if (guids.isEmpty()) return emptyList()
        ensureFresh(context, guids.firstOrNull().orEmpty())
        return BlueVpnProfileManager.uniqueGuids(
            guids.asSequence()
                .map { it.trim() }
                .filter { it.isNotBlank() }
                .filter { allowed(context, it, desiredTier) }
                .toList(),
        )
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
                quarantinedProfiles = json.optInt("quarantined_profiles"),
                endpointOverlapWarnings = json.optInt("endpoint_overlap_warnings"),
                scannedSubscriptions = json.optInt("scanned_subscriptions"),
                builtAt = json.optLong("built_at"),
            )
        }.getOrElse { reconcile(context) }
    }

    fun reset(context: Context) {
        synchronized(lock) {
            context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
                .edit().clear().commit()
        }
    }

    private fun ensureFresh(context: Context, probeGuid: String) {
        val prefs = context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val stale = System.currentTimeMillis() - prefs.getLong(KEY_LAST_SCAN_AT, 0L) > MAX_SCAN_AGE_MS
        val fingerprint = BlueVpnProfileManager.fingerprintGuid(probeGuid)
        val known = fingerprint != null && ownersForFingerprint(
            prefs.getString(KEY_FINGERPRINT_OWNERS, "{}").orEmpty(),
            fingerprint,
        ).isNotEmpty()
        if (stale || !known) reconcile(context)
    }

    private fun ownersForFingerprint(raw: String, fingerprint: String): Set<Tier> = runCatching {
        val arr = JSONObject(raw).optJSONArray(fingerprint) ?: return@runCatching emptySet()
        buildSet {
            for (index in 0 until arr.length()) {
                when (arr.optString(index).lowercase(Locale.ROOT)) {
                    "free" -> add(Tier.FREE)
                    "premium" -> add(Tier.PREMIUM)
                }
            }
        }
    }.getOrDefault(emptySet())
}
