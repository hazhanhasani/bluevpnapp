package com.v2ray.ang.bluevpn

import android.content.Context
import android.content.Intent
import android.net.ConnectivityManager
import android.net.Uri
import android.os.Build
import android.os.PowerManager
import android.provider.Settings

object BlueVpnBackgroundReliability {
    data class State(
        val batteryUnrestricted: Boolean,
        val backgroundDataUnrestricted: Boolean,
    ) {
        val fullyReady: Boolean
            get() = batteryUnrestricted && backgroundDataUnrestricted

        val title: String
            get() = if (fullyReady) "فعال" else "نیاز به تنظیم"

        val description: String
            get() = when {
                fullyReady -> "اتصال و داده پس‌زمینه برای BlueVPN بدون محدودیت هستند"
                !batteryUnrestricted && !backgroundDataUnrestricted ->
                    "بهینه‌سازی باتری و محدودیت داده پس‌زمینه می‌توانند اتصال را قطع کنند"
                !batteryUnrestricted ->
                    "بهینه‌سازی باتری ممکن است اتصال را پس از خروج از برنامه متوقف کند"
                else ->
                    "Data Saver می‌تواند داده پس‌زمینه BlueVPN را محدود کند"
            }
    }

    fun state(context: Context): State {
        val app = context.applicationContext
        val pm = app.getSystemService(PowerManager::class.java)
        val battery = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            pm?.isIgnoringBatteryOptimizations(app.packageName) == true
        } else {
            true
        }

        val cm = app.getSystemService(ConnectivityManager::class.java)
        val data = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.N) {
            cm?.restrictBackgroundStatus !=
                ConnectivityManager.RESTRICT_BACKGROUND_STATUS_ENABLED
        } else {
            true
        }
        return State(
            batteryUnrestricted = battery,
            backgroundDataUnrestricted = data,
        )
    }

    fun openBackgroundDataSettings(context: Context) {
        val app = context.applicationContext
        val packageUri = Uri.parse("package:${app.packageName}")
        val intents = buildList {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.N) {
                add(
                    Intent(Settings.ACTION_IGNORE_BACKGROUND_DATA_RESTRICTIONS_SETTINGS)
                        .setData(packageUri)
                )
            }
            add(
                Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS)
                    .setData(packageUri)
            )
        }
        openFirst(context, intents)
    }

    fun openBatterySettings(context: Context) {
        val intents = buildList {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                add(Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS))
            }
            add(
                Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS)
                    .setData(Uri.parse("package:${context.packageName}"))
            )
        }
        openFirst(context, intents)
    }

    fun shouldPrompt(context: Context): Boolean {
        val s = state(context)
        if (s.fullyReady) return false
        val prefs = context.getSharedPreferences("bluevpn_background_reliability", Context.MODE_PRIVATE)
        val now = System.currentTimeMillis()
        val last = prefs.getLong("last_prompt_at", 0L)
        return now - last >= 7L * 24L * 60L * 60L * 1000L
    }

    fun markPromptShown(context: Context) {
        context.getSharedPreferences("bluevpn_background_reliability", Context.MODE_PRIVATE)
            .edit()
            .putLong("last_prompt_at", System.currentTimeMillis())
            .apply()
    }

    private fun openFirst(context: Context, intents: List<Intent>) {
        for (intent in intents) {
            val candidate = intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            if (candidate.resolveActivity(context.packageManager) != null) {
                context.startActivity(candidate)
                return
            }
        }
    }
}
