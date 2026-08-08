package com.v2ray.ang.ui

import android.app.Activity
import android.content.Intent
import android.content.res.ColorStateList
import android.graphics.Color
import android.graphics.Typeface
import android.graphics.drawable.GradientDrawable
import android.os.Bundle
import android.text.Editable
import android.text.TextWatcher
import android.view.Gravity
import android.view.View
import android.widget.EditText
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import android.widget.Toast
import androidx.activity.viewModels
import com.google.android.material.button.MaterialButton
import com.google.android.material.card.MaterialCardView
import com.v2ray.ang.bluevpn.BlueVpnConnectionMode
import com.v2ray.ang.bluevpn.BlueVpnExperience
import com.v2ray.ang.bluevpn.BlueVpnLocation
import com.v2ray.ang.bluevpn.BlueVpnLocationUtil
import com.v2ray.ang.bluevpn.BlueVpnPreferences
import com.v2ray.ang.handler.MmkvManager
import com.v2ray.ang.viewmodel.MainViewModel

class BlueVpnServersActivity : HelperBaseActivity() {

    companion object {
        const val EXTRA_LOCATION_CHANGED =
            "bluevpn.extra.LOCATION_CHANGED"
        const val EXTRA_LOCATION_KEY =
            "bluevpn.extra.LOCATION_KEY"
        const val EXTRA_LOCATION_TITLE =
            "bluevpn.extra.LOCATION_TITLE"
    }

    private enum class LocationTab {
        ALL,
        FAVORITES,
        RECENT,
    }

    private val mainViewModel: MainViewModel by viewModels()
    private lateinit var listContainer: LinearLayout
    private lateinit var emptyText: TextView
    private lateinit var refreshButton: MaterialButton
    private lateinit var allTabButton: MaterialButton
    private lateinit var favoritesTabButton: MaterialButton
    private lateinit var recentTabButton: MaterialButton
    private val modeButtons =
        mutableMapOf<BlueVpnConnectionMode, MaterialButton>()
    private var selectedTab = LocationTab.ALL
    private var query = ""
    private var firstResume = true

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        window.statusBarColor = Color.parseColor("#09090D")
        window.navigationBarColor = Color.parseColor("#09090D")
        setContentView(createScreen())
        updateTabs()

        mainViewModel.startListenBroadcast()
        mainViewModel.updateListAction.observe(this) {
            BlueVpnLocationUtil.invalidateCache()
            refreshButton.isEnabled = true
            refreshButton.text = "↻"
            renderLocations()
        }
        mainViewModel.updateTestResultAction.observe(this) {
            BlueVpnLocationUtil.invalidateCache()
            if (BlueVpnPreferences.smartBalance(this)) {
                BlueVpnLocationUtil
                    .orderedCandidates(this)
                    .firstOrNull()
                    ?.let { MmkvManager.setSelectServer(it.guid) }
            }
            refreshButton.isEnabled = true
            refreshButton.text = "↻"
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
            setPadding(dp(16), dp(10), dp(16), dp(12))
            setBackgroundColor(Color.parseColor("#09090D"))
            layoutDirection = View.LAYOUT_DIRECTION_RTL
        }

        root.addView(
            createHeader(),
            LinearLayout.LayoutParams(-1, dp(58)),
        )
        root.addView(
            createTabs(),
            LinearLayout.LayoutParams(-1, dp(48)).apply {
                topMargin = dp(8)
            },
        )
        root.addView(
            createSearchField(),
            LinearLayout.LayoutParams(-1, dp(50)).apply {
                topMargin = dp(10)
            },
        )
        root.addView(
            createCompactModeSelector(),
            LinearLayout.LayoutParams(-1, dp(42)).apply {
                topMargin = dp(8)
            },
        )
        root.addView(
            automaticServerCard(),
            LinearLayout.LayoutParams(-1, dp(78)).apply {
                topMargin = dp(10)
                bottomMargin = dp(8)
            },
        )

        emptyText = TextView(this).apply {
            text = "مکانی برای نمایش وجود ندارد."
            textSize = 14f
            gravity = Gravity.CENTER
            setTextColor(Color.parseColor("#858791"))
            visibility = View.GONE
            setPadding(0, dp(34), 0, dp(34))
        }
        root.addView(emptyText)

        listContainer = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(0, 0, 0, dp(12))
        }
        val scroll = ScrollView(this).apply {
            isFillViewport = true
            overScrollMode = View.OVER_SCROLL_NEVER
            addView(listContainer)
        }
        root.addView(scroll, LinearLayout.LayoutParams(-1, 0, 1f))
        return root
    }

    private fun createHeader(): View {
        val row = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
        }

        val titleBox = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER_VERTICAL or Gravity.END
        }
        titleBox.addView(TextView(this).apply {
            text = "مکان  ◉"
            textSize = 25f
            setTextColor(Color.WHITE)
            setTypeface(typeface, Typeface.BOLD)
            gravity = Gravity.END
        })
        titleBox.addView(TextView(this).apply {
            text = "انتخاب هوشمند یا دستی مسیر اتصال"
            textSize = 10.5f
            setTextColor(Color.parseColor("#777A85"))
            gravity = Gravity.END
            setPadding(0, dp(3), 0, 0)
        })
        row.addView(titleBox, LinearLayout.LayoutParams(0, -1, 1f))

        refreshButton = MaterialButton(this).apply {
            text = "↻"
            textSize = 21f
            minWidth = 0
            minimumWidth = 0
            insetTop = 0
            insetBottom = 0
            setPadding(0, 0, 0, 0)
            cornerRadius = dp(16)
            backgroundTintList =
                ColorStateList.valueOf(Color.parseColor("#1B1B21"))
            strokeWidth = dp(1)
            strokeColor =
                ColorStateList.valueOf(Color.parseColor("#2C2D35"))
            setTextColor(Color.parseColor("#A4A6AF"))
            contentDescription = "بررسی دوباره سرورها"
            setOnClickListener {
                isEnabled = false
                text = "…"
                mainViewModel.reloadServerList()
                mainViewModel.testAllRealPing()
            }
        }
        row.addView(
            refreshButton,
            LinearLayout.LayoutParams(dp(46), dp(46)).apply {
                marginStart = dp(8)
            },
        )

        row.addView(
            TextView(this).apply {
                text = "×"
                textSize = 34f
                gravity = Gravity.CENTER
                setTextColor(Color.WHITE)
                isClickable = true
                isFocusable = true
                contentDescription = "بستن"
                background = rounded(
                    Color.parseColor("#24242A"),
                    23,
                    Color.parseColor("#303139"),
                )
                setOnClickListener { finish() }
            },
            LinearLayout.LayoutParams(dp(46), dp(46)).apply {
                marginStart = dp(8)
            },
        )
        return row
    }

    private fun createTabs(): View {
        val container = MaterialCardView(this).apply {
            radius = dp(23).toFloat()
            cardElevation = 0f
            setCardBackgroundColor(Color.parseColor("#15151A"))
            strokeWidth = dp(1)
            strokeColor = Color.parseColor("#25262D")
        }
        val row = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER
            setPadding(dp(4), dp(4), dp(4), dp(4))
        }
        container.addView(row)

        allTabButton = tabButton("مکان‌ها", LocationTab.ALL)
        favoritesTabButton = tabButton("مورد علاقه", LocationTab.FAVORITES)
        recentTabButton = tabButton("اخیر", LocationTab.RECENT)
        row.addView(allTabButton, LinearLayout.LayoutParams(0, -1, 1f))
        row.addView(
            favoritesTabButton,
            LinearLayout.LayoutParams(0, -1, 1f).apply { marginStart = dp(4) },
        )
        row.addView(
            recentTabButton,
            LinearLayout.LayoutParams(0, -1, 0.72f).apply { marginStart = dp(4) },
        )
        return container
    }

    private fun tabButton(
        label: String,
        tab: LocationTab,
    ): MaterialButton = MaterialButton(this).apply {
        text = label
        textSize = 11.5f
        isAllCaps = false
        minWidth = 0
        minimumWidth = 0
        insetTop = 0
        insetBottom = 0
        cornerRadius = dp(19)
        setOnClickListener {
            selectedTab = tab
            updateTabs()
            renderLocations()
        }
    }

    private fun updateTabs() {
        if (!::allTabButton.isInitialized) return
        val favoriteCount = BlueVpnExperience.favoritesCount(this)
        val recentCount = BlueVpnExperience.history(this)
            .map { it.locationKey }
            .distinct()
            .size
        allTabButton.text = "مکان‌ها"
        favoritesTabButton.text = "مورد علاقه $favoriteCount"
        recentTabButton.text = "اخیر $recentCount"

        listOf(
            LocationTab.ALL to allTabButton,
            LocationTab.FAVORITES to favoritesTabButton,
            LocationTab.RECENT to recentTabButton,
        ).forEach { (tab, button) ->
            val active = tab == selectedTab
            button.backgroundTintList = ColorStateList.valueOf(
                Color.parseColor(if (active) "#E7E7EA" else "#202027")
            )
            button.setTextColor(
                Color.parseColor(if (active) "#101014" else "#A3A5AE")
            )
            button.strokeWidth = if (active) 0 else dp(1)
            button.strokeColor = ColorStateList.valueOf(Color.parseColor("#2C2D35"))
        }
    }

    private fun createSearchField(): View = EditText(this).apply {
        hint = "جست‌وجو در مکان‌ها"
        setHintTextColor(Color.parseColor("#656873"))
        setTextColor(Color.WHITE)
        textSize = 13f
        setSingleLine(true)
        gravity = Gravity.CENTER_VERTICAL or Gravity.END
        background = rounded(
            Color.parseColor("#121217"),
            18,
            Color.parseColor("#282932"),
        )
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
                query = BlueVpnLocationUtil.normalizeForSearch(s?.toString())
                renderLocations()
            }

            override fun afterTextChanged(s: Editable?) = Unit
        })
    }

    private fun createCompactModeSelector(): View {
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
                    BlueVpnConnectionMode.BALANCED -> "متعادل"
                    BlueVpnConnectionMode.GAMING -> "بازی"
                    BlueVpnConnectionMode.STREAMING -> "پخش"
                }
                textSize = 10.5f
                isAllCaps = false
                minWidth = 0
                minimumWidth = 0
                insetTop = 0
                insetBottom = 0
                cornerRadius = dp(16)
                setOnClickListener {
                    BlueVpnExperience.setMode(this@BlueVpnServersActivity, mode)
                    updateModeButtons()
                    renderLocations()
                    if (BlueVpnPreferences.smartBalance(this@BlueVpnServersActivity)) {
                        mainViewModel.testAllRealPing()
                    }
                }
            }
            modeButtons[mode] = button
            row.addView(
                button,
                LinearLayout.LayoutParams(0, -1, 1f).apply {
                    if (index > 0) marginStart = dp(5)
                },
            )
        }
        updateModeButtons()
        return row
    }

    private fun updateModeButtons() {
        val selected = BlueVpnExperience.mode(this)
        modeButtons.forEach { (mode, button) ->
            val active = mode == selected
            button.backgroundTintList = ColorStateList.valueOf(
                Color.parseColor(if (active) "#315FD9" else "#19191F")
            )
            button.strokeWidth = dp(1)
            button.strokeColor = ColorStateList.valueOf(
                Color.parseColor(if (active) "#5D88F0" else "#2B2C34")
            )
            button.setTextColor(
                Color.parseColor(if (active) "#FFFFFF" else "#8C8F9A")
            )
        }
    }

    private fun automaticServerCard(): View {
        val automatic = BlueVpnPreferences.smartBalance(this)
        val card = MaterialCardView(this).apply {
            radius = dp(22).toFloat()
            cardElevation = 0f
            setCardBackgroundColor(
                Color.parseColor(if (automatic) "#151C31" else "#121217")
            )
            strokeWidth = dp(if (automatic) 2 else 1)
            strokeColor = Color.parseColor(if (automatic) "#4C80FF" else "#292A32")
            isClickable = true
            isFocusable = true
            setOnClickListener { selectAutomaticLocation() }
        }

        val row = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            setPadding(dp(14), dp(10), dp(14), dp(10))
        }
        card.addView(row)

        row.addView(
            TextView(this).apply {
                text = "◉"
                textSize = 25f
                gravity = Gravity.CENTER
                setTextColor(Color.WHITE)
                background = rounded(Color.parseColor("#3474E8"), 22)
            },
            LinearLayout.LayoutParams(dp(46), dp(46)),
        )
        val content = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER_VERTICAL
            setPadding(dp(12), 0, dp(12), 0)
        }
        content.addView(TextView(this).apply {
            text = "پیش‌فرض"
            textSize = 16f
            setTextColor(Color.WHITE)
            setTypeface(typeface, Typeface.BOLD)
        })
        content.addView(TextView(this).apply {
            text = if (automatic) {
                "انتخاب خودکار از تمام مکان‌ها • فعال"
            } else {
                "بهترین سرور به‌صورت خودکار انتخاب شود"
            }
            textSize = 10.5f
            setTextColor(Color.parseColor("#7F828D"))
            setPadding(0, dp(4), 0, 0)
        })
        row.addView(content, LinearLayout.LayoutParams(0, -1, 1f))
        row.addView(TextView(this).apply {
            text = if (automatic) "✓" else "‹"
            textSize = if (automatic) 17f else 27f
            gravity = Gravity.CENTER
            setTextColor(
                Color.parseColor(if (automatic) "#6B98FF" else "#6C6F7A")
            )
        }, LinearLayout.LayoutParams(dp(36), dp(42)))
        return card
    }

    private fun selectAutomaticLocation() {
        val changed = !BlueVpnPreferences.smartBalance(this)
        BlueVpnPreferences.setSmartBalance(this, true)
        BlueVpnPreferences.setPreferredLocation(this, "")
        BlueVpnLocationUtil
            .orderedCandidates(this)
            .firstOrNull()
            ?.let { MmkvManager.setSelectServer(it.guid) }
        mainViewModel.testAllRealPing()
        setResult(
            Activity.RESULT_OK,
            Intent()
                .putExtra(EXTRA_LOCATION_CHANGED, changed)
                .putExtra(EXTRA_LOCATION_KEY, "")
                .putExtra(EXTRA_LOCATION_TITLE, "انتخاب خودکار"),
        )
        Toast.makeText(this, "انتخاب خودکار فعال شد", Toast.LENGTH_SHORT).show()
        finish()
    }

    private fun renderLocations() {
        if (!::listContainer.isInitialized) return
        listContainer.removeAllViews()
        updateTabs()

        val selectedGuid = MmkvManager.getSelectServer()
        val selectedLocation = selectedGuid
            ?.let { MmkvManager.decodeServerConfig(it) }
            ?.let { BlueVpnLocationUtil.detect(it.remarks, it.server).key }
        val automatic = BlueVpnPreferences.smartBalance(this)
        val preferred = BlueVpnPreferences.preferredLocation(this)
        val recentKeys = BlueVpnExperience.history(this)
            .map { it.locationKey }
            .distinct()
        val recentIndex = recentKeys.withIndex().associate { it.value to it.index }

        val groups = BlueVpnLocationUtil
            .allCandidates()
            .groupBy { it.location.key }
            .values
            .map { servers ->
                val location = servers.first().location
                val successful = servers.filter {
                    it.delay > 0L &&
                        !BlueVpnPreferences.isSessionInactive(this, it.guid)
                }
                val inactive = servers.count {
                    BlueVpnPreferences.isSessionInactive(this, it.guid)
                }
                LocationGroup(
                    location = location,
                    servers = servers,
                    bestDelay = successful.minOfOrNull { it.delay } ?: 0L,
                    successfulRoutes = successful.size,
                    inactiveRoutes = inactive,
                    healthScore = BlueVpnLocationUtil.locationHealthScore(this, servers),
                    favorite = BlueVpnExperience.isFavorite(this, location.key),
                )
            }
            .filter { group ->
                val searchable = BlueVpnLocationUtil.normalizeForSearch(
                    "${group.location.title} ${group.location.key}"
                )
                val matchesQuery = query.isBlank() || searchable.contains(query)
                val matchesTab = when (selectedTab) {
                    LocationTab.ALL -> true
                    LocationTab.FAVORITES -> group.favorite
                    LocationTab.RECENT -> group.location.key in recentKeys
                }
                matchesQuery && matchesTab
            }
            .sortedWith(
                when (selectedTab) {
                    LocationTab.RECENT -> compareBy<LocationGroup> {
                        recentIndex[it.location.key] ?: Int.MAX_VALUE
                    }
                    else -> compareByDescending<LocationGroup> {
                        it.location.key == preferred || it.location.key == selectedLocation
                    }.thenByDescending {
                        it.favorite
                    }.thenByDescending {
                        it.healthScore
                    }.thenBy {
                        if (it.bestDelay > 0L) it.bestDelay else Long.MAX_VALUE
                    }.thenBy { it.location.title }
                }
            )

        emptyText.text = when (selectedTab) {
            LocationTab.ALL -> "مکانی پیدا نشد."
            LocationTab.FAVORITES -> "هنوز مکانی را به علاقه‌مندی‌ها اضافه نکرده‌اید."
            LocationTab.RECENT -> "هنوز اتصال موفقی در تاریخچه ثبت نشده است."
        }
        emptyText.visibility = if (groups.isEmpty()) View.VISIBLE else View.GONE

        groups.forEach { group ->
            val active = !automatic && (
                group.location.key == preferred ||
                    (preferred.isBlank() && group.location.key == selectedLocation)
                )
            listContainer.addView(
                createLocationRow(group, active, automatic, selectedLocation),
                LinearLayout.LayoutParams(-1, dp(76)).apply {
                    bottomMargin = dp(7)
                },
            )
        }
    }

    private fun createLocationRow(
        group: LocationGroup,
        active: Boolean,
        automatic: Boolean,
        selectedLocation: String?,
    ): View {
        val card = MaterialCardView(this).apply {
            radius = dp(20).toFloat()
            cardElevation = 0f
            setCardBackgroundColor(
                Color.parseColor(
                    when {
                        active -> "#151C31"
                        group.favorite -> "#181621"
                        else -> "#121217"
                    }
                )
            )
            strokeWidth = dp(if (active) 2 else 1)
            strokeColor = Color.parseColor(
                when {
                    active -> "#4C80FF"
                    group.favorite -> "#44395F"
                    else -> "#282932"
                }
            )
            isClickable = true
            isFocusable = true
            setOnClickListener {
                selectGroup(group, automatic, selectedLocation)
            }
        }

        val row = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            setPadding(dp(13), dp(9), dp(13), dp(9))
        }
        card.addView(row)

        row.addView(
            TextView(this).apply {
                text = group.location.flag
                textSize = 27f
                gravity = Gravity.CENTER
                background = rounded(Color.parseColor("#24242A"), 24)
            },
            LinearLayout.LayoutParams(dp(50), dp(50)),
        )

        val content = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER_VERTICAL
            setPadding(dp(12), 0, dp(12), 0)
        }
        content.addView(TextView(this).apply {
            text = group.location.title
            textSize = 16f
            setTextColor(Color.WHITE)
            setTypeface(typeface, Typeface.BOLD)
            maxLines = 1
            ellipsize = android.text.TextUtils.TruncateAt.END
        })
        content.addView(TextView(this).apply {
            val ping = if (group.bestDelay > 0L) {
                "${group.bestDelay} ms"
            } else {
                "تست هنگام اتصال"
            }
            val healthy = if (group.successfulRoutes > 0) {
                "${group.successfulRoutes} فعال"
            } else {
                "در انتظار بررسی"
            }
            text = "${group.servers.size} مکان • $healthy • $ping"
            textSize = 10.5f
            setTextColor(Color.parseColor("#777A85"))
            setPadding(0, dp(5), 0, 0)
            maxLines = 1
            ellipsize = android.text.TextUtils.TruncateAt.END
        })
        row.addView(content, LinearLayout.LayoutParams(0, -1, 1f))

        val actions = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER
        }
        actions.addView(MaterialButton(this).apply {
            text = if (group.favorite) "★" else "☆"
            textSize = 18f
            minWidth = 0
            minimumWidth = 0
            minHeight = 0
            minimumHeight = 0
            insetTop = 0
            insetBottom = 0
            setPadding(0, 0, 0, 0)
            isAllCaps = false
            cornerRadius = dp(15)
            backgroundTintList = ColorStateList.valueOf(Color.TRANSPARENT)
            setTextColor(
                Color.parseColor(if (group.favorite) "#B39BFF" else "#656873")
            )
            setOnClickListener {
                BlueVpnExperience.toggleFavorite(
                    this@BlueVpnServersActivity,
                    group.location.key,
                )
                renderLocations()
            }
        }, LinearLayout.LayoutParams(dp(38), dp(38)))
        actions.addView(TextView(this).apply {
            text = if (active) "✓" else "⌄"
            textSize = if (active) 16f else 22f
            gravity = Gravity.CENTER
            setTextColor(
                Color.parseColor(if (active) "#6B98FF" else "#666974")
            )
        }, LinearLayout.LayoutParams(dp(30), dp(38)))
        row.addView(actions)
        return card
    }

    private fun selectGroup(
        group: LocationGroup,
        automatic: Boolean,
        selectedLocation: String?,
    ) {
        val currentPreferred = BlueVpnPreferences
            .preferredLocation(this)
            .ifBlank { selectedLocation.orEmpty() }
        val changed = automatic || currentPreferred != group.location.key

        BlueVpnPreferences.setSmartBalance(this, false)
        BlueVpnPreferences.setPreferredLocation(this, group.location.key)
        BlueVpnLocationUtil
            .orderedCandidates(this, group.location.key)
            .firstOrNull()
            ?.let { MmkvManager.setSelectServer(it.guid) }

        setResult(
            Activity.RESULT_OK,
            Intent()
                .putExtra(EXTRA_LOCATION_CHANGED, changed)
                .putExtra(EXTRA_LOCATION_KEY, group.location.key)
                .putExtra(
                    EXTRA_LOCATION_TITLE,
                    "${group.location.flag} ${group.location.title}",
                ),
        )
        Toast.makeText(
            this,
            if (changed) {
                "${group.location.title} انتخاب شد"
            } else {
                "${group.location.title} از قبل فعال است"
            },
            Toast.LENGTH_SHORT,
        ).show()
        finish()
    }

    private fun rounded(
        fill: Int,
        radiusDp: Int,
        stroke: Int? = null,
    ): GradientDrawable = GradientDrawable().apply {
        setColor(fill)
        cornerRadius = dp(radiusDp).toFloat()
        if (stroke != null) setStroke(dp(1), stroke)
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
