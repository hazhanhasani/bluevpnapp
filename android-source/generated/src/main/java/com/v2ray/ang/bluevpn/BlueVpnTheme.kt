package com.v2ray.ang.bluevpn

import android.animation.ValueAnimator
import android.app.Activity
import android.app.ActivityManager
import android.content.Context
import android.content.res.Configuration
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import android.graphics.RadialGradient
import android.graphics.Shader
import android.os.Build
import android.view.View
import android.view.animation.LinearInterpolator

enum class BlueVpnThemeMode(val key: String, val title: String) {
    SYSTEM("system", "همراه دستگاه"),
    DARK("dark", "تیره"),
    LIGHT("light", "روشن"),
}

data class BlueVpnPalette(
    val dark: Boolean,
    val background: Int,
    val surface: Int,
    val surfaceStrong: Int,
    val surfaceSoft: Int,
    val stroke: Int,
    val textPrimary: Int,
    val textSecondary: Int,
    val textMuted: Int,
    val accent: Int,
    val accentStrong: Int,
    val success: Int,
    val warning: Int,
    val danger: Int,
)

object BlueVpnPerformance {
    @Volatile
    private var cachedLowEnd: Boolean? = null

    fun isLowEnd(context: Context): Boolean {
        cachedLowEnd?.let { return it }
        val app = context.applicationContext
        val manager = app.getSystemService(Context.ACTIVITY_SERVICE) as? ActivityManager
        val maxHeapMb = (Runtime.getRuntime().maxMemory() / (1024L * 1024L)).toInt()
        val lowEnd = BlueVpnUiGuard.safeMode(app) ||
            manager?.isLowRamDevice == true ||
            (manager?.memoryClass ?: 256) <= 128 ||
            maxHeapMb <= 192 ||
            Build.VERSION.SDK_INT <= Build.VERSION_CODES.N_MR1
        cachedLowEnd = lowEnd
        return lowEnd
    }

    fun statsIntervalMs(context: Context): Long =
        if (isLowEnd(context)) 5_000L else 2_000L

    fun locationSyncIntervalMs(context: Context): Long =
        if (isLowEnd(context)) 60_000L else 30_000L

    fun updateCheckDelayMs(context: Context): Long =
        if (isLowEnd(context)) 6_000L else 2_500L

    fun adCacheKb(context: Context): Int =
        if (isLowEnd(context)) 4 * 1024 else 10 * 1024

    fun maxProbeWorkers(context: Context): Int =
        if (isLowEnd(context)) 2 else 3

    fun uiChunkSize(context: Context): Int =
        if (isLowEnd(context)) 3 else 7

    fun uiRenderDelayMs(context: Context): Long =
        if (isLowEnd(context)) 120L else 45L
}

object BlueVpnTheme {
    private const val PREFS = "bluevpn_ui"
    private const val KEY_MODE = "theme_mode"
    private const val KEY_CHANGED_AT = "theme_changed_at"
    private const val TRANSITION_GRACE_MS = 12_000L

    fun mode(context: Context): BlueVpnThemeMode {
        val raw = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .getString(KEY_MODE, BlueVpnThemeMode.SYSTEM.key)
        return BlueVpnThemeMode.values().firstOrNull { it.key == raw }
            ?: BlueVpnThemeMode.SYSTEM
    }

    fun setMode(context: Context, mode: BlueVpnThemeMode) {
        val preferences = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        if (preferences.getString(KEY_MODE, BlueVpnThemeMode.SYSTEM.key) == mode.key) {
            return
        }
        // Commit synchronously so every view rebuilt in this frame reads the
        // same palette. The payload is tiny and avoids mixed old/new colors.
        preferences.edit()
            .putString(KEY_MODE, mode.key)
            .putLong(KEY_CHANGED_AT, System.currentTimeMillis())
            .commit()
    }

    fun changedAt(context: Context): Long =
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .getLong(KEY_CHANGED_AT, 0L)

    fun isTransitionRecent(
        context: Context,
        windowMs: Long = TRANSITION_GRACE_MS,
    ): Boolean {
        val changedAt = changedAt(context)
        return changedAt > 0L &&
            System.currentTimeMillis() - changedAt in 0L..windowMs
    }

    fun isDark(context: Context): Boolean = when (mode(context)) {
        BlueVpnThemeMode.DARK -> true
        BlueVpnThemeMode.LIGHT -> false
        BlueVpnThemeMode.SYSTEM -> {
            val night = context.resources.configuration.uiMode and
                Configuration.UI_MODE_NIGHT_MASK
            night == Configuration.UI_MODE_NIGHT_YES
        }
    }

    fun palette(context: Context): BlueVpnPalette = if (isDark(context)) {
        BlueVpnPalette(
            dark = true,
            background = Color.parseColor("#08090E"),
            surface = Color.parseColor("#121319"),
            surfaceStrong = Color.parseColor("#181A22"),
            surfaceSoft = Color.parseColor("#0D0E13"),
            stroke = Color.parseColor("#2A2D38"),
            textPrimary = Color.parseColor("#F6F7FB"),
            textSecondary = Color.parseColor("#B9BCC7"),
            textMuted = Color.parseColor("#777B89"),
            accent = Color.parseColor("#4B7DFF"),
            accentStrong = Color.parseColor("#2E5FE6"),
            success = Color.parseColor("#2ED3A1"),
            warning = Color.parseColor("#FFB454"),
            danger = Color.parseColor("#F06E84"),
        )
    } else {
        BlueVpnPalette(
            dark = false,
            background = Color.parseColor("#F6F8FC"),
            surface = Color.WHITE,
            surfaceStrong = Color.parseColor("#EEF2FA"),
            surfaceSoft = Color.parseColor("#E8EDF7"),
            stroke = Color.parseColor("#D9DFEC"),
            textPrimary = Color.parseColor("#141824"),
            textSecondary = Color.parseColor("#525B6C"),
            textMuted = Color.parseColor("#818A9B"),
            accent = Color.parseColor("#356DF1"),
            accentStrong = Color.parseColor("#2455CC"),
            success = Color.parseColor("#118A67"),
            warning = Color.parseColor("#B76C10"),
            danger = Color.parseColor("#C43F59"),
        )
    }

    @Suppress("DEPRECATION")
    fun applySystemBars(activity: Activity) {
        val palette = palette(activity)
        activity.window.statusBarColor = palette.background
        activity.window.navigationBarColor = palette.background
        var flags = activity.window.decorView.systemUiVisibility
        flags = if (palette.dark) {
            flags and View.SYSTEM_UI_FLAG_LIGHT_STATUS_BAR.inv()
        } else {
            flags or View.SYSTEM_UI_FLAG_LIGHT_STATUS_BAR
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            flags = if (palette.dark) {
                flags and View.SYSTEM_UI_FLAG_LIGHT_NAVIGATION_BAR.inv()
            } else {
                flags or View.SYSTEM_UI_FLAG_LIGHT_NAVIGATION_BAR
            }
        }
        activity.window.decorView.systemUiVisibility = flags
    }
}

class BlueVpnDynamicBackgroundView(context: Context) : View(context) {
    private val paint = Paint(Paint.ANTI_ALIAS_FLAG)
    private var phase = 0f
    private var animator: ValueAnimator? = null

    override fun onAttachedToWindow() {
        super.onAttachedToWindow()
        if (BlueVpnPerformance.isLowEnd(context)) {
            phase = 0.5f
            invalidate()
            return
        }
        if (animator?.isRunning == true) return
        animator = ValueAnimator.ofFloat(0f, 1f).apply {
            duration = 18_000L
            repeatCount = ValueAnimator.INFINITE
            repeatMode = ValueAnimator.REVERSE
            interpolator = LinearInterpolator()
            addUpdateListener {
                phase = it.animatedValue as Float
                invalidate()
            }
            start()
        }
    }

    override fun onDetachedFromWindow() {
        animator?.cancel()
        animator = null
        super.onDetachedFromWindow()
    }

    override fun onDraw(canvas: Canvas) {
        super.onDraw(canvas)
        val palette = BlueVpnTheme.palette(context)
        canvas.drawColor(palette.background)
        if (width <= 0 || height <= 0) return

        val accent = palette.accent
        drawGlow(
            canvas,
            width * (0.13f + phase * 0.12f),
            height * 0.32f,
            width * 0.48f,
            if (palette.dark) 38 else 26,
            accent,
        )
        drawGlow(
            canvas,
            width * (0.86f - phase * 0.10f),
            height * 0.69f,
            width * 0.38f,
            if (palette.dark) 27 else 18,
            Color.parseColor("#715CFF"),
        )
        drawGlow(
            canvas,
            width * 0.58f,
            height * (0.08f + phase * 0.08f),
            width * 0.28f,
            if (palette.dark) 20 else 12,
            Color.parseColor("#1AC6D9"),
        )
    }

    private fun drawGlow(
        canvas: Canvas,
        cx: Float,
        cy: Float,
        radius: Float,
        alpha: Int,
        color: Int,
    ) {
        paint.shader = RadialGradient(
            cx,
            cy,
            radius,
            intArrayOf(
                Color.argb(alpha, Color.red(color), Color.green(color), Color.blue(color)),
                Color.TRANSPARENT,
            ),
            floatArrayOf(0f, 1f),
            Shader.TileMode.CLAMP,
        )
        canvas.drawCircle(cx, cy, radius, paint)
        paint.shader = null
    }
}
