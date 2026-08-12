package com.v2ray.ang.ui

import android.app.Activity
import android.content.Intent
import android.content.res.ColorStateList
import android.graphics.Typeface
import android.graphics.drawable.GradientDrawable
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.os.SystemClock
import android.text.Editable
import android.text.TextWatcher
import android.view.Gravity
import android.view.View
import android.view.ViewGroup
import android.widget.EditText
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import android.widget.Toast
import androidx.activity.viewModels
import androidx.lifecycle.lifecycleScope
import com.google.android.material.button.MaterialButton
import com.google.android.material.card.MaterialCardView
import com.v2ray.ang.bluevpn.BlueVpnAccountManager
import com.v2ray.ang.bluevpn.BlueVpnExperience
import com.v2ray.ang.bluevpn.BlueVpnEntitlement
import com.v2ray.ang.bluevpn.BlueVpnPlanTier
import com.v2ray.ang.bluevpn.BlueVpnSelectionMode
import com.v2ray.ang.bluevpn.BlueVpnSmartSelector
import com.v2ray.ang.bluevpn.BlueVpnLocation
import com.v2ray.ang.bluevpn.BlueVpnLocationUtil
import com.v2ray.ang.bluevpn.BlueVpnPalette
import com.v2ray.ang.bluevpn.BlueVpnPerformance
import com.v2ray.ang.bluevpn.BlueVpnPreferences
import com.v2ray.ang.bluevpn.BlueVpnRouteIntelligence
import com.v2ray.ang.bluevpn.BlueVpnRuntimeGate
import com.v2ray.ang.bluevpn.BlueVpnTheme
import com.v2ray.ang.bluevpn.BlueVpnUiGuard
import com.v2ray.ang.handler.MmkvManager
import com.v2ray.ang.viewmodel.MainViewModel
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

class BlueVpnServersActivity : HelperBaseActivity() {

    companion object {
        const val EXTRA_LOCATION_CHANGED = "bluevpn.extra.LOCATION_CHANGED"
        const val EXTRA_LOCATION_KEY = "bluevpn.extra.LOCATION_KEY"
        const val EXTRA_LOCATION_TITLE = "bluevpn.extra.LOCATION_TITLE"
    }

    private enum class LocationTab { ALL, FAVORITES, RECENT }

    private data class LocationGroup(
        val location: BlueVpnLocation,
        val servers: List<BlueVpnLocationUtil.Candidate>,
        val usableRoutes: Int,
        val healthScore: Int,
        val favorite: Boolean,
    )

    private val mainViewModel: MainViewModel by viewModels()
    private lateinit var palette: BlueVpnPalette
    private var themeDarkAtCreate = true
    private lateinit var listContainer: LinearLayout
    private lateinit var emptyText: TextView
    private lateinit var refreshButton: MaterialButton
    private lateinit var entitlementSubtitle: TextView
    private lateinit var automaticSubtitle: TextView
    private lateinit var allTabButton: MaterialButton
    private lateinit var favoritesTabButton: MaterialButton
    private lateinit var recentTabButton: MaterialButton
    private var selectedTab = LocationTab.ALL
    private var query = ""
    private val expandedLocations = mutableSetOf<String>()
    private var firstResume = true
    private val locationSyncHandler = Handler(Looper.getMainLooper())
    private val searchHandler = Handler(Looper.getMainLooper())
    private val renderHandler = Handler(Looper.getMainLooper())
    private var renderGeneration = 0
    private var candidateLoadInProgress = false
    private var candidateReloadPending = false
    private var candidateLoadError = ""
    private var entitlementRepairAttempted = false
    private var accountSyncInProgress = false
    private var accountSyncPending = false
    private var lastAccountSyncAt = 0L
    private var renderedPremiumMode: Boolean? = null
    private val searchRunnable = Runnable { renderLocations() }
    private val renderRunnable = Runnable {
        renderLocationsNow(renderGeneration)
    }
    private val refreshTimeoutRunnable = Runnable { stopRefreshing() }
    private val candidateReloadRunnable = Runnable {
        val force = candidateReloadPending
        candidateReloadPending = false
        loadCandidates(force = force)
    }
    private val locationSyncRunnable = object : Runnable {
        override fun run() {
            syncDetectedLocations(force = false)
            locationSyncHandler.postDelayed(
                this,
                BlueVpnPerformance.locationSyncIntervalMs(this@BlueVpnServersActivity),
            )
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        window.setWindowAnimations(0)
        palette = BlueVpnTheme.palette(this)
        themeDarkAtCreate = palette.dark
        window.setBackgroundDrawable(android.graphics.drawable.ColorDrawable(palette.background))
        BlueVpnTheme.applySystemBars(this)
        setContentView(createScreen())
        updateTabs()
        updateEntitlementUi()
        refreshEntitlementState(force = false)

        mainViewModel.startListenBroadcast()
        mainViewModel.updateListAction.observe(this) {
            BlueVpnLocationUtil.invalidateResolvedCache()
            stopRefreshing()
            scheduleCandidateReload(force = true)
        }
        mainViewModel.updateTestResultAction.observe(this) {
            BlueVpnLocationUtil.invalidateResolvedCache()
            stopRefreshing()
            scheduleCandidateReload(force = true)
        }
        renderLocations()
        loadCandidates(force = false)
        syncDetectedLocations(force = false)
    }

    override fun onResume() {
        super.onResume()
        BlueVpnTheme.applySystemBars(this)
        if (BlueVpnTheme.isDark(this) != themeDarkAtCreate) {
            recreate()
            return
        }
        if (firstResume) {
            firstResume = false
        } else {
            renderLocations()
            if (BlueVpnLocationUtil.cachedCandidates(this).isEmpty()) {
                loadCandidates(force = false)
            }
        }
        updateEntitlementUi()
        refreshEntitlementState(force = false)
        locationSyncHandler.removeCallbacks(locationSyncRunnable)
        locationSyncHandler.postDelayed(
            locationSyncRunnable,
            if (BlueVpnPerformance.isLowEnd(this)) 8_000L else 3_000L,
        )
    }

    override fun onPause() {
        renderGeneration++
        locationSyncHandler.removeCallbacks(locationSyncRunnable)
        searchHandler.removeCallbacks(searchRunnable)
        renderHandler.removeCallbacksAndMessages(null)
        super.onPause()
    }

    override fun onDestroy() {
        renderGeneration++
        locationSyncHandler.removeCallbacks(locationSyncRunnable)
        searchHandler.removeCallbacks(searchRunnable)
        renderHandler.removeCallbacksAndMessages(null)
        super.onDestroy()
    }

    private fun scheduleCandidateReload(force: Boolean) {
        candidateReloadPending = candidateReloadPending || force
        renderHandler.removeCallbacks(candidateReloadRunnable)
        renderHandler.postDelayed(candidateReloadRunnable, 350L)
    }

    private fun loadCandidates(
        force: Boolean,
        selectAutomaticAfterLoad: Boolean = false,
    ) {
        if (isFinishing || isDestroyed) return
        if (candidateLoadInProgress) {
            candidateReloadPending = candidateReloadPending || force
            return
        }

        candidateLoadInProgress = true
        candidateLoadError = ""
        val requestIdentity = BlueVpnAccountManager.entitlementIdentityFingerprint(this)
        val requestedForce = force || candidateReloadPending
        candidateReloadPending = false
        renderLocations()

        lifecycleScope.launch(Dispatchers.Default) {
            val result = runCatching {
                var loaded = BlueVpnLocationUtil.allCandidates(
                    this@BlueVpnServersActivity,
                    forceRefresh = requestedForce,
                )

                // Subscription import in v2rayNG is asynchronous. Repair once,
                // but never allow an exception or repeated MMKV broadcasts to
                // leave the screen permanently in a loading state.
                if (
                    loaded.isEmpty() &&
                    BlueVpnAccountManager.active(this@BlueVpnServersActivity) &&
                    !BlueVpnRuntimeGate.connectionActive(this@BlueVpnServersActivity) &&
                    !accountSyncInProgress &&
                    !entitlementRepairAttempted
                ) {
                    entitlementRepairAttempted = true
                    BlueVpnAccountManager.awaitEntitlementServers(
                        this@BlueVpnServersActivity,
                    )
                    loaded = BlueVpnLocationUtil.allCandidates(
                        this@BlueVpnServersActivity,
                        forceRefresh = true,
                    )
                }
                loaded
            }

            withContext(Dispatchers.Main) {
                candidateLoadInProgress = false
                stopRefreshing()
                if (isFinishing || isDestroyed) return@withContext

                val currentIdentity = BlueVpnAccountManager
                    .entitlementIdentityFingerprint(this@BlueVpnServersActivity)
                if (requestIdentity != currentIdentity) {
                    scheduleCandidateReload(force = true)
                    return@withContext
                }

                val loaded = result.getOrDefault(emptyList())
                candidateLoadError = result.exceptionOrNull()?.let {
                    "دریافت سرورها ناموفق بود؛ تازه‌سازی را بزنید"
                }.orEmpty()
                if (loaded.isNotEmpty()) {
                    entitlementRepairAttempted = false
                    candidateLoadError = ""
                }
                updateEntitlementUi()
                if (
                    selectAutomaticAfterLoad &&
                    BlueVpnPreferences.smartBalance(this@BlueVpnServersActivity)
                ) {
                    if (!BlueVpnRuntimeGate.connectionActive(this@BlueVpnServersActivity)) {
                        loaded.firstOrNull()?.let { MmkvManager.setSelectServer(it.guid) }
                    }
                }
                renderLocations()

                // Coalesce the importer's burst of broadcasts into at most one
                // trailing retry, and retry only while the pool is still empty.
                val retry = candidateReloadPending && loaded.isEmpty()
                candidateReloadPending = retry
                if (retry) {
                    renderHandler.removeCallbacks(candidateReloadRunnable)
                    renderHandler.postDelayed(candidateReloadRunnable, 500L)
                }
            }
        }
    }

    private fun syncDetectedLocations(force: Boolean) {
        BlueVpnLocationUtil.syncCloudLocations(
            this,
            force = force,
        ) {
            if (!isFinishing && !isDestroyed) {
                renderLocations()
            }
        }
    }

    private fun createScreen(): View {
        palette = BlueVpnTheme.palette(this)
        val frame = FrameLayout(this).apply {
            layoutDirection = View.LAYOUT_DIRECTION_RTL
            setBackgroundColor(palette.background)
        }
        val root = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(18), dp(10), dp(18), dp(14))
            layoutDirection = View.LAYOUT_DIRECTION_RTL
        }
        frame.addView(root, FrameLayout.LayoutParams(-1, -1))

        root.addView(createHeader(), LinearLayout.LayoutParams(-1, dp(60)))
        root.addView(createTabs(), LinearLayout.LayoutParams(-1, dp(48)).apply { topMargin = dp(8) })
        root.addView(createSearchField(), LinearLayout.LayoutParams(-1, dp(52)).apply { topMargin = dp(10) })
        root.addView(automaticServerCard(), LinearLayout.LayoutParams(-1, dp(82)).apply {
            topMargin = dp(10)
            bottomMargin = dp(9)
        })

        emptyText = textView("مکانی برای نمایش وجود ندارد", 13f, palette.textMuted, Gravity.CENTER).apply {
            visibility = View.GONE
            setPadding(0, dp(30), 0, dp(30))
        }
        root.addView(emptyText)

        listContainer = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(0, 0, 0, dp(16))
        }
        val scroll = ScrollView(this).apply {
            isFillViewport = true
            overScrollMode = View.OVER_SCROLL_NEVER
            addView(listContainer)
        }
        root.addView(scroll, LinearLayout.LayoutParams(-1, 0, 1f))
        return frame
    }

    private fun createHeader(): View {
        val row = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
        }

        refreshButton = smallButton("تازه‌سازی").apply {
            contentDescription = "بررسی دوباره سرورها"
            BlueVpnUiGuard.bind(this, intervalMs = 1_200L) {
                isEnabled = false
                text = "در حال بررسی"
                entitlementRepairAttempted = false
                // One owner for the refresh pipeline. Running account sync, MMKV
                // import, candidate decode and ping simultaneously caused the list
                // to appear and disappear as each job published a different state.
                refreshEntitlementState(force = true)
                syncDetectedLocations(force = true)
                renderHandler.removeCallbacks(refreshTimeoutRunnable)
                renderHandler.postDelayed(refreshTimeoutRunnable, 12_000L)
            }
        }
        row.addView(refreshButton, LinearLayout.LayoutParams(dp(104), dp(44)))

        val titleBox = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER_VERTICAL or Gravity.END
            setPadding(dp(12), 0, dp(12), 0)
        }
        titleBox.addView(textView("مکان‌ها", 25f, palette.textPrimary, Gravity.END).apply {
            setTypeface(typeface, Typeface.BOLD)
        })
        entitlementSubtitle = textView("", 10.5f, palette.textMuted, Gravity.END).apply {
            setPadding(0, dp(3), 0, 0)
        }
        titleBox.addView(entitlementSubtitle)
        row.addView(titleBox, LinearLayout.LayoutParams(0, -1, 1f))

        row.addView(smallButton("بستن").apply { BlueVpnUiGuard.bind(this) { finish() } }, LinearLayout.LayoutParams(dp(76), dp(44)))
        return row
    }

    private fun createTabs(): View {
        val card = card(radius = 24, fill = palette.surface, stroke = palette.stroke)
        val row = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER
            setPadding(dp(4), dp(4), dp(4), dp(4))
        }
        card.addView(row)
        allTabButton = tabButton("همه") { selectedTab = LocationTab.ALL; updateTabs(); renderLocations() }
        favoritesTabButton = tabButton("علاقه‌مندی") { selectedTab = LocationTab.FAVORITES; updateTabs(); renderLocations() }
        recentTabButton = tabButton("اخیر") { selectedTab = LocationTab.RECENT; updateTabs(); renderLocations() }
        row.addView(allTabButton, LinearLayout.LayoutParams(0, -1, 1f))
        row.addView(favoritesTabButton, LinearLayout.LayoutParams(0, -1, 1f).apply { marginStart = dp(4); marginEnd = dp(4) })
        row.addView(recentTabButton, LinearLayout.LayoutParams(0, -1, 1f))
        return card
    }

    private fun createSearchField(): View = EditText(this).apply {
        hint = "جست‌وجوی کشور"
        textSize = 13f
        setTextColor(palette.textPrimary)
        setHintTextColor(palette.textMuted)
        isSingleLine = true
        gravity = Gravity.CENTER_VERTICAL or Gravity.END
        setPadding(dp(16), 0, dp(16), 0)
        background = rounded(palette.surface, 18, palette.stroke)
        addTextChangedListener(object : TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) = Unit
            override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {
                query = BlueVpnLocationUtil.normalizeForSearch(s?.toString())
                searchHandler.removeCallbacks(searchRunnable)
                searchHandler.postDelayed(
                    searchRunnable,
                    if (BlueVpnPerformance.isLowEnd(this@BlueVpnServersActivity)) 320L else 160L,
                )
            }
            override fun afterTextChanged(s: Editable?) = Unit
        })
    }

    private fun automaticServerCard(): View {
        val card = card(radius = 22, fill = palette.surface, stroke = palette.accent).apply {
            isClickable = true
            isFocusable = true
            strokeWidth = dp(if (BlueVpnPreferences.smartBalance(this@BlueVpnServersActivity)) 2 else 1)
            BlueVpnUiGuard.bind(this) { selectAutomatic() }
        }
        val row = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            setPadding(dp(16), dp(10), dp(16), dp(10))
        }
        card.addView(row)
        val dot = View(this).apply { background = circle(palette.accent) }
        row.addView(dot, LinearLayout.LayoutParams(dp(13), dp(13)).apply { marginEnd = dp(12) })
        val box = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; gravity = Gravity.CENTER_VERTICAL }
        box.addView(textView("انتخاب خودکار", 16f, palette.textPrimary, Gravity.END).apply { setTypeface(typeface, Typeface.BOLD) })
        automaticSubtitle = textView("", 10.5f, palette.textMuted, Gravity.END).apply {
            setPadding(0, dp(4), 0, 0)
        }
        box.addView(automaticSubtitle)
        row.addView(box, LinearLayout.LayoutParams(0, -1, 1f))
        row.addView(textView(if (BlueVpnPreferences.smartBalance(this)) "فعال" else "انتخاب", 11f, palette.accent, Gravity.CENTER).apply {
            setTypeface(typeface, Typeface.BOLD)
        }, LinearLayout.LayoutParams(dp(62), dp(38)))
        return card
    }

    // Previous builds used BlueVpnAccountManager.snapshot(this).subscriptionActive
    // directly; the unified entitlement snapshot now owns this decision.
    private fun premiumMode(): Boolean =
        BlueVpnEntitlement.resolve(this).tier == BlueVpnPlanTier.PREMIUM

    /**
     * The locations screen used to contain a hard-coded «free automatic» label.
     * Account activation could therefore be visible on the account screen while
     * this screen continued to advertise free mode until the Activity was rebuilt.
     * Keep all entitlement-dependent copy bound to the same account snapshot.
     */
    // Legacy Premium copy retained for regression coverage:
    // «انتخاب خودکار هوشمند • انتخاب دستی همه مکان‌ها»
    // «انتخاب خودکار رایگان • انتخاب دستی ویژه مشترکین»
    private fun updateEntitlementUi() {
        val entitlement = BlueVpnEntitlement.reconcile(this)
        if (::entitlementSubtitle.isInitialized) {
            entitlementSubtitle.text = when (entitlement.tier) {
                BlueVpnPlanTier.PREMIUM -> "Premium • انتخاب هوشمند و انتخاب دستی همه مکان‌ها"
                BlueVpnPlanTier.FREE -> "رایگان • انتخاب هوشمند فقط از Pool رایگان"
                BlueVpnPlanTier.UNAVAILABLE -> "دسترسی اتصال هنوز آماده نیست"
            }
        }
        if (::automaticSubtitle.isInitialized) {
            automaticSubtitle.text = when (entitlement.tier) {
                BlueVpnPlanTier.PREMIUM -> "بهترین سرور Premium با پینگ، سابقه و سلامت مسیر انتخاب می‌شود"
                BlueVpnPlanTier.FREE -> "بهترین سرور رایگان • هر اتصال ${entitlement.sessionMinutes} دقیقه"
                BlueVpnPlanTier.UNAVAILABLE -> "برای دریافت سرورها تازه‌سازی کنید یا اشتراک تهیه کنید"
            }
        }

        val premium = entitlement.isPremium
        val previous = renderedPremiumMode
        renderedPremiumMode = premium
        if (previous != null && previous != premium) {
            entitlementRepairAttempted = false
            BlueVpnLocationUtil.invalidateCache()
        }
    }

    /**
     * Refresh entitlement on entry and after returning from payment/admin
     * activation. The UI is updated immediately from local state, then again
     * from the authoritative server response without requiring logout/login.
     */
    private fun refreshEntitlementState(force: Boolean) {
        updateEntitlementUi()
        if (!BlueVpnAccountManager.hasSession(this)) return

        val now = SystemClock.elapsedRealtime()
        if (!force && now - lastAccountSyncAt < 15_000L) return
        if (accountSyncInProgress) {
            accountSyncPending = accountSyncPending || force
            return
        }

        accountSyncInProgress = true
        lastAccountSyncAt = now
        val before = BlueVpnAccountManager.snapshot(this)
        lifecycleScope.launch(Dispatchers.IO) {
            val result = BlueVpnAccountManager.sync(
                this@BlueVpnServersActivity,
                force = force,
            )
            withContext(Dispatchers.Main) {
                accountSyncInProgress = false
                if (isFinishing || isDestroyed) return@withContext

                val after = result.getOrElse {
                    BlueVpnAccountManager.snapshot(this@BlueVpnServersActivity)
                }
                val entitlementChanged =
                    before.subscriptionActive != after.subscriptionActive ||
                        before.subscriptionUrl != after.subscriptionUrl ||
                        before.status != after.status ||
                        before.expire != after.expire

                updateEntitlementUi()
                if (result.isSuccess) {
                    BlueVpnLocationUtil.invalidateCache()
                    if (entitlementChanged) {
                        entitlementRepairAttempted = false
                        mainViewModel.reloadServerList()
                    }
                    loadCandidates(force = true)
                }

                if (accountSyncPending) {
                    val pendingForce = accountSyncPending
                    accountSyncPending = false
                    refreshEntitlementState(force = pendingForce)
                }
            }
        }
    }

    private fun updateTabs() {
        applyTab(allTabButton, selectedTab == LocationTab.ALL)
        applyTab(favoritesTabButton, selectedTab == LocationTab.FAVORITES)
        applyTab(recentTabButton, selectedTab == LocationTab.RECENT)
    }

    private fun applyTab(button: MaterialButton, active: Boolean) {
        button.backgroundTintList = ColorStateList.valueOf(if (active) palette.accent else android.graphics.Color.TRANSPARENT)
        button.setTextColor(if (active) android.graphics.Color.WHITE else palette.textSecondary)
    }

    private fun renderLocations() {
        if (!::listContainer.isInitialized || isFinishing || isDestroyed) return
        renderGeneration++
        renderHandler.removeCallbacks(renderRunnable)
        renderHandler.postDelayed(
            renderRunnable,
            BlueVpnPerformance.uiRenderDelayMs(this),
        )
    }

    private fun renderLocationsNow(generation: Int) {
        if (
            generation != renderGeneration ||
            !::listContainer.isInitialized ||
            isFinishing ||
            isDestroyed
        ) return
        val automatic = BlueVpnPreferences.smartBalance(this)
        val preferred = BlueVpnPreferences.preferredLocation(this)
        val selected = MmkvManager.getSelectServer()
        val candidates = BlueVpnLocationUtil.cachedCandidates(this)
        if (candidates.isEmpty()) {
            // Do not destroy already rendered rows while an entitlement import is
            // temporarily between clear and repopulate. The cache layer will
            // replace them atomically when the new non-empty snapshot is ready.
            if (candidateLoadInProgress && listContainer.childCount > 0) {
                emptyText.visibility = View.GONE
                return
            }
            listContainer.removeAllViews()
            val entitlement = BlueVpnEntitlement.resolve(this)
            emptyText.text = when {
                candidateLoadError.isNotBlank() -> candidateLoadError
                candidateLoadInProgress && entitlement.isPremium ->
                    "در حال همگام‌سازی Pool اختصاصی Premium…"
                candidateLoadInProgress && entitlement.isFree ->
                    "در حال دریافت Pool رایگان…"
                entitlement.isPremium ->
                    "سرورهای اشتراک هنوز دریافت نشده‌اند؛ تازه‌سازی را بزنید"
                entitlement.isFree -> "سرور رایگان فعالی برای نمایش پیدا نشد"
                else -> "دسترسی فعالی برای دریافت سرور وجود ندارد"
            }
            emptyText.visibility = View.VISIBLE
            return
        }
        listContainer.removeAllViews()
        val selectedLocation = candidates.firstOrNull { it.guid == selected }?.location?.key
        val recentKeys = BlueVpnExperience.history(this).map { it.locationKey }.distinct()
        val recentIndex = recentKeys.withIndex().associate { it.value to it.index }

        val groups = candidates
            .groupBy { it.location.key }
            .mapNotNull { (_, servers) ->
                val location = servers.firstOrNull()?.location ?: return@mapNotNull null
                val usable = servers.count { !BlueVpnPreferences.isSessionInactive(this, it.guid) }
                LocationGroup(
                    location = location,
                    servers = servers,
                    usableRoutes = usable,
                    healthScore = BlueVpnLocationUtil.locationHealthScore(this, servers),
                    favorite = BlueVpnExperience.isFavorite(this, location.key),
                )
            }
            .filter { group ->
                val searchable = BlueVpnLocationUtil.normalizeForSearch("${group.location.title} ${group.location.key}")
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
                    LocationTab.RECENT -> compareBy<LocationGroup> { recentIndex[it.location.key] ?: Int.MAX_VALUE }
                    else -> compareByDescending<LocationGroup> {
                        it.location.key == preferred || it.location.key == selectedLocation
                    }.thenByDescending { it.favorite }
                        .thenByDescending { it.healthScore }
                        .thenBy { it.location.title }
                }
            )

        emptyText.text = when (selectedTab) {
            LocationTab.ALL -> "مکانی پیدا نشد"
            LocationTab.FAVORITES -> "هنوز مکانی را به علاقه‌مندی اضافه نکرده‌اید"
            LocationTab.RECENT -> "هنوز اتصال موفقی ثبت نشده است"
        }
        emptyText.visibility = if (groups.isEmpty()) View.VISIBLE else View.GONE

        var groupIndex = 0
        val chunkSize = BlueVpnPerformance.uiChunkSize(this)
        val appendChunk = object : Runnable {
            override fun run() {
                if (
                    generation != renderGeneration ||
                    isFinishing ||
                    isDestroyed ||
                    !::listContainer.isInitialized
                ) return
                val end = (groupIndex + chunkSize).coerceAtMost(groups.size)
                while (groupIndex < end) {
                    val group = groups[groupIndex++]
                    val active = !automatic && (
                        group.location.key == preferred ||
                            (preferred.isBlank() && group.location.key == selectedLocation)
                        )
                    listContainer.addView(
                        createLocationSection(group, active),
                        LinearLayout.LayoutParams(-1, -2).apply {
                            bottomMargin = dp(8)
                        },
                    )
                }
                if (groupIndex < groups.size) {
                    renderHandler.post(this)
                }
            }
        }
        renderHandler.post(appendChunk)
    }

    private fun createLocationSection(
        group: LocationGroup,
        active: Boolean,
    ): View {
        val premium = BlueVpnEntitlement.resolve(this).manualSelectionAllowed
        val expanded = group.location.key in expandedLocations
        val outer = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
        }
        val fill = when {
            active -> if (palette.dark) 0xFF151D31.toInt() else 0xFFEAF0FF.toInt()
            group.favorite -> if (palette.dark) 0xFF181621.toInt() else 0xFFF4F0FF.toInt()
            else -> palette.surface
        }
        val stroke = when {
            active -> palette.accent
            group.favorite -> if (palette.dark) 0xFF44395F.toInt() else 0xFFD9CDF7.toInt()
            else -> palette.stroke
        }
        val header = card(radius = 21, fill = fill, stroke = stroke).apply {
            strokeWidth = dp(if (active) 2 else 1)
            isClickable = true
            isFocusable = true
            BlueVpnUiGuard.bind(this) {
                if (expanded) {
                    expandedLocations.remove(group.location.key)
                } else {
                    expandedLocations.clear()
                    expandedLocations.add(group.location.key)
                }
                renderLocations()
            }
        }
        val row = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            setPadding(dp(13), dp(9), dp(13), dp(9))
        }
        header.addView(row)
        row.addView(textView(group.location.flag, 27f, palette.textPrimary, Gravity.CENTER).apply {
            background = rounded(palette.surfaceStrong, 24)
        }, LinearLayout.LayoutParams(dp(50), dp(50)))

        val content = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER_VERTICAL
            setPadding(dp(12), 0, dp(12), 0)
        }
        content.addView(textView(group.location.title, 16f, palette.textPrimary, Gravity.END).apply {
            setTypeface(typeface, Typeface.BOLD)
            maxLines = 1
            ellipsize = android.text.TextUtils.TruncateAt.END
        })
        val availability = when {
            group.location.key == "unknown" -> "شناسایی کشور در پس‌زمینه"
            group.usableRoutes <= 0 -> "در انتظار بررسی"
            group.usableRoutes == group.servers.size -> "همه مسیرها آماده"
            else -> "${group.usableRoutes} مسیر آماده"
        }
        content.addView(textView("${group.servers.size} ورودی • $availability", 10.5f, palette.textMuted, Gravity.END).apply {
            setPadding(0, dp(5), 0, 0)
            maxLines = 1
            ellipsize = android.text.TextUtils.TruncateAt.END
        })
        row.addView(content, LinearLayout.LayoutParams(0, -1, 1f))

        val favoriteButton = MaterialButton(this).apply {
            text = if (group.favorite) "★" else "☆"
            textSize = 18f
            minWidth = 0; minimumWidth = 0; minHeight = 0; minimumHeight = 0
            insetTop = 0; insetBottom = 0
            setPadding(0, 0, 0, 0)
            isAllCaps = false
            cornerRadius = dp(15)
            backgroundTintList = ColorStateList.valueOf(android.graphics.Color.TRANSPARENT)
            setTextColor(if (group.favorite) palette.accent else palette.textMuted)
            contentDescription = if (group.favorite) "حذف از علاقه‌مندی" else "افزودن به علاقه‌مندی"
            BlueVpnUiGuard.bind(this) {
                BlueVpnExperience.toggleFavorite(
                    this@BlueVpnServersActivity,
                    group.location.key,
                )
                renderLocations()
            }
        }
        row.addView(favoriteButton, LinearLayout.LayoutParams(dp(40), dp(40)))
        row.addView(textView(
            if (!premium) "🔒" else if (expanded) "⌃" else "⌄",
            if (!premium) 16f else 18f,
            if (!premium) palette.textMuted else palette.accent,
            Gravity.CENTER,
        ), LinearLayout.LayoutParams(dp(48), dp(40)))
        outer.addView(header, LinearLayout.LayoutParams(-1, dp(78)))

        if (expanded) {
            group.servers
                .sortedWith(compareByDescending<BlueVpnLocationUtil.Candidate> {
                    BlueVpnLocationUtil.healthScore(this, it)
                }.thenBy { if (it.delay > 0L) it.delay else Long.MAX_VALUE })
                .forEachIndexed { index, candidate ->
                    outer.addView(
                        createServerEntry(group, candidate, index, premium),
                        LinearLayout.LayoutParams(-1, dp(62)).apply {
                            topMargin = dp(5)
                            marginStart = dp(12)
                            marginEnd = dp(12)
                        },
                    )
                }
        }
        return outer
    }

    private fun createServerEntry(
        group: LocationGroup,
        candidate: BlueVpnLocationUtil.Candidate,
        index: Int,
        premium: Boolean,
    ): View {
        val selected = MmkvManager.getSelectServer() == candidate.guid
        val entry = card(
            radius = 17,
            fill = if (selected) palette.surfaceStrong else palette.surface,
            stroke = if (selected) palette.accent else palette.stroke,
        ).apply {
            strokeWidth = dp(if (selected) 2 else 1)
            isClickable = true
            isFocusable = true
            BlueVpnUiGuard.bind(this) {
                if (!premium) {
                    openSubscriptionForPremium()
                } else {
                    selectServer(group, candidate)
                }
            }
        }
        val row = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            setPadding(dp(14), dp(7), dp(14), dp(7))
        }
        entry.addView(row)
        val stateColor = when {
            BlueVpnPreferences.isSessionInactive(this, candidate.guid) -> 0xFFFF5364.toInt()
            candidate.delay > 0L -> 0xFF24C7A5.toInt()
            candidate.delay < 0L -> 0xFFFF5364.toInt()
            else -> palette.textMuted
        }
        row.addView(View(this).apply { background = circle(stateColor) }, LinearLayout.LayoutParams(dp(9), dp(9)).apply { marginEnd = dp(11) })
        val labelBox = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; gravity = Gravity.CENTER_VERTICAL }
        labelBox.addView(textView(routeLabel(candidate, index), 14f, palette.textPrimary, Gravity.END).apply {
            setTypeface(typeface, Typeface.BOLD)
            maxLines = 1
            ellipsize = android.text.TextUtils.TruncateAt.END
        })
        val routeEvidence = BlueVpnRouteIntelligence.evidence(this, candidate.guid)
        val exitEvidence = BlueVpnRouteIntelligence.exitSummary(this, candidate.guid)
        val routeDetails = when {
            !premium -> "ویژه مشترکین"
            else -> buildList {
                if (selected) add("مسیر انتخاب‌شده")
                if (candidate.delay > 0L) add("${candidate.delay}ms")
                routeEvidence?.let { add(it) }
                exitEvidence?.let { add("خروجی $it") }
                if (isEmpty()) add("مسیر ${index + 1}")
            }.take(3).joinToString(" • ")
        }
        labelBox.addView(textView(
            routeDetails,
            9.5f, palette.textMuted, Gravity.END,
        ).apply {
            setPadding(0, dp(3), 0, 0)
            maxLines = 1
            ellipsize = android.text.TextUtils.TruncateAt.END
        })
        row.addView(labelBox, LinearLayout.LayoutParams(0, -1, 1f))
        row.addView(textView(if (premium) "انتخاب" else "🔒", 11f, if (premium) palette.accent else palette.textMuted, Gravity.CENTER), LinearLayout.LayoutParams(dp(58), dp(38)))
        return entry
    }

    private fun routeLabel(candidate: BlueVpnLocationUtil.Candidate, index: Int): String {
        val raw = candidate.profile.remarks.orEmpty()
            .replace(Regex("(?i)\\b(vless|vmess|trojan|reality|grpc|websocket|ws|tcp|udp|tls|xray|v2rayng|vpn|proxy)\\b"), " ")
            .replace(Regex("[_|/\\-]+"), " ")
            .replace(Regex("\\s+"), " ")
            .trim()
        return raw.takeIf { it.length in 2..36 } ?: "${candidate.location.title} • مسیر ${index + 1}"
    }

    private fun openSubscriptionForPremium() {
        Toast.makeText(
            this,
            "برای انتخاب دستی لوکیشن ابتدا اشتراک تهیه کنید",
            Toast.LENGTH_LONG,
        ).show()
        BlueVpnUiGuard.start(
            this,
            Intent(this, BlueVpnSubscriptionsActivity::class.java),
        )
    }

    private fun selectServer(
        group: LocationGroup,
        candidate: BlueVpnLocationUtil.Candidate,
    ) {
        val changed = MmkvManager.getSelectServer() != candidate.guid || BlueVpnPreferences.smartBalance(this)
        BlueVpnPreferences.setManualServerSelection(
            this,
            group.location.key,
            candidate.guid,
        )
        // While a live tunnel owns MMKV, keep the daemon's selected GUID
        // untouched. The requested switch is stored in BlueVpnPreferences and
        // Home performs stop -> exact GUID start after the old core confirms
        // STOP_SUCCESS.
        if (!BlueVpnRuntimeGate.connectionActive(this)) {
            MmkvManager.setSelectServer(candidate.guid)
        }
        setResult(Activity.RESULT_OK, Intent()
            .putExtra(EXTRA_LOCATION_CHANGED, changed)
            .putExtra(EXTRA_LOCATION_KEY, group.location.key)
            .putExtra(EXTRA_LOCATION_TITLE, "${group.location.flag} ${group.location.title}"))
        Toast.makeText(this, "${routeLabel(candidate, 0)} انتخاب شد", Toast.LENGTH_SHORT).show()
        finish()
    }

    private fun selectAutomatic() {
        val entitlement = BlueVpnEntitlement.reconcile(this)
        if (!entitlement.canConnect) {
            Toast.makeText(this, entitlement.connectionNotice, Toast.LENGTH_LONG).show()
            return
        }
        val changed = !BlueVpnPreferences.smartBalance(this)
        BlueVpnPreferences.setAutomaticSelection(this)
        val candidates = BlueVpnLocationUtil.instantCandidates(this, maxCandidates = 24)
        val decision = BlueVpnSmartSelector.decide(this, candidates)
        if (decision == null) {
            Toast.makeText(this, "سرور مجاز و سالمی در Pool فعلی پیدا نشد", Toast.LENGTH_LONG).show()
            scheduleCandidateReload(force = true)
            return
        }
        if (!BlueVpnRuntimeGate.connectionActive(this)) {
            MmkvManager.setSelectServer(decision.candidate.guid)
        }
        setResult(Activity.RESULT_OK, Intent()
            .putExtra(EXTRA_LOCATION_CHANGED, changed)
            .putExtra(EXTRA_LOCATION_KEY, "")
            .putExtra(EXTRA_LOCATION_TITLE, "انتخاب هوشمند"))
        Toast.makeText(
            this,
            "${decision.candidate.location.flag} ${decision.candidate.location.title} • امتیاز ${decision.score}",
            Toast.LENGTH_LONG,
        ).show()
        finish()
    }

    private fun selectGroup(group: LocationGroup, automatic: Boolean, selectedLocation: String?) {
        if (BlueVpnEntitlement.resolve(this).tier != BlueVpnPlanTier.PREMIUM) {
            openSubscriptionForPremium()
            return
        }
        val currentPreferred = BlueVpnPreferences.preferredLocation(this).ifBlank { selectedLocation.orEmpty() }
        val changed = automatic || currentPreferred != group.location.key
        BlueVpnPreferences.setManualLocationSelection(this, group.location.key)
        if (!BlueVpnRuntimeGate.connectionActive(this)) {
            BlueVpnLocationUtil.instantCandidates(this, group.location.key)
                .firstOrNull()
                ?.let { MmkvManager.setSelectServer(it.guid) }
        }
        setResult(Activity.RESULT_OK, Intent()
            .putExtra(EXTRA_LOCATION_CHANGED, changed)
            .putExtra(EXTRA_LOCATION_KEY, group.location.key)
            .putExtra(EXTRA_LOCATION_TITLE, "${group.location.flag} ${group.location.title}"))
        Toast.makeText(this, if (changed) "${group.location.title} انتخاب شد" else "${group.location.title} فعال است", Toast.LENGTH_SHORT).show()
        finish()
    }

    private fun stopRefreshing() {
        renderHandler.removeCallbacks(refreshTimeoutRunnable)
        if (!::refreshButton.isInitialized) return
        refreshButton.isEnabled = true
        refreshButton.text = "تازه‌سازی"
    }

    private fun tabButton(label: String, action: () -> Unit): MaterialButton = MaterialButton(this).apply {
        text = label
        textSize = 11f
        isAllCaps = false
        insetTop = 0
        insetBottom = 0
        cornerRadius = dp(18)
        BlueVpnUiGuard.bind(this) { action() }
    }

    private fun smallButton(label: String): MaterialButton = MaterialButton(this).apply {
        text = label
        textSize = 11f
        isAllCaps = false
        insetTop = 0
        insetBottom = 0
        minWidth = 0
        minimumWidth = 0
        cornerRadius = dp(16)
        setTextColor(palette.textSecondary)
        backgroundTintList = ColorStateList.valueOf(palette.surfaceStrong)
        strokeColor = ColorStateList.valueOf(palette.stroke)
        strokeWidth = dp(1)
    }

    private fun card(radius: Int, fill: Int, stroke: Int): MaterialCardView = MaterialCardView(this).apply {
        this.radius = dp(radius).toFloat()
        cardElevation = 0f
        setCardBackgroundColor(fill)
        strokeColor = stroke
        strokeWidth = dp(1)
    }

    private fun textView(value: String, size: Float, color: Int, gravityValue: Int): TextView = TextView(this).apply {
        text = value
        textSize = size
        setTextColor(color)
        gravity = gravityValue
        includeFontPadding = false
    }

    private fun rounded(fill: Int, radiusDp: Int, stroke: Int? = null): GradientDrawable = GradientDrawable().apply {
        setColor(fill)
        cornerRadius = dp(radiusDp).toFloat()
        if (stroke != null) setStroke(dp(1), stroke)
    }

    private fun circle(fill: Int): GradientDrawable = GradientDrawable().apply {
        shape = GradientDrawable.OVAL
        setColor(fill)
    }

    private fun dp(value: Int): Int = (value * resources.displayMetrics.density).toInt()
}
