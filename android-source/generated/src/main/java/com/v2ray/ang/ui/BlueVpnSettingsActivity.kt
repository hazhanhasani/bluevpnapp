package com.v2ray.ang.ui

import android.content.Intent
import android.content.res.ColorStateList
import android.graphics.Color
import android.graphics.Typeface
import android.net.Uri
import android.os.Bundle
import android.view.Gravity
import android.view.View
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import android.widget.Toast
import androidx.lifecycle.lifecycleScope
import com.google.android.material.button.MaterialButton
import com.google.android.material.card.MaterialCardView
import com.v2ray.ang.BuildConfig
import com.v2ray.ang.bluevpn.BlueVpnAi
import com.v2ray.ang.bluevpn.BlueVpnConnectionMode
import com.v2ray.ang.bluevpn.BlueVpnExperience
import com.v2ray.ang.bluevpn.BlueVpnUpdateManager
import com.v2ray.ang.handler.SettingsManager
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL

class BlueVpnSettingsActivity : HelperBaseActivity() {

    private var firstResume = true

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        window.statusBarColor = Color.parseColor("#07152F")
        window.navigationBarColor = Color.parseColor("#07152F")
        setContentView(createScreen())
    }

    override fun onResume() {
        super.onResume()
        if (firstResume) {
            firstResume = false
            return
        }
        setContentView(createScreen())
    }

    private fun createScreen(): View {
        val root = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(20), dp(18), dp(20), dp(22))
            setBackgroundColor(Color.parseColor("#071A39"))
            layoutDirection = View.LAYOUT_DIRECTION_RTL
        }

        val header = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
        }
        val back = MaterialButton(this).apply {
            text = "بازگشت"
            textSize = 13f
            setTextColor(Color.WHITE)
            backgroundTintList = ColorStateList.valueOf(Color.parseColor("#173B70"))
            cornerRadius = dp(14)
            setOnClickListener { finish() }
        }
        val title = TextView(this).apply {
            text = "تنظیمات BlueVPN"
            textSize = 24f
            gravity = Gravity.END
            setTextColor(Color.WHITE)
            setTypeface(typeface, Typeface.BOLD)
        }
        header.addView(back, LinearLayout.LayoutParams(dp(92), dp(48)))
        header.addView(title, LinearLayout.LayoutParams(0, dp(52), 1f))
        root.addView(header)

        val subtitle = TextView(this).apply {
            text = "شخصی‌سازی اتصال، کیفیت و تجربه BlueVPN ${BuildConfig.VERSION_NAME}"
            textSize = 13f
            setTextColor(Color.parseColor("#9FB7D9"))
            setPadding(0, dp(8), 0, dp(16))
        }
        root.addView(subtitle)

        val content = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
        }
        content.addView(actionCard(
            "BlueAI Intelligence",
            "${BlueVpnAi.localSummary(this)}\n\nیادگیری از اپراتور، پایداری، مدت اتصال و تجربه کاربران مشابه",
            "بازکردن"
        ) {
            startActivity(Intent(this, BlueVpnAiActivity::class.java))
        })
        content.addView(actionCard(
            "لوکیشن‌ها و انتخاب خودکار",
            "انتخاب دستی کشور یا بهترین سرور از میان همه لوکیشن‌ها",
            "بازکردن"
        ) {
            startActivity(Intent(this, BlueVpnServersActivity::class.java))
        })
        content.addView(infoCard(
            "حالت اتصال",
            if (SettingsManager.isVpnMode()) "VPN تمام دستگاه" else "حالت پروکسی",
            "اتصال توسط موتور امن Xray انجام می‌شود."
        ))
        val selectedMode = BlueVpnExperience.mode(this)
        content.addView(infoCard(
            "پروفایل هوشمند",
            selectedMode.title,
            selectedMode.description
        ))
        content.addView(infoCard(
            "لوکیشن‌های مورد علاقه",
            "${BlueVpnExperience.favoritesCount(this)} لوکیشن",
            "لوکیشن‌های ستاره‌دار در انتخاب هوشمند اولویت بیشتری دارند."
        ))
        content.addView(infoCard(
            "آخرین اتصال",
            BlueVpnExperience.recentSummary(this),
            BlueVpnExperience.historyDescription(this)
        ))
        content.addView(actionCard(
            "پاک‌کردن تاریخچه",
            "فقط سوابق اتصال ذخیره‌شده روی همین دستگاه حذف می‌شود.",
            "پاک‌کردن"
        ) {
            BlueVpnExperience.clearHistory(this)
            Toast.makeText(
                this,
                "تاریخچه اتصال پاک شد",
                Toast.LENGTH_SHORT,
            ).show()
            setContentView(createScreen())
        })
        content.addView(infoCard(
            "همگام‌سازی اشتراک",
            "هنگام اجرای تازه برنامه",
            "اشتراک و سرورها فقط بعد از بسته‌شدن کامل و اجرای دوباره برنامه همگام می‌شوند."
        ))
        content.addView(actionCard(
            "پشتیبانی",
            "ارتباط مستقیم با پشتیبانی BlueVPN",
            "بازکردن"
        ) { openRemoteLink("support_url") })
        content.addView(actionCard(
            "تمدید اشتراک",
            "خرید یا تمدید سرویس",
            "تمدید"
        ) { openRemoteLink("renew_url") })
        content.addView(actionCard(
            "بررسی بروزرسانی",
            "نسخه نصب‌شده: ${BuildConfig.VERSION_NAME}",
            "بررسی"
        ) {
            BlueVpnUpdateManager.check(
                this,
                force = true,
                showStatus = true,
            )
        })
        content.addView(infoCard(
            "نسخه برنامه",
            BuildConfig.VERSION_NAME,
            "نسخه‌های جدید مستقیماً از GitHub Releases دریافت می‌شوند."
        ))

        val scroll = ScrollView(this).apply {
            isFillViewport = true
            addView(content)
        }
        root.addView(scroll, LinearLayout.LayoutParams(-1, 0, 1f))
        return root
    }


    private fun infoCard(title: String, value: String, description: String): View {
        val card = baseCard()
        val box = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(18), dp(17), dp(18), dp(17))
        }
        box.addView(TextView(this).apply {
            text = title
            textSize = 13f
            setTextColor(Color.parseColor("#90A8CC"))
        })
        box.addView(TextView(this).apply {
            text = value
            textSize = 18f
            setTextColor(Color.WHITE)
            setTypeface(typeface, Typeface.BOLD)
            setPadding(0, dp(6), 0, 0)
        })
        box.addView(TextView(this).apply {
            text = description
            textSize = 12f
            setTextColor(Color.parseColor("#9FB7D9"))
            setPadding(0, dp(7), 0, 0)
        })
        card.addView(box)
        return card
    }

    private fun actionCard(
        title: String,
        description: String,
        buttonText: String,
        action: () -> Unit
    ): View {
        val card = baseCard()
        val row = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            setPadding(dp(18), dp(16), dp(18), dp(16))
        }
        val textBox = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
        }
        textBox.addView(TextView(this).apply {
            text = title
            textSize = 17f
            setTextColor(Color.WHITE)
            setTypeface(typeface, Typeface.BOLD)
        })
        textBox.addView(TextView(this).apply {
            text = description
            textSize = 12f
            setTextColor(Color.parseColor("#9FB7D9"))
            setPadding(0, dp(6), 0, 0)
        })
        val button = MaterialButton(this).apply {
            text = buttonText
            textSize = 12f
            setTextColor(Color.WHITE)
            backgroundTintList = ColorStateList.valueOf(Color.parseColor("#1676FF"))
            cornerRadius = dp(13)
            setOnClickListener { action() }
        }
        row.addView(textBox, LinearLayout.LayoutParams(0, -2, 1f))
        row.addView(button, LinearLayout.LayoutParams(dp(104), dp(44)))
        card.addView(row)
        return card
    }

    private fun baseCard(): MaterialCardView =
        MaterialCardView(this).apply {
            radius = dp(20).toFloat()
            cardElevation = 0f
            setCardBackgroundColor(Color.parseColor("#102A55"))
            strokeColor = Color.parseColor("#214A83")
            strokeWidth = dp(1)
            layoutParams = LinearLayout.LayoutParams(-1, -2).apply {
                bottomMargin = dp(12)
            }
        }


    private fun openRemoteLink(field: String) {
        lifecycleScope.launch(Dispatchers.IO) {
            val link = runCatching {
                val base = BuildConfig.BLUEVPN_API_BASE_URL.trimEnd('/')
                if (base.isBlank()) return@runCatching ""
                val connection = URL("$base/api/v1/mobile/config").openConnection()
                    as HttpURLConnection
                connection.connectTimeout = 8000
                connection.readTimeout = 8000
                connection.requestMethod = "GET"
                connection.inputStream.bufferedReader().use {
                    JSONObject(it.readText()).optString(field, "")
                }
            }.getOrDefault("")

            withContext(Dispatchers.Main) {
                if (link.startsWith("http://") || link.startsWith("https://")) {
                    startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(link)))
                } else {
                    Toast.makeText(
                        this@BlueVpnSettingsActivity,
                        "این لینک هنوز از پنل مدیریت تنظیم نشده است",
                        Toast.LENGTH_SHORT
                    ).show()
                }
            }
        }
    }

    private fun dp(value: Int): Int =
        (value * resources.displayMetrics.density).toInt()
}
