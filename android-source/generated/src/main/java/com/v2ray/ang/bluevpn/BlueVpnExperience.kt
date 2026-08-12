package com.v2ray.ang.bluevpn

import android.app.Activity
import android.content.Context
import android.content.Intent
import android.os.SystemClock
import android.util.Log
import android.view.View
import android.widget.Toast
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.WeakHashMap

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

    fun mode(context: Context): BlueVpnConnectionMode {
        // Legacy builds exposed gaming/streaming presets. The simplified app
        // has one adaptive mode, so migrate every old preference to automatic.
        val storage = prefs(context)
        if (storage.getString(KEY_MODE, "") != BlueVpnConnectionMode.BALANCED.key) {
            storage.edit()
                .putString(KEY_MODE, BlueVpnConnectionMode.BALANCED.key)
                .apply()
        }
        return BlueVpnConnectionMode.BALANCED
    }

    fun setMode(
        context: Context,
        @Suppress("UNUSED_PARAMETER") mode: BlueVpnConnectionMode,
    ) {
        prefs(context).edit()
            .putString(KEY_MODE, BlueVpnConnectionMode.BALANCED.key)
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
    ): Int = BlueVpnSmartSelector.scoreTrusted(context, candidate).score

    fun candidatePriority(
        context: Context,
        candidate: BlueVpnLocationUtil.Candidate,
    ): Int {
        val scored = BlueVpnSmartSelector.scoreTrusted(context, candidate)
        return scored.score * 100 + scored.confidence
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


/**
 * Small process-wide guard for UI actions.
 *
 * Older builds allowed several taps to launch the same screen, rebuild a large
 * server list more than once, or start connect/disconnect concurrently. On low
 * end phones that looked like a frozen screen and could end in an Activity
 * crash. This guard only debounces UI events; it never changes VPN state.
 */
object BlueVpnUiGuard {
    private const val PREFS = "bluevpn_stability"
    private const val DEFAULT_CLICK_WINDOW_MS = 280L
    private const val DEFAULT_NAVIGATION_WINDOW_MS = 360L
    private const val SAFE_MODE_DURATION_MS = 24 * 60 * 60 * 1000L

    private val clickTimes = WeakHashMap<View, Long>()
    private val navigationTimes = WeakHashMap<Activity, Long>()
    @Volatile private var crashLoggerInstalled = false

    fun bind(
        view: View,
        intervalMs: Long = DEFAULT_CLICK_WINDOW_MS,
        action: () -> Unit,
    ) {
        view.setOnClickListener {
            if (!view.isEnabled || !view.isAttachedToWindow) return@setOnClickListener
            val now = SystemClock.elapsedRealtime()
            val accepted = synchronized(clickTimes) {
                val previous = clickTimes[view] ?: 0L
                if (now - previous < intervalMs) {
                    false
                } else {
                    clickTimes[view] = now
                    true
                }
            }
            if (!accepted) return@setOnClickListener
            run(view.context, "click") { action() }
        }
    }

    fun run(
        context: Context,
        label: String,
        action: () -> Unit,
    ): Boolean = try {
        action()
        true
    } catch (error: Exception) {
        record(context, label, error)
        Toast.makeText(
            context,
            "این بخش موقتاً آماده نشد؛ دوباره تلاش کنید",
            Toast.LENGTH_SHORT,
        ).show()
        false
    }

    fun start(
        activity: Activity,
        intent: Intent,
        intervalMs: Long = DEFAULT_NAVIGATION_WINDOW_MS,
    ): Boolean {
        if (activity.isFinishing || activity.isDestroyed) return false
        val now = SystemClock.elapsedRealtime()
        val accepted = synchronized(navigationTimes) {
            val previous = navigationTimes[activity] ?: 0L
            if (now - previous < intervalMs) {
                false
            } else {
                navigationTimes[activity] = now
                true
            }
        }
        if (!accepted) return false
        return run(activity, "navigation:${intent.component?.className.orEmpty()}") {
            activity.startActivity(intent)
        }
    }

    fun installCrashLogger(context: Context) {
        if (crashLoggerInstalled) return
        synchronized(this) {
            if (crashLoggerInstalled) return
            val app = context.applicationContext
            val previous = Thread.getDefaultUncaughtExceptionHandler()
            Thread.setDefaultUncaughtExceptionHandler { thread, error ->
                runCatching {
                    val summary = "${error.javaClass.simpleName}: ${error.message.orEmpty()}"
                        .take(500)
                    app.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
                        .edit()
                        .putLong("last_fatal_at", System.currentTimeMillis())
                        .putLong(
                            "safe_mode_until",
                            System.currentTimeMillis() + SAFE_MODE_DURATION_MS,
                        )
                        .putString("last_fatal_thread", thread.name.take(80))
                        .putString("last_fatal_summary", summary)
                        .commit()
                }
                previous?.uncaughtException(thread, error)
            }
            crashLoggerInstalled = true
        }
    }

    fun safeMode(context: Context): Boolean =
        context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .getLong("safe_mode_until", 0L) > System.currentTimeMillis()

    fun consumeRecoveryNotice(context: Context): Boolean {
        val preferences = context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val fatalAt = preferences.getLong("last_fatal_at", 0L)
        val shownAt = preferences.getLong("last_fatal_notice_at", 0L)
        if (fatalAt <= 0L || fatalAt <= shownAt) return false
        preferences.edit().putLong("last_fatal_notice_at", fatalAt).apply()
        return true
    }

    private fun record(context: Context, label: String, error: Exception) {
        Log.e("BlueVpnUiGuard", label, error)
        runCatching {
            context.applicationContext
                .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
                .edit()
                .putLong("last_ui_error_at", System.currentTimeMillis())
                .putString("last_ui_error_action", label.take(120))
                .putString(
                    "last_ui_error_summary",
                    "${error.javaClass.simpleName}: ${error.message.orEmpty()}".take(500),
                )
                .apply()
        }
    }
}
