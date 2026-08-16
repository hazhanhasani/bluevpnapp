package com.v2ray.ang.bluevpn

import android.content.Context
import android.os.SystemClock
import com.v2ray.ang.handler.MmkvManager

/** A deterministic local selector. Cloud BlueAI is an optional signal, never a dependency. */
object BlueVpnSmartSelector {
    data class ScoredCandidate(
        val candidate: BlueVpnLocationUtil.Candidate,
        val score: Int,
        val confidence: Int,
        val reason: String,
    )

    data class Decision(
        val candidate: BlueVpnLocationUtil.Candidate,
        val score: Int,
        val confidence: Int,
        val reason: String,
        val evaluated: Int,
    )

    private const val PREFS = "bluevpn_smart_selector"
    private const val KEY_LAST_GUID = "last_guid"
    private const val KEY_LAST_REASON = "last_reason"
    private const val KEY_LAST_SCORE = "last_score"
    private const val KEY_LAST_AT = "last_at"
    private const val KEY_LAST_AUTO_GUID = "last_auto_guid"
    private const val KEY_LAST_AUTO_IDENTITY = "last_auto_identity"

    private fun delayScore(delay: Long): Int = when {
        delay in 1..35 -> 100
        delay in 36..60 -> 95
        delay in 61..90 -> 89
        delay in 91..130 -> 81
        delay in 131..180 -> 72
        delay in 181..250 -> 60
        delay in 251..400 -> 45
        delay > 400 -> 28
        delay == 0L -> 52
        else -> 12
    }

    private fun scoreKnownAllowed(
        context: Context,
        candidate: BlueVpnLocationUtil.Candidate,
    ): ScoredCandidate {
        val inactive = BlueVpnPreferences.isSessionInactive(context, candidate.guid)
        val failed = BlueVpnPreferences.failedRecently(context, candidate.guid)
        val freshness = BlueVpnPreferences.successFreshnessScore(context, candidate.guid)
        val cloud = BlueVpnAi.cloudScore(context, candidate)
        val personal = BlueVpnAi.personalScore(context, candidate)
        val latency = delayScore(candidate.delay)
        val routeAdjustment = BlueVpnRouteIntelligence.rankingAdjustment(context, candidate.guid)
        val ircfAdjustment = BlueVpnIrcfIntelligence.rankingAdjustment(context, candidate.guid)
        val intelligence = BlueVpnIntelligenceCore.routeEvidence(context, candidate.guid)
        val routeEvidence = BlueVpnRouteIntelligence.evidence(context, candidate.guid)

        var score = latency * 50 / 100
        score += cloud * 14 / 100
        score += personal * 14 / 100
        score += freshness.coerceIn(0, 34) * 12 / 34
        score += routeAdjustment
        score += ircfAdjustment
        score += intelligence.scoreAdjustment
        if (BlueVpnExperience.isFavorite(context, candidate.location.key)) score += 3
        if (failed) score -= 24
        if (inactive) score -= 55
        score = score.coerceIn(0, 100)

        val evidence = buildList {
            add(if (candidate.delay > 0L) "پینگ ${candidate.delay}ms" else "پینگ نامشخص")
            routeEvidence?.let { add(it) }
            if (intelligence.reason.isNotBlank()) add("AI: ${intelligence.reason}")
            add(when {
                inactive -> "در این نشست ناموفق"
                failed -> "خطای اخیر"
                freshness > 10 -> "موفقیت اخیر"
                personal >= 65 -> "سابقه خوب روی این دستگاه"
                cloud >= 65 -> "پیشنهاد جمعی مناسب"
                else -> "اتصال سازگار"
            })
        }.joinToString(" • ")
        val baseConfidence = when {
            candidate.delay > 0L && (freshness > 0 || personal != 50 || cloud != 50) -> 92
            candidate.delay > 0L -> 78
            freshness > 0 || personal != 50 || cloud != 50 -> 64
            else -> 45
        }
        val confidence = maxOf(baseConfidence, intelligence.confidence).coerceIn(0, 98)
        return ScoredCandidate(candidate, score, confidence, evidence)
    }

    /** Score one arbitrary candidate with one entitlement resolution. */
    fun score(
        context: Context,
        candidate: BlueVpnLocationUtil.Candidate,
    ): ScoredCandidate {
        val entitlement = BlueVpnEntitlement.resolve(context)
        if (!BlueVpnEntitlement.candidateAllowed(context, candidate, entitlement)) {
            return ScoredCandidate(candidate, 0, 100, "خارج از پلن فعال")
        }
        return scoreKnownAllowed(context, candidate)
    }

    /**
     * UI/catalogue scoring path. The caller already obtained the candidate from
     * the isolated entitlement cache, so re-enumerating MMKV for every visible
     * row would be both redundant and an ANR risk.
     */
    fun scoreTrusted(
        context: Context,
        candidate: BlueVpnLocationUtil.Candidate,
    ): ScoredCandidate = scoreKnownAllowed(context, candidate)

    fun rank(
        context: Context,
        candidates: List<BlueVpnLocationUtil.Candidate>,
    ): List<ScoredCandidate> {
        if (candidates.isEmpty()) return emptyList()
        val entitlement = BlueVpnEntitlement.resolve(context)
        val allowed = entitlement.serverGuids.toSet()
        return candidates
        .asSequence()
        .filter { candidate ->
            candidate.guid in allowed && BlueVpnAccountManager.candidateAllowed(
                context,
                candidate.guid,
                candidate.profile.subscriptionId,
                allowed,
            )
        }
        .map { scoreKnownAllowed(context, it) }
        .sortedWith(
            compareByDescending<ScoredCandidate> { it.score }
                .thenByDescending { it.confidence }
                .thenBy {
                    if (it.candidate.delay > 0L) it.candidate.delay else Long.MAX_VALUE
                }
                .thenBy { it.candidate.location.title }
        )
        .toList()
    }

    /** Rank a pool that was already isolated against one frozen entitlement set. */
    fun rankTrusted(
        context: Context,
        candidates: List<BlueVpnLocationUtil.Candidate>,
    ): List<ScoredCandidate> = candidates
        .asSequence()
        .map { scoreKnownAllowed(context, it) }
        .sortedWith(
            compareByDescending<ScoredCandidate> { it.score }
                .thenByDescending { it.confidence }
                .thenBy {
                    if (it.candidate.delay > 0L) it.candidate.delay else Long.MAX_VALUE
                }
                .thenBy { it.candidate.location.title }
        )
        .toList()

    private fun recordShadowComparison(
        context: Context,
        ranked: List<ScoredCandidate>,
    ) {
        if (ranked.size < 2) return
        val actual = ranked.first()
        val legacy = ranked.maxByOrNull {
            it.score - BlueVpnIntelligenceCore.routeEvidence(context, it.candidate.guid).scoreAdjustment
        } ?: return
        BlueVpnIntelligenceCore.recordShadowDecision(
            context = context,
            actualGuid = actual.candidate.guid,
            shadowGuid = legacy.candidate.guid,
            actualScore = actual.score,
            shadowScore = legacy.score,
            reason = "adaptive-vs-legacy",
        )
    }

    fun connectionOrderTrusted(
        context: Context,
        candidates: List<BlueVpnLocationUtil.Candidate>,
    ): List<ScoredCandidate> {
        val ranked = rankTrusted(context, candidates)
        recordShadowComparison(context, ranked)
        if (ranked.size < 2) return ranked
        val sticky = BlueVpnRouteIntelligence.stickyCandidate(context, ranked)
        if (sticky != null && sticky.candidate.guid != ranked.first().candidate.guid) {
            return buildList {
                add(sticky)
                ranked.forEach { if (it.candidate.guid != sticky.candidate.guid) add(it) }
            }
        }
        return ranked
    }

    /**
     * Orders candidates for an AUTO connection without permanently sticking to
     * one GUID. The highest scored route remains preferred, but when multiple
     * routes are within a small quality window, consecutive new sessions rotate
     * between them. A clearly superior route is never displaced merely to rotate.
     */
    fun connectionOrder(
        context: Context,
        candidates: List<BlueVpnLocationUtil.Candidate>,
    ): List<ScoredCandidate> {
        val ranked = rank(context, candidates)
        recordShadowComparison(context, ranked)
        if (ranked.size < 2) return ranked

        // Desktop-style URL-test behaviour: keep a recently verified route while
        // it is still within a small score/latency tolerance. This avoids
        // unnecessary server flapping and only switches when another route is
        // meaningfully better or the current one has actually failed.
        val sticky = BlueVpnRouteIntelligence.stickyCandidate(context, ranked)
        if (sticky != null && sticky.candidate.guid != ranked.first().candidate.guid) {
            return buildList {
                add(sticky)
                ranked.forEach { if (it.candidate.guid != sticky.candidate.guid) add(it) }
            }
        }
        return ranked
    }

    fun recordAutomaticConnectionChoice(
        context: Context,
        chosen: ScoredCandidate,
        evaluated: Int,
    ): Decision {
        val decision = Decision(
            candidate = chosen.candidate,
            score = chosen.score,
            confidence = chosen.confidence,
            reason = chosen.reason,
            evaluated = evaluated,
        )
        val identity = BlueVpnEntitlement.resolveUi(context).identity
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
            .putString(KEY_LAST_AUTO_GUID, decision.candidate.guid)
            .putString(KEY_LAST_AUTO_IDENTITY, identity)
            .putString(KEY_LAST_GUID, decision.candidate.guid)
            .putString(KEY_LAST_REASON, decision.reason)
            .putInt(KEY_LAST_SCORE, decision.score)
            .putLong(KEY_LAST_AT, System.currentTimeMillis())
            .apply()
        return decision
    }

    fun decide(
        context: Context,
        candidates: List<BlueVpnLocationUtil.Candidate>,
    ): Decision? {
        val ranked = rank(context, candidates)
        val best = ranked.firstOrNull() ?: return null
        val decision = Decision(
            candidate = best.candidate,
            score = best.score,
            confidence = best.confidence,
            reason = best.reason,
            evaluated = ranked.size,
        )
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
            .putString(KEY_LAST_GUID, decision.candidate.guid)
            .putString(KEY_LAST_REASON, decision.reason)
            .putInt(KEY_LAST_SCORE, decision.score)
            .putLong(KEY_LAST_AT, System.currentTimeMillis())
            .apply()
        return decision
    }

    fun lastSummary(context: Context): String {
        val storage = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val storedGuid = storage.getString(KEY_LAST_GUID, "").orEmpty()
        val age = System.currentTimeMillis() - storage.getLong(KEY_LAST_AT, 0L)

        // Public UI is location-only. The scorer may keep per-route health
        // history internally, but stale route evidence (ping/score/failure
        // streaks) must never leak back onto Home after routes were hidden.
        // Prefer the concrete GUID currently selected by v2rayNG so the AI
        // summary cannot describe an old Poland decision while Home shows UK.
        val selectedGuid = MmkvManager.getSelectServer().orEmpty()
        val currentGuid = sequenceOf(selectedGuid, storedGuid)
            .filter { it.isNotBlank() }
            .firstOrNull { guid ->
                val profile = MmkvManager.decodeServerConfig(guid)
                profile != null &&
                    BlueVpnAccountManager.candidateAllowed(
                        context,
                        guid,
                        profile.subscriptionId,
                    )
            }
            .orEmpty()

        if (currentGuid.isBlank()) {
            return "انتخاب‌گر هوشمند آماده تحلیل است"
        }

        val profile = MmkvManager.decodeServerConfig(currentGuid)
            ?: return "انتخاب‌گر هوشمند در انتظار دریافت سرورهای مجاز است"
        val location = BlueVpnLocationUtil.detect(profile.remarks, profile.server)
        val label = when {
            currentGuid == selectedGuid && selectedGuid.isNotBlank() -> "لوکیشن انتخاب‌شده"
            currentGuid == storedGuid && age in 0..30 * 60_000L -> "پیشنهاد هوشمند"
            else -> "آماده اتصال"
        }
        return "${location.flag} ${location.title} • $label".trim()
    }

    fun clear(context: Context) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().clear().apply()
    }
}
