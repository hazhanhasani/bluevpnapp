package com.v2ray.ang.ui

import android.app.Activity
import android.content.Intent
import android.content.res.ColorStateList
import android.graphics.Color
import android.graphics.Typeface
import android.os.Bundle
import android.text.Editable
import android.text.TextWatcher
import android.view.Gravity
import android.view.View
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import android.widget.Toast
import androidx.activity.viewModels
import com.google.android.material.button.MaterialButton
import com.google.android.material.card.MaterialCardView
import com.google.android.material.switchmaterial.SwitchMaterial
import com.v2ray.ang.bluevpn.BlueVpnConnectionMode
import com.v2ray.ang.bluevpn.BlueVpnExperience
import com.v2ray.ang.bluevpn.BlueVpnLocation
import com.v2ray.ang.bluevpn.BlueVpnLocationUtil
import com.v2ray.ang.bluevpn.BlueVpnPreferences
import com.v2ray.ang.handler.MmkvManager
import com.v2ray.ang.viewmodel.MainViewModel
import java.util.Locale

class BlueVpnServersActivity : HelperBaseActivity() {

    companion object {
        const val EXTRA_LOCATION_CHANGED =
            "bluevpn.extra.LOCATION_CHANGED"
        const val EXTRA_LOCATION_KEY =
            "bluevpn.extra.LOCATION_KEY"
        const val EXTRA_LOCATION_TITLE =
            "bluevpn.extra.LOCATION_TITLE"
    }

    private val mainViewModel: MainViewModel by viewModels()
    private lateinit var listContainer: LinearLayout
    private lateinit var emptyText: TextView
    private lateinit var refreshButton: MaterialButton
    private lateinit var favoritesButton: MaterialButton
    private val modeButtons =
        mutableMapOf<BlueVpnConnectionMode, MaterialButton>()
    private var query = ""
    private var favoritesOnly = false
    private var firstResume = true

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        window.statusBarColor = Color.parseColor("#07152F")
        window.navigationBarColor = Color.parseColor("#07152F")
        setContentView(createScreen())
        updateFavoritesButton()

        mainViewModel.startListenBroadcast()
        mainViewModel.updateListAction.observe(this) {
            BlueVpnLocationUtil.invalidateCache()
            refreshButton.isEnabled = true
            refreshButton.text = "بررسی همه سرورها"
            renderLocations()
        }
        mainViewModel.updateTestResultAction.observe(this) {
            BlueVpnLocationUtil.invalidateCache()
            if (BlueVpnPreferences.smartBalance(this)) {
                BlueVpnLocationUtil
                    .orderedCandidates(this)
                    .firstOrNull()
                    ?.let {
                        MmkvManager.setSelectServer(it.guid)
                    }
            }
            renderLocations()
        }

        renderLocations()
    }

    override fun onResume() {
        super.onResume()
        if (firstResume) {
            firstResume = false
        } else {
            renderLocations()
        }
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
            backgroundTintList =
                ColorStateList.valueOf(Color.parseColor("#173B70"))
            cornerRadius = dp(14)
            setOnClickListener { finish() }
        }

        val title = TextView(this).apply {
            text = "انتخاب لوکیشن"
            textSize = 24f
            setTextColor(Color.WHITE)
            setTypeface(typeface, Typeface.BOLD)
            gravity = Gravity.END
        }

        header.addView(back, LinearLayout.LayoutParams(dp(92), dp(48)))
        header.addView(title, LinearLayout.LayoutParams(0, dp(52), 1f))
        root.addView(header)

        root.addView(TextView(this).apply {
            text = "حالت خودکار همه مسیرها را با اینترنت شما ارزیابی می‌کند. انتخاب یک کشور، حالت دستی را فعال می‌کند."
            textSize = 12.5f
            setTextColor(Color.parseColor("#9FB7D9"))
            setPadding(0, dp(7), 0, dp(13))
        })

        root.addView(modeSelectorCard())
        root.addView(automaticServerCard())

        favoritesButton = MaterialButton(this).apply {
            text = "☆ نمایش همه لوکیشن‌ها"
            textSize = 12.5f
            setTextColor(Color.WHITE)
            backgroundTintList =
                ColorStateList.valueOf(
                    Color.parseColor("#152F5A")
                )
            strokeWidth = dp(1)
            strokeColor =
                ColorStateList.valueOf(
                    Color.parseColor("#315F99")
                )
            cornerRadius = dp(15)
            isAllCaps = false
            setOnClickListener {
                favoritesOnly = !favoritesOnly
                updateFavoritesButton()
                renderLocations()
            }
        }
        root.addView(
            favoritesButton,
            LinearLayout.LayoutParams(-1, dp(46)).apply {
                bottomMargin = dp(10)
            }
        )

        val search = EditText(this).apply {
            hint = "جست‌وجوی نام لوکیشن"
            setHintTextColor(Color.parseColor("#7894BD"))
            setTextColor(Color.WHITE)
            textSize = 14f
            setSingleLine(true)
            backgroundTintList =
                ColorStateList.valueOf(Color.parseColor("#2E64AA"))
            setPadding(dp(16), 0, dp(16), 0)
            addTextChangedListener(object : TextWatcher {
                override fun beforeTextChanged(
                    s: CharSequence?,
                    start: Int,
                    count: Int,
                    after: Int,
                ) = Unit

                override fun onTextChanged(
                    s: CharSequence?,
                    start: Int,
                    before: Int,
                    count: Int,
                ) {
                    query = BlueVpnLocationUtil.normalizeForSearch(
                        s?.toString()
                    )
                    renderLocations()
                }

                override fun afterTextChanged(s: Editable?) = Unit
            })
        }
        root.addView(search, LinearLayout.LayoutParams(-1, dp(54)))

        refreshButton = MaterialButton(this).apply {
            text = "بررسی همه سرورها"
            textSize = 14f
            setTextColor(Color.WHITE)
            backgroundTintList =
                ColorStateList.valueOf(Color.parseColor("#1676FF"))
            cornerRadius = dp(16)
            setOnClickListener {
                isEnabled = false
                text = "در حال تست همه سرورها..."
                mainViewModel.reloadServerList()
                mainViewModel.testAllRealPing()
            }
        }
        root.addView(
            refreshButton,
            LinearLayout.LayoutParams(-1, dp(50)).apply {
                topMargin = dp(10)
                bottomMargin = dp(10)
            }
        )

        emptyText = TextView(this).apply {
            text = "لوکیشنی پیدا نشد."
            textSize = 15f
            gravity = Gravity.CENTER
            setTextColor(Color.parseColor("#9FB7D9"))
            visibility = View.GONE
            setPadding(0, dp(36), 0, dp(36))
        }
        root.addView(emptyText)

        listContainer = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
        }

        val scroll = ScrollView(this).apply {
            isFillViewport = true
            addView(listContainer)
        }
        root.addView(scroll, LinearLayout.LayoutParams(-1, 0, 1f))
        return root
    }

private fun modeSelectorCard(): View {
    val card = MaterialCardView(this).apply {
        radius = dp(22).toFloat()
        cardElevation = 0f
        setCardBackgroundColor(Color.parseColor("#0E2852"))
        strokeWidth = dp(1)
        strokeColor = Color.parseColor("#285991")
        layoutParams =
            LinearLayout.LayoutParams(-1, -2).apply {
                bottomMargin = dp(12)
            }
    }

    val box = LinearLayout(this).apply {
        orientation = LinearLayout.VERTICAL
        setPadding(dp(16), dp(15), dp(16), dp(15))
    }

    box.addView(TextView(this).apply {
        text = "حالت هوشمند اتصال"
        textSize = 16f
        setTextColor(Color.WHITE)
        setTypeface(typeface, Typeface.BOLD)
    })

    box.addView(TextView(this).apply {
        text = "الگوریتم انتخاب سرور را متناسب با استفاده خودت تنظیم کن."
        textSize = 11.5f
        setTextColor(Color.parseColor("#91ABD0"))
        setPadding(0, dp(5), 0, dp(12))
    })

    val row = LinearLayout(this).apply {
        orientation = LinearLayout.HORIZONTAL
        gravity = Gravity.CENTER
    }

    listOf(
        BlueVpnConnectionMode.BALANCED,
        BlueVpnConnectionMode.GAMING,
        BlueVpnConnectionMode.STREAMING,
    ).forEachIndexed { index, mode ->
        val button = MaterialButton(this).apply {
            text = when (mode) {
                BlueVpnConnectionMode.BALANCED -> "⚖ متعادل"
                BlueVpnConnectionMode.GAMING -> "🎮 بازی"
                BlueVpnConnectionMode.STREAMING -> "▶ پخش"
            }
            textSize = 11.5f
            isAllCaps = false
            cornerRadius = dp(14)
            setOnClickListener {
                BlueVpnExperience.setMode(
                    this@BlueVpnServersActivity,
                    mode,
                )
                updateModeButtons()
                renderLocations()

                if (
                    BlueVpnPreferences.smartBalance(
                        this@BlueVpnServersActivity
                    )
                ) {
                    mainViewModel.testAllRealPing()
                }

                Toast.makeText(
                    this@BlueVpnServersActivity,
                    "${mode.title} فعال شد",
                    Toast.LENGTH_SHORT,
                ).show()
            }
        }

        modeButtons[mode] = button
        row.addView(
            button,
            LinearLayout.LayoutParams(
                0,
                dp(46),
                1f,
            ).apply {
                if (index > 0) {
                    marginStart = dp(6)
                }
            }
        )
    }

    box.addView(row)
    card.addView(box)
    updateModeButtons()
    return card
}

private fun updateModeButtons() {
    val selected = BlueVpnExperience.mode(this)

    modeButtons.forEach { (mode, button) ->
        val active = mode == selected
        button.backgroundTintList =
            ColorStateList.valueOf(
                Color.parseColor(
                    if (active) "#247CFF" else "#173B6C"
                )
            )
        button.strokeWidth = dp(1)
        button.strokeColor =
            ColorStateList.valueOf(
                Color.parseColor(
                    if (active) "#7DB7FF" else "#2D5A91"
                )
            )
        button.setTextColor(
            Color.parseColor(
                if (active) "#FFFFFF" else "#A9C2E5"
            )
        )
    }
}

private fun updateFavoritesButton() {
    if (!::favoritesButton.isInitialized) return

    val count = BlueVpnExperience.favoritesCount(this)
    favoritesButton.text =
        if (favoritesOnly) {
            "★ فقط علاقه‌مندی‌ها ($count)"
        } else {
            "☆ نمایش همه لوکیشن‌ها • $count علاقه‌مندی"
        }

    favoritesButton.backgroundTintList =
        ColorStateList.valueOf(
            Color.parseColor(
                if (favoritesOnly) "#6F4FC9" else "#152F5A"
            )
        )
}

    private fun automaticServerCard(): View {
        val automatic =
            BlueVpnPreferences.smartBalance(this)

        val card = MaterialCardView(this).apply {
            radius = dp(20).toFloat()
            cardElevation = 0f
            setCardBackgroundColor(
                Color.parseColor(
                    if (automatic) "#153E76" else "#102A55"
                )
            )
            strokeWidth = dp(if (automatic) 2 else 1)
            strokeColor = Color.parseColor(
                if (automatic) "#4B9BFF" else "#214A83"
            )
            layoutParams =
                LinearLayout.LayoutParams(-1, -2).apply {
                    bottomMargin = dp(12)
                }
        }

        val row = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            setPadding(dp(17), dp(15), dp(17), dp(15))
        }

        val textBox = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
        }

        textBox.addView(TextView(this).apply {
            text = "انتخاب خودکار سرور"
            textSize = 17f
            setTextColor(Color.WHITE)
            setTypeface(typeface, Typeface.BOLD)
        })

        textBox.addView(TextView(this).apply {
            text =
                "انتخاب سریع‌ترین مسیر فعال از همه لوکیشن‌ها"
            textSize = 11.5f
            setTextColor(Color.parseColor("#9FB7D9"))
            setPadding(0, dp(6), 0, 0)
        })

        val toggle = SwitchMaterial(this).apply {
            isChecked = automatic

            setOnCheckedChangeListener { _, enabled ->
                BlueVpnPreferences.setSmartBalance(
                    this@BlueVpnServersActivity,
                    enabled
                )

                if (enabled) {
                    BlueVpnPreferences.setPreferredLocation(
                        this@BlueVpnServersActivity,
                        ""
                    )

                    BlueVpnLocationUtil
                        .orderedCandidates(
                            this@BlueVpnServersActivity
                        )
                        .firstOrNull()
                        ?.let {
                            MmkvManager.setSelectServer(it.guid)
                        }

                    mainViewModel.testAllRealPing()

                    Toast.makeText(
                        this@BlueVpnServersActivity,
                        "انتخاب خودکار از همه سرورها فعال شد",
                        Toast.LENGTH_SHORT
                    ).show()
                } else {
                    Toast.makeText(
                        this@BlueVpnServersActivity,
                        "یک لوکیشن را به‌صورت دستی انتخاب کنید",
                        Toast.LENGTH_SHORT
                    ).show()
                }

                renderLocations()
            }
        }

        row.addView(
            textBox,
            LinearLayout.LayoutParams(0, -2, 1f)
        )
        row.addView(toggle)
        card.addView(row)
        return card
    }

    private fun renderLocations() {
        if (!::listContainer.isInitialized) return
        listContainer.removeAllViews()

        val selectedGuid = MmkvManager.getSelectServer()
        val selectedLocation = selectedGuid
            ?.let { MmkvManager.decodeServerConfig(it) }
            ?.let {
                BlueVpnLocationUtil.detect(
                    it.remarks,
                    it.server
                ).key
            }

        val automatic =
            BlueVpnPreferences.smartBalance(this)
        val preferred =
            BlueVpnPreferences.preferredLocation(this)

        val groups = BlueVpnLocationUtil
            .allCandidates()
            .groupBy { it.location.key }
            .values
            .map { servers ->
                val location = servers.first().location
                val successful = servers.filter {
                    it.delay > 0L &&
                        !BlueVpnPreferences.isSessionInactive(
                            this,
                            it.guid
                        )
                }
                val inactive = servers.count {
                    BlueVpnPreferences.isSessionInactive(
                        this,
                        it.guid
                    )
                }
                val bestDelay =
                    successful.minOfOrNull { it.delay } ?: 0L

                LocationGroup(
                    location = location,
                    servers = servers,
                    bestDelay = bestDelay,
                    successfulRoutes = successful.size,
                    inactiveRoutes = inactive,
                    healthScore =
                        BlueVpnLocationUtil.locationHealthScore(
                            this,
                            servers,
                        ),
                    favorite =
                        BlueVpnExperience.isFavorite(
                            this,
                            location.key,
                        ),
                )
            }
            .filter { group ->
                val searchable = BlueVpnLocationUtil.normalizeForSearch(
                    "${group.location.title} ${group.location.key}"
                )
                val matchesQuery =
                    query.isBlank() || searchable.contains(query)
                val matchesFavorite =
                    !favoritesOnly || group.favorite
                matchesQuery && matchesFavorite
            }
            .sortedWith(
                compareByDescending<LocationGroup> {
                    it.location.key == preferred ||
                        it.location.key == selectedLocation
                }.thenByDescending {
                    it.favorite
                }.thenByDescending {
                    it.healthScore
                }.thenBy {
                    if (it.bestDelay > 0L) {
                        it.bestDelay
                    } else {
                        Long.MAX_VALUE
                    }
                }.thenBy { it.location.title }
            )

        emptyText.visibility =
            if (groups.isEmpty()) View.VISIBLE else View.GONE

        groups.forEach { group ->
            val active = !automatic && (
                group.location.key == preferred ||
                    (
                        preferred.isBlank() &&
                            group.location.key == selectedLocation
                        )
                )

            val card = MaterialCardView(this).apply {
                radius = dp(20).toFloat()
                cardElevation = 0f
                setCardBackgroundColor(
                    Color.parseColor(
                        when {
                            active -> "#153E76"
                            group.favorite -> "#241F57"
                            else -> "#102A55"
                        }
                    )
                )
                strokeWidth = dp(if (active) 2 else 1)
                strokeColor = Color.parseColor(
                    when {
                        active -> "#4B9BFF"
                        group.favorite -> "#8B74E8"
                        else -> "#214A83"
                    }
                )
                isClickable = true
                isFocusable = true

                setOnClickListener {
                    val currentPreferred =
                        BlueVpnPreferences
                            .preferredLocation(
                                this@BlueVpnServersActivity
                            )
                            .ifBlank {
                                selectedLocation.orEmpty()
                            }

                    val changed =
                        automatic ||
                            currentPreferred != group.location.key

                    BlueVpnPreferences.setSmartBalance(
                        this@BlueVpnServersActivity,
                        false
                    )

                    BlueVpnPreferences.setPreferredLocation(
                        this@BlueVpnServersActivity,
                        group.location.key
                    )

                    val selected =
                        BlueVpnLocationUtil
                            .orderedCandidates(
                                this@BlueVpnServersActivity,
                                group.location.key
                            )
                            .firstOrNull()

                    selected?.let {
                        MmkvManager.setSelectServer(it.guid)
                    }

                    setResult(
                        Activity.RESULT_OK,
                        Intent()
                            .putExtra(
                                EXTRA_LOCATION_CHANGED,
                                changed
                            )
                            .putExtra(
                                EXTRA_LOCATION_KEY,
                                group.location.key
                            )
                            .putExtra(
                                EXTRA_LOCATION_TITLE,
                                "${group.location.flag} ${group.location.title}"
                            )
                    )

                    Toast.makeText(
                        this@BlueVpnServersActivity,
                        if (changed) {
                            "${group.location.title} انتخاب شد"
                        } else {
                            "${group.location.title} از قبل فعال است"
                        },
                        Toast.LENGTH_SHORT
                    ).show()
                    finish()
                }
            }

            val row = LinearLayout(this).apply {
                orientation = LinearLayout.HORIZONTAL
                gravity = Gravity.CENTER_VERTICAL
                setPadding(dp(17), dp(14), dp(17), dp(14))
            }

            row.addView(
                TextView(this).apply {
                    text = group.location.flag
                    textSize = 27f
                    gravity = Gravity.CENTER
                    setBackgroundColor(Color.parseColor("#183D74"))
                },
                LinearLayout.LayoutParams(dp(52), dp(52))
            )

            val content = LinearLayout(this).apply {
                orientation = LinearLayout.VERTICAL
                setPadding(dp(14), 0, dp(14), 0)
            }

            content.addView(TextView(this).apply {
                text = group.location.title
                textSize = 17f
                setTextColor(Color.WHITE)
                setTypeface(typeface, Typeface.BOLD)
                maxLines = 1
                ellipsize = android.text.TextUtils.TruncateAt.END
            })

            content.addView(TextView(this).apply {
                val pingText = if (group.bestDelay > 0L) {
                    "پینگ ذخیره‌شده ${group.bestDelay} ms"
                } else {
                    "بررسی سریع هنگام اتصال"
                }

                val healthyText = when {
                    group.successfulRoutes > 0 ->
                        "${group.successfulRoutes} فعال"
                    group.inactiveRoutes > 0 ->
                        "همه مسیرها فعلاً ناموفق"
                    else ->
                        "در انتظار تست"
                }

                val inactiveText =
                    if (group.inactiveRoutes > 0) {
                        " • ${group.inactiveRoutes} موقتاً کنار گذاشته"
                    } else {
                        ""
                    }

                text =
                    "${group.servers.size} مسیر • $healthyText$inactiveText • امتیاز ${group.healthScore}/100 • $pingText"
                textSize = 11.5f
                setTextColor(Color.parseColor("#9FB7D9"))
                setPadding(0, dp(6), 0, 0)
                maxLines = 1
                ellipsize = android.text.TextUtils.TruncateAt.END
            })

            row.addView(
                content,
                LinearLayout.LayoutParams(0, -2, 1f)
            )

            val actions = LinearLayout(this).apply {
                orientation = LinearLayout.VERTICAL
                gravity = Gravity.CENTER
            }

            actions.addView(MaterialButton(this).apply {
                text = if (group.favorite) "★" else "☆"
                textSize = 20f
                minWidth = 0
                minimumWidth = 0
                minHeight = 0
                minimumHeight = 0
                insetTop = 0
                insetBottom = 0
                setPadding(0, 0, 0, 0)
                isAllCaps = false
                cornerRadius = dp(13)
                backgroundTintList =
                    ColorStateList.valueOf(
                        Color.parseColor(
                            if (group.favorite) {
                                "#7257D6"
                            } else {
                                "#173B6C"
                            }
                        )
                    )
                setTextColor(Color.WHITE)
                setOnClickListener {
                    val enabled =
                        BlueVpnExperience.toggleFavorite(
                            this@BlueVpnServersActivity,
                            group.location.key,
                        )
                    Toast.makeText(
                        this@BlueVpnServersActivity,
                        if (enabled) {
                            "${group.location.title} به علاقه‌مندی‌ها اضافه شد"
                        } else {
                            "${group.location.title} از علاقه‌مندی‌ها حذف شد"
                        },
                        Toast.LENGTH_SHORT,
                    ).show()
                    updateFavoritesButton()
                    renderLocations()
                }
            }, LinearLayout.LayoutParams(dp(42), dp(38)))

            actions.addView(TextView(this).apply {
                text =
                    if (active) {
                        "فعال"
                    } else {
                        BlueVpnExperience.qualityLabel(
                            group.healthScore
                        )
                    }
                textSize = 10.5f
                gravity = Gravity.CENTER
                setTextColor(
                    Color.parseColor(
                        if (active) {
                            "#57E6B0"
                        } else {
                            BlueVpnExperience.qualityColor(
                                group.healthScore
                            )
                        }
                    )
                )
                setTypeface(typeface, Typeface.BOLD)
                setPadding(0, dp(4), 0, 0)
            })

            row.addView(actions)

            card.addView(row)
            listContainer.addView(
                card,
                LinearLayout.LayoutParams(-1, -2).apply {
                    bottomMargin = dp(10)
                }
            )
        }
    }

    private fun dp(value: Int): Int =
        (value * resources.displayMetrics.density).toInt()

    data class LocationGroup(
        val location: BlueVpnLocation,
        val servers: List<BlueVpnLocationUtil.Candidate>,
        val bestDelay: Long,
        val successfulRoutes: Int,
        val inactiveRoutes: Int,
        val healthScore: Int,
        val favorite: Boolean,
    )
}
