package com.v2ray.ang.bluevpn

import android.content.Context
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

enum class BlueVpnConnectionMode(
    val key: String,
    val title: String,
    val shortTitle: String,
    val description: String,
) {
    BALANCED(
        key = "balanced",
        title = "متعادل",
        shortTitle = "متعادل",
        description = "ترکیب سرعت، پایداری و مسیر جایگزین",
    ),
    GAMING(
        key = "gaming",
        title = "حالت بازی",
        shortTitle = "بازی",
        description = "اولویت با کمترین پینگ و مسیر ثابت",
    ),
    STREAMING(
        key = "streaming",
        title = "حالت پخش",
        shortTitle = "پخش",
        description = "اولویت با مسیرهای پایدار برای ویدئو",
    );

    companion object {
        fun fromKey(value: String?): BlueVpnConnectionMode =
            entries.firstOrNull { it.key == value }
                ?: BALANCED
    }
}

data class BlueVpnHistoryEntry(
    val timestamp: Long,
    val locationKey: String,
    val locationTitle: String,
    val flag: String,
    val delayMs: Long,
    val healthScore: Int,
    val mode: BlueVpnConnectionMode,
)

object BlueVpnExperience {
    private const val PREFS = "bluevpn_experience"
    private const val KEY_MODE = "connection_mode"
    private const val KEY_FAVORITES = "favorite_locations"
    private const val KEY_HISTORY = "connection_history"
    private const val KEY_WELCOME_SHOWN = "welcome_210_shown"
    private const val HISTORY_LIMIT = 12

    private fun prefs(context: Context) =
        context.getSharedPreferences(
            PREFS,
            Context.MODE_PRIVATE,
        )

    fun mode(context: Context): BlueVpnConnectionMode =
        BlueVpnConnectionMode.fromKey(
            prefs(context).getString(
                KEY_MODE,
                BlueVpnConnectionMode.BALANCED.key,
            )
        )

    fun setMode(
        context: Context,
        mode: BlueVpnConnectionMode,
    ) {
        prefs(context).edit()
            .putString(KEY_MODE, mode.key)
            .apply()
    }

    fun favoriteLocations(context: Context): Set<String> =
        prefs(context)
            .getStringSet(KEY_FAVORITES, emptySet())
            ?.toSet()
            ?: emptySet()

    fun isFavorite(
        context: Context,
        locationKey: String,
    ): Boolean =
        favoriteLocations(context).contains(locationKey)

    fun toggleFavorite(
        context: Context,
        locationKey: String,
    ): Boolean {
        if (locationKey.isBlank()) return false

        val values = favoriteLocations(context)
            .toMutableSet()
        val nowFavorite = if (values.contains(locationKey)) {
            values.remove(locationKey)
            false
        } else {
            values.add(locationKey)
            true
        }

        prefs(context).edit()
            .putStringSet(KEY_FAVORITES, values)
            .apply()

        return nowFavorite
    }

    fun favoritesCount(context: Context): Int =
        favoriteLocations(context).size

    fun shouldShowWelcome(context: Context): Boolean =
        !prefs(context).getBoolean(
            KEY_WELCOME_SHOWN,
            false,
        )

    fun markWelcomeShown(context: Context) {
        prefs(context).edit()
            .putBoolean(KEY_WELCOME_SHOWN, true)
            .apply()
    }

    fun healthScore(
        context: Context,
        candidate: BlueVpnLocationUtil.Candidate,
    ): Int {
        if (
            BlueVpnPreferences.isSessionInactive(
                context,
                candidate.guid,
            )
        ) {
            return 8
        }

        val delay = candidate.delay
        var score = when {
            delay in 1..35 -> 98
            delay in 36..60 -> 94
            delay in 61..90 -> 88
            delay in 91..130 -> 80
            delay in 131..180 -> 70
            delay in 181..250 -> 58
            delay > 250 -> 42
            delay < 0 -> 18
            else -> 55
        }

        if (
            BlueVpnPreferences.failedRecently(
                context,
                candidate.guid,
            )
        ) {
            score -= 24
        }

        if (
            isFavorite(
                context,
                candidate.location.key,
            )
        ) {
            score += 4
        }

        score += when (mode(context)) {
            BlueVpnConnectionMode.GAMING -> when {
                delay in 1..50 -> 8
                delay in 51..85 -> 4
                delay > 150 -> -12
                else -> 0
            }

            BlueVpnConnectionMode.STREAMING -> when {
                delay in 1..180 -> 5
                delay > 300 -> -8
                else -> 0
            }

            BlueVpnConnectionMode.BALANCED -> 0
        }

        return BlueVpnAi.combinedScore(
            context,
            candidate,
            score.coerceIn(0, 100),
        ).coerceIn(0, 100)
    }

    fun candidatePriority(
        context: Context,
        candidate: BlueVpnLocationUtil.Candidate,
    ): Int {
        var priority = healthScore(context, candidate) * 100

        if (
            isFavorite(
                context,
                candidate.location.key,
            )
        ) {
            priority += when (mode(context)) {
                BlueVpnConnectionMode.BALANCED -> 450
                BlueVpnConnectionMode.GAMING -> 180
                BlueVpnConnectionMode.STREAMING -> 650
            }
        }

        priority += when (mode(context)) {
            BlueVpnConnectionMode.GAMING ->
                if (candidate.delay in 1..80) 500 else 0

            BlueVpnConnectionMode.STREAMING ->
                if (candidate.delay in 1..220) 280 else 0

            BlueVpnConnectionMode.BALANCED -> 0
        }

        priority += BlueVpnAi.priorityBoost(
            context,
            candidate,
        )

        return priority
    }

    fun qualityLabel(score: Int): String =
        when {
            score >= 92 -> "عالی"
            score >= 82 -> "خیلی خوب"
            score >= 70 -> "خوب"
            score >= 55 -> "متوسط"
            score >= 35 -> "ضعیف"
            else -> "ناموفق"
        }

    fun qualityColor(score: Int): String =
        when {
            score >= 82 -> "#35E6A6"
            score >= 70 -> "#6CABFF"
            score >= 55 -> "#FFB44A"
            else -> "#FF6E83"
        }

    fun recordConnection(
        context: Context,
        location: BlueVpnLocation,
        delayMs: Long,
        healthScore: Int,
    ) {
        val entry = listOf(
            System.currentTimeMillis().toString(),
            location.key.safeHistoryValue(),
            location.title.safeHistoryValue(),
            location.flag.safeHistoryValue(),
            delayMs.toString(),
            healthScore.toString(),
            mode(context).key,
        ).joinToString("\t")

        val updated = buildList {
            add(entry)
            addAll(
                prefs(context)
                    .getString(KEY_HISTORY, "")
                    .orEmpty()
                    .lineSequence()
                    .filter { it.isNotBlank() }
                    .filterNot { it == entry }
                    .take(HISTORY_LIMIT - 1)
            )
        }.joinToString("\n")

        prefs(context).edit()
            .putString(KEY_HISTORY, updated)
            .apply()
    }

    fun history(context: Context): List<BlueVpnHistoryEntry> =
        prefs(context)
            .getString(KEY_HISTORY, "")
            .orEmpty()
            .lineSequence()
            .filter { it.isNotBlank() }
            .mapNotNull { line ->
                val parts = line.split("\t")
                if (parts.size < 7) return@mapNotNull null

                BlueVpnHistoryEntry(
                    timestamp = parts[0].toLongOrNull()
                        ?: return@mapNotNull null,
                    locationKey = parts[1],
                    locationTitle = parts[2],
                    flag = parts[3],
                    delayMs = parts[4].toLongOrNull() ?: 0L,
                    healthScore = parts[5].toIntOrNull() ?: 0,
                    mode = BlueVpnConnectionMode.fromKey(parts[6]),
                )
            }
            .take(HISTORY_LIMIT)
            .toList()

    fun recentSummary(context: Context): String {
        val latest = history(context).firstOrNull()
            ?: return "هنوز اتصال موفقی ثبت نشده است"

        val time = SimpleDateFormat(
            "HH:mm",
            Locale("fa"),
        ).format(Date(latest.timestamp))

        val delay = latest.delayMs
            .takeIf { it > 0L }
            ?.let { " • ${it} ms" }
            .orEmpty()

        return (
            "${latest.flag} ${latest.locationTitle}" +
                delay +
                " • $time"
            )
    }

    fun historyDescription(context: Context): String {
        val rows = history(context).take(5)

        if (rows.isEmpty()) {
            return "تاریخچه اتصال هنوز خالی است."
        }

        val formatter = SimpleDateFormat(
            "MM/dd  HH:mm",
            Locale("fa"),
        )

        return rows.joinToString("\n") { item ->
            val delay = item.delayMs
                .takeIf { it > 0L }
                ?.let { "${it}ms" }
                ?: "بدون پینگ"

            (
                "${item.flag} ${item.locationTitle}" +
                    " • $delay" +
                    " • ${item.healthScore}/100" +
                    " • ${formatter.format(Date(item.timestamp))}"
                )
        }
    }

    fun clearHistory(context: Context) {
        prefs(context).edit()
            .remove(KEY_HISTORY)
            .apply()
    }

    private fun String.safeHistoryValue(): String =
        replace("\t", " ")
            .replace("\n", " ")
            .trim()
}
