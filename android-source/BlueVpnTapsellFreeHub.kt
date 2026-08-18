package com.v2ray.ang.bluevpn

import android.app.Activity
import android.app.Dialog
import android.content.Context
import android.graphics.Color
import android.graphics.drawable.GradientDrawable
import android.os.Handler
import android.os.Looper
import android.view.Gravity
import android.view.View
import android.view.ViewGroup
import android.view.Window
import android.view.WindowManager
import android.widget.Button
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.ProgressBar
import android.widget.ScrollView
import android.widget.TextView
import android.widget.Toast

/**
 * Free-only Tapsell surface hub.
 *
 * Premium contract:
 * no Tapsell request/show/preload/surface is allowed for Premium entitlement.
 * Premium keeps only BlueVPN's own first-party campaign banners.
 */
object BlueVpnTapsellFreeHub {
    private val main = Handler(Looper.getMainLooper())

    fun show(activity: Activity) {
        if (activity.isFinishing || activity.isDestroyed) return
        if (!BlueVpnEntitlement.resolveUi(activity).isFree) return

        BlueVpnTapsellManager.surfaceConfig(activity) { config ->
            if (
                activity.isFinishing ||
                activity.isDestroyed ||
                !BlueVpnEntitlement.resolveUi(activity).isFree
            ) return@surfaceConfig

            if (!config.enabled || config.zones.values.none { it.isNotBlank() }) {
                Toast.makeText(
                    activity,
                    "جایگاه تبلیغ رایگان فعال نیست",
                    Toast.LENGTH_SHORT,
                ).show()
                return@surfaceConfig
            }
            main.post { showDialog(activity, config) }
        }
    }

    private fun showDialog(
        activity: Activity,
        config: BlueVpnTapsellManager.SurfaceConfig,
    ) {
        if (!BlueVpnEntitlement.resolveUi(activity).isFree) return

        val dialog = Dialog(activity)
        dialog.requestWindowFeature(Window.FEATURE_NO_TITLE)

        val scroll = ScrollView(activity).apply {
            isFillViewport = true
        }
        val root = LinearLayout(activity).apply {
            orientation = LinearLayout.VERTICAL
            layoutDirection = View.LAYOUT_DIRECTION_RTL
            setPadding(dp(activity, 16), dp(activity, 18), dp(activity, 16), dp(activity, 22))
            setBackgroundColor(Color.rgb(247, 249, 253))
        }
        scroll.addView(
            root,
            ViewGroup.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT,
            ),
        )

        root.addView(title(activity, "تبلیغات و هدیه رایگان", 20f, true))
        root.addView(
            title(
                activity,
                "این بخش فقط برای پلن رایگان است و هیچ تبلیغی مالک وضعیت VPN نیست.",
                11f,
                false,
            ).apply {
                setTextColor(Color.rgb(92, 104, 126))
                setPadding(0, dp(activity, 4), 0, dp(activity, 12))
            },
        )

        val cleanups = mutableListOf<() -> Unit>()

        config.zones["rewarded_video"]?.takeIf { it.isNotBlank() }?.let { zone ->
            root.addView(sectionLabel(activity, "🎁 ویدئوی جایزه‌ای"))
            root.addView(
                Button(activity).apply {
                    isAllCaps = false
                    text = "تماشای تبلیغ و دریافت ${config.rewardedBonusMinutes} دقیقه هدیه"
                    setOnClickListener {
                        if (!BlueVpnEntitlement.resolveUi(activity).isFree) return@setOnClickListener
                        isEnabled = false
                        text = "در حال آماده‌سازی تبلیغ…"
                        BlueVpnTapsellManager.showRewarded(
                            activity = activity,
                            zoneId = zone,
                            rewardMinutes = config.rewardedBonusMinutes,
                            onRewarded = {
                                if (!activity.isFinishing && !activity.isDestroyed) {
                                    isEnabled = true
                                    text = "هدیه دریافت شد ✓"
                                    Toast.makeText(
                                        activity,
                                        "${config.rewardedBonusMinutes} دقیقه به زمان رایگان اضافه شد",
                                        Toast.LENGTH_LONG,
                                    ).show()
                                }
                            },
                            onUnavailable = {
                                if (!activity.isFinishing && !activity.isDestroyed) {
                                    isEnabled = true
                                    text = "فعلاً تبلیغ جایزه‌ای موجود نیست"
                                }
                            },
                        )
                    }
                },
                LinearLayout.LayoutParams(
                    ViewGroup.LayoutParams.MATCH_PARENT,
                    dp(activity, 52),
                ).apply { bottomMargin = dp(activity, 12) },
            )
        }

        config.zones["standard_banner"]?.takeIf { it.isNotBlank() }?.let { zone ->
            root.addView(sectionLabel(activity, "▰ بنر استاندارد"))
            val holder = FrameLayout(activity).apply { visibility = View.GONE }
            root.addView(
                holder,
                LinearLayout.LayoutParams(
                    ViewGroup.LayoutParams.MATCH_PARENT,
                    ViewGroup.LayoutParams.WRAP_CONTENT,
                ).apply { bottomMargin = dp(activity, 12) },
            )
            BlueVpnTapsellManager.attachStandardBanner(
                activity = activity,
                host = holder,
                zoneId = zone,
                onCleanup = { cleanup -> cleanups += cleanup },
            )
        }

        config.zones["native_banner"]?.takeIf { it.isNotBlank() }?.let { zone ->
            root.addView(sectionLabel(activity, "▤ بنر همسان"))
            root.addView(
                reflectiveSlot(activity, zone, "native_banner"),
                LinearLayout.LayoutParams(
                    ViewGroup.LayoutParams.MATCH_PARENT,
                    dp(activity, 130),
                ).apply { bottomMargin = dp(activity, 12) },
            )
        }

        config.zones["native_video"]?.takeIf { it.isNotBlank() }?.let { zone ->
            root.addView(sectionLabel(activity, "▶ ویدئوی همسان"))
            root.addView(
                reflectiveSlot(activity, zone, "native_video"),
                LinearLayout.LayoutParams(
                    ViewGroup.LayoutParams.MATCH_PARENT,
                    dp(activity, 210),
                ).apply { bottomMargin = dp(activity, 12) },
            )
        }

        config.zones["pre_roll_video"]?.takeIf { it.isNotBlank() }?.let { zone ->
            root.addView(sectionLabel(activity, "⏵ ویدئوی پیش‌نمایشی"))
            val host = FrameLayout(activity).apply {
                setPadding(dp(activity, 8), dp(activity, 8), dp(activity, 8), dp(activity, 8))
                background = rounded(Color.WHITE, Color.rgb(218, 224, 234))
            }
            host.addView(
                Button(activity).apply {
                    isAllCaps = false
                    text = "نمایش Pre-roll"
                    setOnClickListener {
                        if (!BlueVpnEntitlement.resolveUi(activity).isFree) return@setOnClickListener
                        BlueVpnTapsellManager.showReflectiveFormat(
                            activity = activity,
                            host = host,
                            zoneId = zone,
                            format = "pre_roll_video",
                        )
                    }
                },
                FrameLayout.LayoutParams(
                    ViewGroup.LayoutParams.MATCH_PARENT,
                    dp(activity, 48),
                    Gravity.CENTER,
                ),
            )
            root.addView(
                host,
                LinearLayout.LayoutParams(
                    ViewGroup.LayoutParams.MATCH_PARENT,
                    dp(activity, 136),
                ).apply { bottomMargin = dp(activity, 12) },
            )
        }

        // Interstitial Video + Interstitial Banner are automatic post-connect
        // Free waterfall surfaces and intentionally have no manual button here.
        root.addView(
            Button(activity).apply {
                isAllCaps = false
                text = "بستن"
                setOnClickListener { dialog.dismiss() }
            },
            LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                dp(activity, 50),
            ),
        )

        dialog.setContentView(scroll)
        dialog.setOnDismissListener {
            cleanups.forEach { cleanup -> runCatching(cleanup) }
        }
        dialog.show()
        dialog.window?.apply {
            setBackgroundDrawableResource(android.R.color.transparent)
            setLayout(
                WindowManager.LayoutParams.MATCH_PARENT,
                WindowManager.LayoutParams.MATCH_PARENT,
            )
        }
    }

    private fun reflectiveSlot(
        activity: Activity,
        zoneId: String,
        format: String,
    ): View {
        val host = FrameLayout(activity).apply {
            setPadding(dp(activity, 8), dp(activity, 8), dp(activity, 8), dp(activity, 8))
            background = rounded(Color.WHITE, Color.rgb(218, 224, 234))
        }
        val loading = LinearLayout(activity).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER
            addView(ProgressBar(activity))
            addView(
                title(activity, "در حال دریافت تبلیغ…", 10f, false).apply {
                    gravity = Gravity.CENTER
                },
            )
        }
        host.addView(
            loading,
            FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT,
            ),
        )
        main.post {
            if (!BlueVpnEntitlement.resolveUi(activity).isFree) {
                host.visibility = View.GONE
                return@post
            }
            BlueVpnTapsellManager.showReflectiveFormat(
                activity = activity,
                host = host,
                zoneId = zoneId,
                format = format,
                loadingView = loading,
            )
        }
        return host
    }

    private fun sectionLabel(context: Context, value: String): TextView =
        title(context, value, 13f, true).apply {
            setPadding(0, dp(context, 6), 0, dp(context, 6))
        }

    private fun title(
        context: Context,
        value: String,
        size: Float,
        bold: Boolean,
    ): TextView = TextView(context).apply {
        text = value
        textSize = size
        gravity = Gravity.START or Gravity.CENTER_VERTICAL
        setTextColor(Color.rgb(24, 34, 52))
        if (bold) setTypeface(typeface, android.graphics.Typeface.BOLD)
    }

    private fun rounded(fill: Int, stroke: Int): GradientDrawable =
        GradientDrawable().apply {
            shape = GradientDrawable.RECTANGLE
            cornerRadius = 18f
            setColor(fill)
            setStroke(1, stroke)
        }

    private fun dp(context: Context, value: Int): Int =
        (value * context.resources.displayMetrics.density).toInt()
}
