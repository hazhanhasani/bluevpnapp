package com.v2ray.ang.ui

import android.content.Intent
import android.content.res.ColorStateList
import android.graphics.Color
import android.graphics.Typeface
import android.graphics.drawable.ColorDrawable
import android.graphics.drawable.GradientDrawable
import android.net.Uri
import android.os.Bundle
import android.view.Gravity
import android.view.View
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AlertDialog
import androidx.lifecycle.lifecycleScope
import com.google.android.material.button.MaterialButton
import com.google.android.material.card.MaterialCardView
import com.v2ray.ang.BuildConfig
import com.v2ray.ang.bluevpn.BlueVpnAccountManager
import com.v2ray.ang.bluevpn.BlueVpnBackgroundReliability
import com.v2ray.ang.bluevpn.BlueVpnBackgroundOptimizer
import com.v2ray.ang.bluevpn.BlueVpnEntitlement
import com.v2ray.ang.bluevpn.BlueVpnPalette
import com.v2ray.ang.bluevpn.BlueVpnTheme
import com.v2ray.ang.bluevpn.BlueVpnThemeMode
import com.v2ray.ang.bluevpn.BlueVpnUpdateManager
import com.v2ray.ang.bluevpn.BlueVpnUiGuard
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL

class BlueVpnSettingsActivity : HelperBaseActivity() {

    private lateinit var palette: BlueVpnPalette
    private var themeDarkAtCreate = true
    private var remoteLinkInProgress = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        window.setWindowAnimations(0)
        applyThemeInPlace()
    }

    override fun onResume() {
        super.onResume()
        if (BlueVpnTheme.isDark(this) != themeDarkAtCreate) {
            applyThemeInPlace()
        } else {
            BlueVpnTheme.applySystemBars(this)
        }
        BlueVpnBackgroundReliability.observeAndMaybeOptimize(this)
    }

    private fun applyThemeInPlace() {
        palette = BlueVpnTheme.palette(this)
        themeDarkAtCreate = palette.dark
        window.setBackgroundDrawable(ColorDrawable(palette.background))
        BlueVpnTheme.applySystemBars(this)
        setContentView(createScreen())
    }

    private fun createScreen(): View {
        palette = BlueVpnTheme.palette(this)
        val frame = FrameLayout(this).apply {
            setBackgroundColor(palette.background)
        }

        val root = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(18), dp(12), dp(18), dp(18))
            layoutDirection = View.LAYOUT_DIRECTION_RTL
        }
        frame.addView(root, FrameLayout.LayoutParams(-1, -1))

        val header = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
        }
        val back = MaterialButton(this).apply {
            text = "بازگشت"
            textSize = 12f
            isAllCaps = false
            insetTop = 0
            insetBottom = 0
            setTextColor(palette.textPrimary)
            backgroundTintList = ColorStateList.valueOf(palette.surfaceStrong)
            strokeColor = ColorStateList.valueOf(palette.stroke)
            strokeWidth = dp(1)
            cornerRadius = dp(16)
            BlueVpnUiGuard.bind(this) { finish() }
        }
        val title = TextView(this).apply {
            text = "تنظیمات"
            textSize = 25f
            gravity = Gravity.END or Gravity.CENTER_VERTICAL
            setTextColor(palette.textPrimary)
            setTypeface(typeface, Typeface.BOLD)
            includeFontPadding = false
        }
        header.addView(back, LinearLayout.LayoutParams(dp(90), dp(46)))
        header.addView(title, LinearLayout.LayoutParams(0, dp(50), 1f))
        root.addView(header)

        val content = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(0, dp(10), 0, dp(24))
        }

        sectionLabel(content, "حساب کاربری")
        val snapshot = BlueVpnAccountManager.snapshot(this)
        val entitlement = BlueVpnEntitlement.resolveUi(this)
        content.addView(
            settingRow(
                title = "حساب BlueVPN",
                value = snapshot.email.ifBlank { "ورود یا ثبت‌نام" },
                description = entitlement.accountLabel,
            ) {
                BlueVpnUiGuard.start(
                    this,
                    Intent(this, BlueVpnSubscriptionsActivity::class.java),
                )
            },
        )

        sectionLabel(content, "ظاهر")
        content.addView(
            settingRow(
                title = "تم برنامه",
                value = BlueVpnTheme.mode(this).title,
                description = "تیره، روشن یا هماهنگ با دستگاه",
            ) { showThemeChooser() },
        )

        sectionLabel(content, "اتصال")
        val backgroundState = BlueVpnBackgroundReliability.state(this)
        content.addView(
            settingRow(
                title = "پایداری اتصال در پس‌زمینه",
                value = backgroundState.title,
                description = backgroundState.description,
            ) { showBackgroundReliability() },
        )
        content.addView(
            settingRow(
                title = "انتخاب خودکار بهترین سرور",
                value = "فعال",
                description = "کیفیت اتصال در پس‌زمینه و به‌صورت لحظه‌ای بررسی می‌شود",
                showArrow = false,
            ),
        )
        content.addView(
            settingRow(
                title = "مکان اتصال",
                value = "انتخاب خودکار یا دستی",
                description = "کشورها و سرورهای در دسترس",
            ) {
                BlueVpnUiGuard.start(
                    this,
                    Intent(this, BlueVpnServersActivity::class.java),
                )
            },
        )
        content.addView(
            settingRow(
                title = "تشخیص شبکه",
                value = "خودکار",
                description = "شبکه فعال بدون نمایش جزئیات فنی شناسایی می‌شود",
                showArrow = false,
            ),
        )

        sectionLabel(content, "برنامه")
        content.addView(
            settingRow(
                title = "بررسی بروزرسانی",
                value = BuildConfig.VERSION_NAME,
                description = "دریافت آخرین نسخه BlueVPN",
            ) {
                BlueVpnUpdateManager.check(this, force = true, showStatus = true)
            },
        )
        content.addView(
            settingRow(
                title = "تماس با پشتیبانی",
                value = "پاسخ‌گویی مستقیم",
                description = "ارسال سؤال یا گزارش مشکل",
            ) {
                BlueVpnUiGuard.start(
                    this,
                    Intent(this, BlueVpnSupportActivity::class.java),
                )
            },
        )
        content.addView(
            settingRow(
                title = "سیاست حفظ حریم خصوصی",
                value = "مشاهده",
                description = "محتوای ترافیک شما ثبت یا خوانده نمی‌شود",
            ) { showPrivacy() },
        )
        content.addView(
            settingRow(
                title = "شرایط استفاده",
                value = "مشاهده",
                description = "قوانین استفاده از سرویس",
            ) { showTerms() },
        )
        content.addView(
            settingRow(
                title = "نسخه برنامه",
                value = BuildConfig.VERSION_NAME,
                description = "BlueVPN",
                showArrow = false,
            ),
        )

        val scroll = ScrollView(this).apply {
            isFillViewport = true
            overScrollMode = View.OVER_SCROLL_NEVER
            addView(content)
        }
        root.addView(scroll, LinearLayout.LayoutParams(-1, 0, 1f))
        return frame
    }

    private fun sectionLabel(parent: LinearLayout, value: String) {
        parent.addView(
            TextView(this).apply {
                text = value
                textSize = 11f
                setTextColor(palette.textMuted)
                setPadding(dp(7), dp(14), dp(7), dp(7))
            },
        )
    }

    private fun settingRow(
        title: String,
        value: String,
        description: String,
        showArrow: Boolean = true,
        action: (() -> Unit)? = null,
    ): View {
        val card = MaterialCardView(this).apply {
            radius = dp(20).toFloat()
            cardElevation = 0f
            setCardBackgroundColor(palette.surface)
            strokeColor = palette.stroke
            strokeWidth = dp(1)
            isClickable = action != null
            isFocusable = action != null
            if (action != null) BlueVpnUiGuard.bind(this) { action() }
            layoutParams = LinearLayout.LayoutParams(-1, -2).apply {
                bottomMargin = dp(9)
            }
        }
        val row = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            setPadding(dp(16), dp(14), dp(16), dp(14))
        }
        val textBox = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
        }
        textBox.addView(TextView(this).apply {
            text = title
            textSize = 16f
            setTextColor(palette.textPrimary)
            setTypeface(typeface, Typeface.BOLD)
            includeFontPadding = false
        })
        textBox.addView(TextView(this).apply {
            text = description
            textSize = 11f
            setTextColor(palette.textMuted)
            setPadding(0, dp(5), 0, 0)
        })
        row.addView(textBox, LinearLayout.LayoutParams(0, -2, 1f))

        val valueText = TextView(this).apply {
            text = value
            textSize = 11.5f
            gravity = Gravity.CENTER
            setTextColor(if (value == "فعال") palette.success else palette.textSecondary)
            maxLines = 1
            background = GradientDrawable().apply {
                cornerRadius = dp(12).toFloat()
                setColor(palette.surfaceStrong)
                setStroke(dp(1), palette.stroke)
            }
            setPadding(dp(10), 0, dp(10), 0)
        }
        row.addView(valueText, LinearLayout.LayoutParams(-2, dp(36)).apply {
            marginStart = dp(8)
        })
        if (showArrow && action != null) {
            row.addView(TextView(this).apply {
                text = "‹"
                textSize = 24f
                gravity = Gravity.CENTER
                setTextColor(palette.textMuted)
            }, LinearLayout.LayoutParams(dp(28), dp(38)))
        }
        card.addView(row)
        return card
    }

    private fun showBackgroundReliability() {
        val state = BlueVpnBackgroundReliability.state(this)
        val batteryText = if (state.batteryUnrestricted) "بدون محدودیت" else "محدود"
        val dataText = if (state.backgroundDataUnrestricted) "بدون محدودیت" else "محدود"

        val optimizer = BlueVpnBackgroundOptimizer.snapshot(this)
        val optimizerText = when {
            BlueVpnBackgroundOptimizer.isRunning() -> "در حال تست کامل کانفیگ‌ها با شبکه فعلی"
            optimizer != null && optimizer.completedAt > 0L ->
                "آخرین بهینه‌سازی: ${optimizer.tested}/${optimizer.total} • سریع ${optimizer.fast} • پایدار ${optimizer.stable} • ذخیره ${optimizer.reserve} • ناموفق ${optimizer.failed}"
            else -> "هنوز تست کامل پس‌زمینه انجام نشده"
        }

        AlertDialog.Builder(this)
            .setTitle("پایداری اتصال در پس‌زمینه")
            .setMessage(
                "بهینه‌سازی باتری: $batteryText\n" +
                    "داده پس‌زمینه: $dataText\n\n" +
                    "$optimizerText\n\n" +
                    "پس از آزاد شدن محدودیت‌های پس‌زمینه، BlueVPN همه کانفیگ‌های مجاز پلن را با شبکه واقعی همین دستگاه در Batchهای کوچک تست و رتبه‌بندی می‌کند."
            )
            .setPositiveButton(
                if (state.fullyReady) "تست کامل کانفیگ‌ها" else "داده پس‌زمینه"
            ) { _, _ ->
                if (state.fullyReady) {
                    BlueVpnBackgroundOptimizer.forceStart(this)
                    Toast.makeText(this, "تست کامل کانفیگ‌ها در پس‌زمینه شروع شد", Toast.LENGTH_LONG).show()
                } else {
                    BlueVpnBackgroundReliability.openBackgroundDataSettings(this)
                }
            }
            .setNeutralButton("باتری") { _, _ ->
                BlueVpnBackgroundReliability.openBatterySettings(this)
            }
            .setNegativeButton("بستن", null)
            .show()
    }

    private fun showThemeChooser() {
        if (isFinishing || isDestroyed) return
        BlueVpnUiGuard.run(this, "theme-dialog") {
            val values = BlueVpnThemeMode.values()
            val labels = values.map { it.title }.toTypedArray()
            val selected = values.indexOf(BlueVpnTheme.mode(this)).coerceAtLeast(0)
            AlertDialog.Builder(this)
                .setTitle("تم برنامه")
                .setSingleChoiceItems(labels, selected) { dialog, which ->
                    if (which !in values.indices) return@setSingleChoiceItems
                    BlueVpnTheme.setMode(this, values[which])
                    dialog.dismiss()
                    if (!isFinishing && !isDestroyed) applyThemeInPlace()
                }
                .setNegativeButton("انصراف", null)
                .show()
        }
    }

    private fun showPrivacy() {
        AlertDialog.Builder(this)
            .setTitle("حریم خصوصی")
            .setMessage(
                "BlueVPN برای انتخاب اتصال بهتر فقط شاخص‌های فنی کوتاه‌مدت مانند موفق یا ناموفق بودن اتصال را پردازش می‌کند. محتوای وب‌گردی، پیام‌ها و فایل‌های شما خوانده یا ذخیره نمی‌شود."
            )
            .setPositiveButton("متوجه شدم", null)
            .show()
    }

    private fun showTerms() {
        AlertDialog.Builder(this)
            .setTitle("شرایط استفاده")
            .setMessage(
                "استفاده از BlueVPN باید مطابق قوانین محل زندگی شما و قوانین سرویس باشد. اطلاعات حساب را در اختیار دیگران قرار ندهید و برنامه را فقط از منبع رسمی دریافت کنید."
            )
            .setPositiveButton("بستن", null)
            .show()
    }

    private fun openRemoteLink(field: String) {
        if (remoteLinkInProgress || isFinishing || isDestroyed) return
        remoteLinkInProgress = true
        lifecycleScope.launch(Dispatchers.IO) {
            val link = BlueVpnAccountManager.mobileConfig(
                this@BlueVpnSettingsActivity,
                force = false,
            ).getOrNull()?.optString(field, "").orEmpty()

            withContext(Dispatchers.Main) {
                remoteLinkInProgress = false
                if (isFinishing || isDestroyed) return@withContext
                if (link.startsWith("http://") || link.startsWith("https://")) {
                    BlueVpnUiGuard.start(
                        this@BlueVpnSettingsActivity,
                        Intent(Intent.ACTION_VIEW, Uri.parse(link)),
                    )
                } else {
                    Toast.makeText(
                        this@BlueVpnSettingsActivity,
                        "لینک پشتیبانی هنوز از پنل مدیریت تنظیم نشده است",
                        Toast.LENGTH_SHORT,
                    ).show()
                }
            }
        }
    }

    private fun dp(value: Int): Int =
        (value * resources.displayMetrics.density).toInt()
}
