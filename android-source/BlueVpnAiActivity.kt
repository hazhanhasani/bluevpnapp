package com.v2ray.ang.ui

import android.content.Intent
import android.content.res.ColorStateList
import android.graphics.Color
import android.graphics.Typeface
import android.os.Bundle
import android.view.Gravity
import android.view.View
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AlertDialog
import androidx.lifecycle.lifecycleScope
import com.google.android.material.button.MaterialButton
import com.google.android.material.card.MaterialCardView
import com.google.android.material.switchmaterial.SwitchMaterial
import com.v2ray.ang.BuildConfig
import com.v2ray.ang.bluevpn.BlueVpnAccountManager
import com.v2ray.ang.bluevpn.BlueVpnAi
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONObject

class BlueVpnAiActivity : HelperBaseActivity() {
    private lateinit var content: LinearLayout

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        window.statusBarColor = Color.parseColor("#050B19")
        window.navigationBarColor = Color.parseColor("#050B19")
        setContentView(createScreen())
        refreshDashboard()
    }

    private fun createScreen(): View {
        val root = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(18), dp(16), dp(18), dp(22))
            setBackgroundColor(Color.parseColor("#07142D"))
            layoutDirection = View.LAYOUT_DIRECTION_RTL
        }
        val header = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
        }
        header.addView(MaterialButton(this).apply {
            text = "بازگشت"
            textSize = 12f
            cornerRadius = dp(14)
            backgroundTintList = ColorStateList.valueOf(Color.parseColor("#183B70"))
            setOnClickListener { finish() }
        }, LinearLayout.LayoutParams(dp(88), dp(46)))
        header.addView(TextView(this).apply {
            text = "BlueAI Intelligence"
            textSize = 23f
            gravity = Gravity.END
            setTextColor(Color.WHITE)
            setTypeface(typeface, Typeface.BOLD)
        }, LinearLayout.LayoutParams(0, dp(52), 1f))
        root.addView(header)

        val hero = MaterialCardView(this).apply {
            radius = dp(26).toFloat()
            strokeWidth = dp(1)
            strokeColor = Color.parseColor("#315F9D")
            setCardBackgroundColor(Color.parseColor("#122B58"))
        }
        val heroBody = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER
            setPadding(dp(20), dp(22), dp(20), dp(22))
        }
        heroBody.addView(TextView(this).apply {
            text = "🧠"
            textSize = 42f
            gravity = Gravity.CENTER
        })
        heroBody.addView(TextView(this).apply {
            text = "مسیریابی هوشمند فعال"
            textSize = 21f
            gravity = Gravity.CENTER
            setTextColor(Color.WHITE)
            setTypeface(typeface, Typeface.BOLD)
        })
        heroBody.addView(TextView(this).apply {
            text = BlueVpnAi.localSummary(this@BlueVpnAiActivity)
            textSize = 12f
            gravity = Gravity.CENTER
            setTextColor(Color.parseColor("#9FC3F0"))
            setPadding(0, dp(8), 0, dp(12))
        })
        heroBody.addView(SwitchMaterial(this).apply {
            text = "یادگیری فنی ناشناس"
            isChecked = BlueVpnAi.enabled(this@BlueVpnAiActivity)
            setTextColor(Color.WHITE)
            setOnCheckedChangeListener { _, checked ->
                BlueVpnAi.setEnabled(this@BlueVpnAiActivity, checked)
                Toast.makeText(
                    this@BlueVpnAiActivity,
                    if (checked) "BlueAI فعال شد" else "BlueAI غیرفعال شد",
                    Toast.LENGTH_SHORT,
                ).show()
                refreshDashboard()
            }
        })
        hero.addView(heroBody)
        root.addView(hero, LinearLayout.LayoutParams(-1, -2).apply { topMargin = dp(12) })

        content = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL }
        val scroll = ScrollView(this).apply { isFillViewport = true; addView(content) }
        root.addView(scroll, LinearLayout.LayoutParams(-1, 0, 1f).apply { topMargin = dp(12) })
        return root
    }

    private fun refreshDashboard() {
        content.removeAllViews()
        content.addView(infoCard("شبکه فعلی", BlueVpnAi.localSummary(this), "اپراتور و نوع شبکه بدون ثبت شماره یا محتوای ترافیک"))
        content.addView(infoCard("حریم خصوصی", "فقط داده فنی", "محتوای ترافیک، سایت‌های مقصد و پیام‌های کاربر جمع‌آوری نمی‌شوند."))
        content.addView(actionCard("بروزرسانی رتبه‌بندی", "دریافت بهترین مسیرها برای همین اپراتور و ساعت", "تحلیل") {
            lifecycleScope.launch(Dispatchers.IO) {
                val result = BlueVpnAi.refreshRecommendations(this@BlueVpnAiActivity, true)
                withContext(Dispatchers.Main) {
                    Toast.makeText(this@BlueVpnAiActivity, result.fold({ "$it مسیر تحلیل شد" }, { it.message ?: "خطا" }), Toast.LENGTH_LONG).show()
                    refreshDashboard()
                }
            }
        })
        val top = BlueVpnAi.cachedTopRoutes(this)
        content.addView(infoCard(
            "رتبه‌بندی کشورها",
            if (top.isEmpty()) "در انتظار داده" else top.joinToString("   ") { "${it.first.uppercase()} ${it.second}" },
            "امتیاز از ترکیب تجربه شخصی و کاربران مشابه ساخته می‌شود."
        ))
        content.addView(actionCard("داشبورد حساب", "تعداد اتصال‌ها، مدت استفاده و بهترین مسیر شخصی", "دریافت") {
            lifecycleScope.launch(Dispatchers.IO) {
                val result = BlueVpnAccountManager.aiDashboard(this@BlueVpnAiActivity)
                withContext(Dispatchers.Main) {
                    result.onSuccess { response -> showDashboard(response.optJSONObject("dashboard") ?: JSONObject()) }
                        .onFailure { Toast.makeText(this@BlueVpnAiActivity, it.message ?: "خطا", Toast.LENGTH_LONG).show() }
                }
            }
        })
        content.addView(actionCard("ارسال بازخورد", "گزارش تجربه اتصال و کمک به بهترشدن نسخه بعدی", "ارسال") { showFeedbackDialog() })
        content.addView(actionCard("پاک‌کردن یادگیری دستگاه", "امتیازها و حافظه محلی BlueAI پاک می‌شوند.", "پاک‌کردن") {
            BlueVpnAi.clearLearning(this)
            refreshDashboard()
        })
    }

    private fun showDashboard(data: JSONObject) {
        val best = data.optJSONObject("best_location") ?: JSONObject()
        AlertDialog.Builder(this)
            .setTitle("گزارش BlueAI")
            .setMessage(
                "رویدادهای یادگیری: ${data.optInt("learning_events")}\n" +
                    "اتصال موفق: ${data.optInt("successful_sessions")}\n" +
                    "نرخ موفقیت: ${data.optDouble("success_rate")}٪\n" +
                    "میانگین پینگ: ${data.optDouble("average_ping_ms")} ms\n" +
                    "بهترین لوکیشن: ${best.optString("title", "در انتظار داده")}"
            )
            .setPositiveButton("باشه", null)
            .show()
    }

    private fun showFeedbackDialog() {
        val input = EditText(this).apply { hint = "تجربه یا باگ را بنویسید"; minLines = 4; setTextColor(Color.WHITE); setHintTextColor(Color.GRAY) }
        AlertDialog.Builder(this)
            .setTitle("بازخورد BlueVPN ${BuildConfig.VERSION_NAME}")
            .setView(input)
            .setPositiveButton("ارسال") { _, _ ->
                lifecycleScope.launch(Dispatchers.IO) {
                    val result = BlueVpnAccountManager.submitFeedback(
                        this@BlueVpnAiActivity,
                        JSONObject().put("rating", 5).put("category", "android").put("message", input.text.toString()).put("app_version", BuildConfig.VERSION_NAME).put("diagnostics", JSONObject().put("network", BlueVpnAi.localSummary(this@BlueVpnAiActivity))),
                    )
                    withContext(Dispatchers.Main) { Toast.makeText(this@BlueVpnAiActivity, if (result.isSuccess) "بازخورد ارسال شد" else "ارسال ناموفق بود", Toast.LENGTH_LONG).show() }
                }
            }
            .setNegativeButton("انصراف", null)
            .show()
    }

    private fun infoCard(title: String, value: String, description: String): View = card(title, value, description, null, null)
    private fun actionCard(title: String, value: String, description: String, action: () -> Unit): View = card(title, value, description, "بازکردن", action)
    private fun card(title: String, value: String, description: String, button: String?, action: (() -> Unit)?): View {
        val card = MaterialCardView(this).apply { radius = dp(20).toFloat(); strokeWidth = dp(1); strokeColor = Color.parseColor("#285991"); setCardBackgroundColor(Color.parseColor("#0E2852")) }
        val body = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(dp(16), dp(15), dp(16), dp(15)) }
        body.addView(TextView(this).apply { text = title; textSize = 15f; setTextColor(Color.WHITE); setTypeface(typeface, Typeface.BOLD) })
        body.addView(TextView(this).apply { text = value; textSize = 13f; setTextColor(Color.parseColor("#6FE1E8")); setPadding(0, dp(7), 0, dp(4)) })
        body.addView(TextView(this).apply { text = description; textSize = 11f; setTextColor(Color.parseColor("#9FB7D9")) })
        if (action != null) body.addView(MaterialButton(this).apply { text = button ?: "بازکردن"; cornerRadius = dp(13); backgroundTintList = ColorStateList.valueOf(Color.parseColor("#347CFF")); setOnClickListener { action() } }, LinearLayout.LayoutParams(-1, dp(46)).apply { topMargin = dp(12) })
        card.addView(body)
        card.layoutParams = LinearLayout.LayoutParams(-1, -2).apply { bottomMargin = dp(12) }
        return card
    }
    private fun dp(value: Int): Int = (value * resources.displayMetrics.density).toInt()
}
