package com.v2ray.ang.bluevpn

import android.content.Context
import android.os.SystemClock
import com.v2ray.ang.handler.MmkvManager
import kotlin.math.max

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

    fun score(
        context: Context,
        candidate: BlueVpnLocationUtil.Candidate,
    ): ScoredCandidate {
        if (!BlueVpnEntitlement.candidateAllowed(context, candidate)) {
            return ScoredCandidate(candidate, 0, 100, "خارج از پلن فعال")
        }
        val inactive = BlueVpnPreferences.isSessionInactive(context, candidate.guid)
        val failed = BlueVpnPreferences.failedRecently(context, candidate.guid)
        val freshness = BlueVpnPreferences.successFreshnessScore(context, candidate.guid)
        val cloud = BlueVpnAi.cloudScore(context, candidate)
        val personal = BlueVpnAi.personalScore(context, candidate)
        val latency = delayScore(candidate.delay)

        var score = latency * 55 / 100
        score += cloud * 15 / 100
        score += personal * 15 / 100
        score += freshness.coerceIn(0, 34) * 15 / 34
        if (BlueVpnExperience.isFavorite(context, candidate.location.key)) score += 3
        if (failed) score -= 24
        if (inactive) score -= 55
        score = score.coerceIn(0, 100)

        val evidence = listOf(
            if (candidate.delay > 0L) "پینگ ${candidate.delay}ms" else "پینگ نامشخص",
            when {
                inactive -> "در این نشست ناموفق"
                failed -> "خطای اخیر"
                freshness > 10 -> "موفقیت اخیر"
                personal >= 65 -> "سابقه خوب روی این دستگاه"
                cloud >= 65 -> "پیشنهاد جمعی مناسب"
                else -> "مسیر سازگار"
            },
        ).joinToString(" • ")
        val confidence = when {
            candidate.delay > 0L && (freshness > 0 || personal != 50 || cloud != 50) -> 92
            candidate.delay > 0L -> 78
            freshness > 0 || personal != 50 || cloud != 50 -> 64
            else -> 45
        }
        return ScoredCandidate(candidate, score, confidence, evidence)
    }

    fun rank(
        context: Context,
        candidates: List<BlueVpnLocationUtil.Candidate>,
    ): List<ScoredCandidate> = candidates
        .asSequence()
        .filter { BlueVpnEntitlement.candidateAllowed(context, it) }
        .map { score(context, it) }
        .sortedWith(
            compareByDescending<ScoredCandidate> { it.score }
                .thenByDescending { it.confidence }
                .thenBy {
                    if (it.candidate.delay > 0L) it.candidate.delay else Long.MAX_VALUE
                }
                .thenBy { it.candidate.location.title }
        )
        .toList()

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
        if (ranked.size < 2) return ranked

        val bestScore = ranked.first().score
        val nearTop = ranked.takeWhile { it.score >= max(35, bestScore - 8) }
        if (nearTop.size < 2) return ranked

        val identity = BlueVpnEntitlement.resolve(context).identity
        val storage = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val lastIdentity = storage.getString(KEY_LAST_AUTO_IDENTITY, "").orEmpty()
        val lastGuid = if (lastIdentity == identity) {
            storage.getString(KEY_LAST_AUTO_GUID, "").orEmpty()
        } else {
            ""
        }
        val lastIndex = nearTop.indexOfFirst { it.candidate.guid == lastGuid }
        val chosen = if (lastIndex >= 0) {
            nearTop[(lastIndex + 1) % nearTop.size]
        } else {
            nearTop.first()
        }
        return buildList {
            add(chosen)
            ranked.forEach { if (it.candidate.guid != chosen.candidate.guid) add(it) }
        }
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
        val identity = BlueVpnEntitlement.resolve(context).identity
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
        val guid = storage.getString(KEY_LAST_GUID, "").orEmpty()
        val reason = storage.getString(KEY_LAST_REASON, "").orEmpty()
        val score = storage.getInt(KEY_LAST_SCORE, 0)
        val age = System.currentTimeMillis() - storage.getLong(KEY_LAST_AT, 0L)
        if (guid.isBlank() || reason.isBlank() || age !in 0..30 * 60_000L) {
            return "انتخاب‌گر هوشمند آماده تحلیل است"
        }
        val profile = MmkvManager.decodeServerConfig(guid)
        val location = profile?.let { BlueVpnLocationUtil.detect(it.remarks, it.server) }
        return "${location?.flag.orEmpty()} ${location?.title ?: "مسیر منتخب"} • امتیاز $score • $reason".trim()
    }

    fun clear(context: Context) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().clear().apply()
    }
}
