package com.v2ray.ang.ui

import android.app.Activity
import android.content.Intent
import android.content.res.ColorStateList
import android.graphics.Typeface
import android.graphics.drawable.GradientDrawable
import android.os.Bundle
import android.os.Handler
import android.os.Looper
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
import com.v2ray.ang.bluevpn.BlueVpnBackgroundOptimizer
import com.v2ray.ang.bluevpn.BlueVpnLocation
import com.v2ray.ang.bluevpn.BlueVpnLocationUtil
import com.v2ray.ang.bluevpn.BlueVpnPalette
import com.v2ray.ang.bluevpn.BlueVpnPerformance
import com.v2ray.ang.bluevpn.BlueVpnPreferences
import com.v2ray.ang.bluevpn.BlueVpnRuntimeGate
import com.v2ray.ang.bluevpn.BlueVpnTheme
import com.v2ray.ang.bluevpn.BlueVpnTapsellManager
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
    private lateinit var locationsScrollView: ScrollView
    private lateinit var emptyText: TextView
    private lateinit var nativeBannerHost: FrameLayout
    private lateinit var refreshButton: MaterialButton
    private lateinit var entitlementSubtitle: TextView
    private lateinit var automaticSubtitle: TextView
    private lateinit var allTabButton: TextView
    private lateinit var favoritesTabButton: TextView
    private lateinit var recentTabButton: TextView
    private var selectedTab = LocationTab.ALL
    private var query = ""
    private var firstResume = true
    private var initialScrollRestored = false
    private val searchHandler = Handler(Looper.getMainLooper())
    private val renderHandler = Handler(Looper.getMainLooper())
    private var renderGeneration = 0
    private var candidateLoadInProgress = false
    private var candidateReloadPending = false
    private var candidateLoadError = ""
    private var entitlementRepairAttempted = false
    private var accountSyncInProgress = false
    private var accountSyncPending = false
    private var healthSweepRequested = false
    private var healthSweepInProgress = false
    private var renderedPremiumMode: Boolean? = null
    private var lastRenderedStructureFingerprint: String = ""
    private val healthStatusViews = LinkedHashMap<String, TextView>()
    private val serverHealthViews = LinkedHashMap<String, TextView>()
    private val expandedLocationKeys = linkedSetOf<String>()
    private val healthRefreshRunnable = Runnable {
        refreshVisibleHealthPresentation()
    }
    private val searchRunnable = Runnable { renderLocations() }
    private val renderRunnable = Runnable {
        renderLocationsNow(renderGeneration)
    }
    private var appendChunkRunnable: Runnable? = null
    private val refreshTimeoutRunnable = Runnable { stopRefreshing() }
    private val candidateReloadRunnable = Runnable {
        val force = candidateReloadPending
        candidateReloadPending = false
        loadCandidates(force = force)
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

        mainViewModel.startListenBroadcast()
        mainViewModel.updateListAction.observe(this) {
            // v2rayNG can emit this broadcast repeatedly while ping/import/runtime
            // metadata changes. Never redraw immediately. Invalidate the decoded
            // snapshot and wait for a quiet window before checking whether the
            // actual location membership changed.
            BlueVpnLocationUtil.invalidateResolvedCache()
            stopRefreshing()
            scheduleCandidateReload(force = false, delayMs = 2_000L)
        }
        mainViewModel.updateTestResultAction.observe(this) {
            // Ping/test-result broadcasts are presentation-only. Never rebuild the
            // country/server tree here; refresh the visible labels from MMKV so the
            // current scroll position and expanded groups remain untouched.
            stopRefreshing()
            healthSweepInProgress = false
            renderHandler.removeCallbacks(healthRefreshRunnable)
            renderHandler.postDelayed(healthRefreshRunnable, 120L)
        }
        renderLocations()
        loadCandidates(force = false)
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
        // Returning to Locations is a pure local/UI operation. The page must not
        // refresh WordPress, subscriptions, MMKV ownership or cloud metadata just
        // because it became visible again.
        updateEntitlementUi()
    }

    override fun onPause() {
        renderGeneration++
        searchHandler.removeCallbacks(searchRunnable)
        appendChunkRunnable?.let { renderHandler.removeCallbacks(it) }
        appendChunkRunnable = null
        renderHandler.removeCallbacksAndMessages(null)
        super.onPause()
    }

    override fun onDestroy() {
        renderGeneration++
        searchHandler.removeCallbacks(searchRunnable)
        renderHandler.removeCallbacksAndMessages(null)
        super.onDestroy()
    }

    private fun scheduleCandidateReload(
        force: Boolean,
        delayMs: Long = 350L,
    ) {
        candidateReloadPending = candidateReloadPending || force
        renderHandler.removeCallbacks(candidateReloadRunnable)
        renderHandler.postDelayed(candidateReloadRunnable, delayMs.coerceAtLeast(250L))
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

        // Keep the existing rows completely untouched while a background snapshot
        // is being checked. Only an initially empty screen may render its loading
        // placeholder.
        if (listContainer.childCount == 0 && BlueVpnLocationUtil.cachedCandidates(this).isEmpty()) {
            renderLocations()
        }

        lifecycleScope.launch(Dispatchers.Default) {
            val result = runCatching {
                // Never enumerate/reconcile hundreds of profiles while the stock
                // v2rayNG importer owns MMKV. That made Locations look frozen for
                // the whole network timeout window. Keep any same-entitlement cache
                // visible and let the importer broadcast trigger the trailing reload.
                if (BlueVpnRuntimeGate.subscriptionMutationActive()) {
                    return@runCatching BlueVpnLocationUtil.cachedCandidates(
                        this@BlueVpnServersActivity,
                    )
                }
                val loaded = BlueVpnLocationUtil.allCandidates(
                    this@BlueVpnServersActivity,
                    forceRefresh = requestedForce,
                )

                // IMPORTANT: an empty local pool is not permission to refresh the
                // subscription. Only the explicit "تازه‌سازی" action may
                // reconcile/import.
                loaded
            }

            withContext(Dispatchers.Main) {
                candidateLoadInProgress = false
                stopRefreshing()
                if (isFinishing || isDestroyed) return@withContext

                val currentIdentity = BlueVpnAccountManager
                    .entitlementIdentityFingerprint(this@BlueVpnServersActivity)
                if (requestIdentity != currentIdentity) {
                    // Entitlement metadata changed while a local decode was in
                    // flight. Re-read the local snapshot only; do not turn it into
                    // a network/subscription refresh.
                    scheduleCandidateReload(force = false)
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
                requestHealthSweepIfNeeded(loaded, force = requestedForce)
                if (
                    selectAutomaticAfterLoad &&
                    BlueVpnPreferences.smartBalance(this@BlueVpnServersActivity)
                ) {
                    if (!BlueVpnRuntimeGate.connectionActive(this@BlueVpnServersActivity)) {
                        loaded.firstOrNull()?.let { MmkvManager.setSelectServer(it.guid) }
                    }
                }

                val nextFingerprint = locationStructureFingerprint(loaded)
                if (nextFingerprint != lastRenderedStructureFingerprint) {
                    renderLocations()
                } else {
                    // Health/ping changes must not destroy and recreate rows.
                    refreshVisibleHealthPresentation()
                }

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


    private fun requestHealthSweepIfNeeded(
        candidates: List<BlueVpnLocationUtil.Candidate>,
        force: Boolean,
    ) {
        if (candidates.isEmpty()) return

        val unknown = candidates.count { it.delay <= 0L }
        if (!force && (healthSweepRequested || unknown == 0)) return
        if (healthSweepInProgress) return

        // Do not launch a global batch test while a VPN session is actively
        // carrying traffic. The active route already has its own real-ping flow.
        if (mainViewModel.isRunning.value == true) return

        healthSweepRequested = true
        healthSweepInProgress = true

        // testAllRealPing() is the stock v2rayNG measurement pipeline already used
        // elsewhere in BlueVPN. It publishes results through updateTestResultAction.
        mainViewModel.testAllRealPing()

        // Fail-safe only: if upstream does not publish a completion event, allow a
        // later manual refresh to start another sweep.
        renderHandler.postDelayed({
            healthSweepInProgress = false
        }, 30_000L)
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

        nativeBannerHost = FrameLayout(this).apply {
            visibility = View.GONE
            clipChildren = true
            clipToPadding = true
        }
        root.addView(
            nativeBannerHost,
            LinearLayout.LayoutParams(-1, -2).apply {
                bottomMargin = dp(8)
            },
        )
        attachFreeNativeBanner()

        emptyText = textView("مکانی برای نمایش وجود ندارد", 13f, palette.textMuted, Gravity.CENTER).apply {
            visibility = View.GONE
            setPadding(0, dp(30), 0, dp(30))
        }
        root.addView(emptyText)

        listContainer = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(0, 0, 0, dp(16))
        }
        locationsScrollView = ScrollView(this).apply {
            isFillViewport = true
            overScrollMode = View.OVER_SCROLL_NEVER
            addView(listContainer)
        }
        root.addView(locationsScrollView, LinearLayout.LayoutParams(-1, 0, 1f))
        return frame
    }

    private fun attachFreeNativeBanner() {
        if (!BlueVpnEntitlement.resolveUi(this).isFree) {
            if (::nativeBannerHost.isInitialized) nativeBannerHost.visibility = View.GONE
            return
        }
        nativeBannerHost.visibility = View.GONE
        BlueVpnTapsellManager.attachPlacement(
            activity = this,
            host = nativeBannerHost,
            type = "native_banner",
            onUnavailable = {
                if (::nativeBannerHost.isInitialized) {
                    nativeBannerHost.visibility = View.GONE
                }
            },
        )
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
        BlueVpnEntitlement.resolveUi(this).tier == BlueVpnPlanTier.PREMIUM

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
        val entitlement = BlueVpnEntitlement.resolveUi(this)
        if (::entitlementSubtitle.isInitialized) {
            entitlementSubtitle.text = when (entitlement.tier) {
                BlueVpnPlanTier.PREMIUM -> "Premium • انتخاب هوشمند و انتخاب دستی همه مکان‌ها"
                BlueVpnPlanTier.FREE -> "رایگان • انتخاب هوشمند فقط از Pool رایگان"
                BlueVpnPlanTier.UNAVAILABLE -> "دسترسی اتصال هنوز آماده نیست"
            }
        }
        if (::automaticSubtitle.isInitialized) {
            val activeAutoCandidate = if (BlueVpnPreferences.smartBalance(this)) {
                val guid = MmkvManager.getSelectServer().orEmpty()
                BlueVpnLocationUtil.cachedCandidates(this).firstOrNull { it.guid == guid }
            } else null
            automaticSubtitle.text = when (entitlement.tier) {
                BlueVpnPlanTier.PREMIUM -> activeAutoCandidate?.let { candidate ->
                    val peers = BlueVpnLocationUtil.cachedCandidates(this)
                        .filter { it.location.key == candidate.location.key }
                    val ordinal = stableServerRows(candidate.location, peers)
                        .firstOrNull { it.first.guid == candidate.guid }
                        ?.second
                    "فعال • " + candidate.location.flag + " " + candidate.location.title +
                        (ordinal?.let { " " + it }.orEmpty()) +
                        " • جابه‌جایی خودکار هنگام افت واقعی"
                } ?: "بهترین اتصال با پینگ، سابقه و سلامت شبکه انتخاب می‌شود"
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
     * Explicit refresh pipeline. Merely opening/resuming Locations is local-only;
     * only the user's "تازه‌سازی" action may contact WordPress or mutate the
     * subscription/MMKV pool.
     */
    private fun refreshEntitlementState(force: Boolean) {
        updateEntitlementUi()
        if (!force) return
        if (!BlueVpnAccountManager.hasSession(this)) {
            // Guest/Free refresh is handled by the same explicit repair API.
            if (accountSyncInProgress) return
            accountSyncInProgress = true
            lifecycleScope.launch(Dispatchers.IO) {
                val repair = BlueVpnAccountManager.prepareFreeAccess(
                    this@BlueVpnServersActivity,
                    force = true,
                )
                withContext(Dispatchers.Main) {
                    accountSyncInProgress = false
                    if (isFinishing || isDestroyed) return@withContext
                    candidateLoadError = repair.exceptionOrNull()?.let {
                        "دریافت سرورها ناموفق بود؛ دوباره تلاش کنید"
                    }.orEmpty()
                    BlueVpnLocationUtil.invalidateCache()
                    loadCandidates(force = true)
                }
            }
            return
        }

        if (accountSyncInProgress) {
            accountSyncPending = true
            return
        }

        accountSyncInProgress = true
        lifecycleScope.launch(Dispatchers.IO) {
            val result = runCatching {
                // Manual refresh has one owner and one ordering:
                // 1) refresh authoritative account snapshot,
                // 2) reconcile/import entitlement servers,
                // 3) publish a new local candidate snapshot.
                // Refresh account metadata first, but do not let sync() start a
                // subscription import of its own. awaitEntitlementServers() below
                // is the single owner of the MMKV import transaction. The previous
                // two-owner pipeline could run the stock importer twice for one tap.
                val account = BlueVpnAccountManager.sync(
                    this@BlueVpnServersActivity,
                    force = true,
                    deferEntitlementWork = true,
                ).getOrThrow()
                if (
                    account.subscriptionActive &&
                    account.subscriptionUrl.startsWith("http")
                ) {
                    BlueVpnAccountManager.awaitEntitlementServers(
                        this@BlueVpnServersActivity,
                    ).getOrThrow()
                } else {
                    BlueVpnAccountManager.prepareFreeAccess(
                        this@BlueVpnServersActivity,
                        force = true,
                    ).getOrThrow()
                }
            }

            withContext(Dispatchers.Main) {
                accountSyncInProgress = false
                if (isFinishing || isDestroyed) return@withContext

                candidateLoadError = result.exceptionOrNull()?.let {
                    "دریافت سرورها ناموفق بود؛ دوباره تلاش کنید"
                }.orEmpty()
                updateEntitlementUi()
                BlueVpnLocationUtil.invalidateCache()
                loadCandidates(force = true)

                if (accountSyncPending) {
                    accountSyncPending = false
                    // Coalesce repeated taps into one trailing manual refresh.
                    refreshEntitlementState(force = true)
                }
            }
        }
    }

    private fun updateTabs() {
        applyTab(allTabButton, selectedTab == LocationTab.ALL)
        applyTab(favoritesTabButton, selectedTab == LocationTab.FAVORITES)
        applyTab(recentTabButton, selectedTab == LocationTab.RECENT)
    }

    private fun applyTab(button: TextView, active: Boolean) {
        button.background = rounded(if (active) palette.accent else android.graphics.Color.TRANSPARENT, 18)
        button.setTextColor(if (active) android.graphics.Color.WHITE else palette.textSecondary)
    }

    private fun locationStructureFingerprint(
        candidates: List<BlueVpnLocationUtil.Candidate>,
    ): String {
        val entitlementIdentity =
            BlueVpnAccountManager.entitlementIdentityFingerprint(this)
        val uiEntitlement = BlueVpnEntitlement.resolveUi(this)
        val payload = buildString {
            append(entitlementIdentity).append('|')
            append(uiEntitlement.tier.name).append('|')
            append(uiEntitlement.manualSelectionAllowed).append('|')
            append(selectedTab.name).append('|')
            append(query).append('|')

            // Structural identity only. Ping, health, selected GUID, preferred
            // location and quarantine flags are deliberately excluded because they
            // are volatile runtime state and must not recreate the row tree.
            candidates
                .map { "${it.location.key}:${it.guid}" }
                .sorted()
                .forEach {
                    append(it).append(';')
                }
        }
        return payload.hashCode().toString()
    }

    private fun renderLocations() {
        if (!::listContainer.isInitialized || isFinishing || isDestroyed) return
        renderGeneration++
        renderHandler.removeCallbacks(renderRunnable)
        appendChunkRunnable?.let { renderHandler.removeCallbacks(it) }
        appendChunkRunnable = null
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
        val uiEntitlement = BlueVpnEntitlement.resolveUi(this)
        val manualSelectionAllowed = uiEntitlement.manualSelectionAllowed
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
            healthStatusViews.clear()
        serverHealthViews.clear()
            val entitlement = uiEntitlement
            emptyText.text = when {
                candidateLoadError.isNotBlank() -> candidateLoadError
                BlueVpnRuntimeGate.subscriptionMutationActive() && entitlement.isPremium ->
                    "در حال دریافت Pool اختصاصی Premium… صفحه قفل نیست و پس از آماده‌شدن خودکار نمایش داده می‌شود"
                BlueVpnRuntimeGate.subscriptionMutationActive() && entitlement.isFree ->
                    "در حال دریافت Pool رایگان… صفحه قفل نیست و پس از آماده‌شدن خودکار نمایش داده می‌شود"
                candidateLoadInProgress && entitlement.isPremium ->
                    "در حال آماده‌سازی اولین فهرست مکان‌ها…"
                candidateLoadInProgress && entitlement.isFree ->
                    "در حال خواندن Pool رایگان…"
                entitlement.isPremium ->
                    "سرورهای اشتراک هنوز دریافت نشده‌اند؛ تازه‌سازی را بزنید"
                entitlement.isFree && BlueVpnAccountManager.warpFreeEnabled(this) -> "WARP رایگان فعال است • انتخاب سرور توسط Cloudflare/Aether انجام می‌شود"
                entitlement.isFree -> "سرور رایگان فعالی برای نمایش پیدا نشد"
                else -> "دسترسی فعالی برای دریافت سرور وجود ندارد"
            }
            emptyText.visibility = View.VISIBLE
            return
        }
        val preservedScrollY = if (::locationsScrollView.isInitialized) {
            locationsScrollView.scrollY
        } else {
            0
        }
        listContainer.removeAllViews()
        healthStatusViews.clear()
        serverHealthViews.clear()
        val selectedLocation = candidates.firstOrNull { it.guid == selected }?.location?.key
        val connectedNow = BlueVpnRuntimeGate.connectionActive(this)
        // While the VPN is actually connected, the visible active country must
        // follow the route that is really selected by the core. A stale manual
        // preference from a previous session must never override the connected
        // route (for example: connected to Netherlands but Germany stays blue).
        val activeLocationKey = when {
            connectedNow -> selectedLocation.orEmpty()
            automatic -> ""
            else -> preferred.ifBlank { selectedLocation.orEmpty() }
        }
        // Expansion is user-owned UI state. Runtime selection must not silently
        // open a country because that changes row heights and causes scroll jumps.
        val recentKeys = BlueVpnExperience.history(this).map { it.locationKey }.distinct()
        val recentIndex = recentKeys.withIndex().associate { it.value to it.index }

        val groups = candidates
            .groupBy { it.location.key }
            .mapNotNull { (_, servers) ->
                val location = servers.firstOrNull()?.location ?: return@mapNotNull null
                // A config containing only an IP or a generic remark has no
                // trustworthy country before its first successful exit trace.
                // Keep those routes available to automatic selection, but never
                // present "unknown" as if it were a manually selectable country.
                if (location.key == "unknown") return@mapNotNull null
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
                    LocationTab.RECENT ->
                        compareBy<LocationGroup> { recentIndex[it.location.key] ?: Int.MAX_VALUE }
                            .thenBy { it.location.title }
                    LocationTab.FAVORITES ->
                        compareBy<LocationGroup> { it.location.title }
                    LocationTab.ALL ->
                        // Keep the master list spatially stable. Selection, active
                        // connection and ping/health are volatile state and must
                        // never move a country to another position under the user's finger.
                        compareBy<LocationGroup> { it.location.title }
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
                ) {
                    if (appendChunkRunnable === this) appendChunkRunnable = null
                    return
                }
                val end = (groupIndex + chunkSize).coerceAtMost(groups.size)
                while (groupIndex < end) {
                    val group = groups[groupIndex++]
                    val active =
                        activeLocationKey.isNotBlank() &&
                        group.location.key == activeLocationKey
                    listContainer.addView(
                        createLocationSection(group, active, manualSelectionAllowed),
                        LinearLayout.LayoutParams(-1, -2).apply {
                            bottomMargin = dp(8)
                        },
                    )
                }
                if (groupIndex < groups.size) {
                    renderHandler.post(this)
                } else {
                    if (appendChunkRunnable === this) appendChunkRunnable = null

                    // Restore only after the complete tree exists. Restoring while
                    // only the first chunk is mounted clamps a deep offset to the
                    // temporary short height and produces a visible jump.
                    if (::locationsScrollView.isInitialized) {
                        val targetScrollY = if (!initialScrollRestored) {
                            initialScrollRestored = true
                            getSharedPreferences("bluevpn_locations_ui", MODE_PRIVATE)
                                .getInt("scroll_y", 0)
                        } else {
                            preservedScrollY
                        }
                        if (targetScrollY > 0) {
                            locationsScrollView.post {
                                locationsScrollView.scrollTo(0, targetScrollY)
                            }
                        }
                    }
                }
            }
        }
        appendChunkRunnable = appendChunk
        lastRenderedStructureFingerprint = locationStructureFingerprint(candidates)

        // Mount the first rows synchronously so the screen never flashes blank;
        // subsequent chunks are still yielded to the main looper.
        appendChunk.run()
    }

    private fun availabilityLabel(
        location: BlueVpnLocation,
        servers: List<BlueVpnLocationUtil.Candidate>,
    ): String {
        val usableRoutes = servers.count {
            !BlueVpnPreferences.isSessionInactive(this, it.guid)
        }
        val backgroundBucket =
            BlueVpnBackgroundOptimizer.bestBucket(this, servers.map { it.guid })
        return when {
            location.key == "unknown" -> "در حال شناسایی لوکیشن"
            usableRoutes <= 0 -> "در انتظار بررسی"
            backgroundBucket != null ->
                "دسته‌بندی شبکه شما: ${BlueVpnBackgroundOptimizer.bucketLabel(backgroundBucket)}"
            else -> "آماده اتصال"
        }
    }

    /**
     * Ping/test broadcasts are presentation-only. Never clear/rebuild/re-sort
     * the location list for them; update only the visible status labels.
     */
    private fun refreshVisibleHealthPresentation() {
        if (
            isFinishing ||
            isDestroyed ||
            !::listContainer.isInitialized ||
            healthStatusViews.isEmpty()
        ) return

        val groups = BlueVpnLocationUtil.cachedCandidates(this)
            .groupBy { it.location.key }

        healthStatusViews.forEach { (locationKey, view) ->
            val servers = groups[locationKey].orEmpty()
            val location = servers.firstOrNull()?.location ?: return@forEach
            val next = servers.size.toString() + " سرور • " + availabilityLabel(location, servers)
            if (view.text?.toString() != next) {
                view.text = next
            }
        }
        val selectedGuid=MmkvManager.getSelectServer().orEmpty()
        val automatic=BlueVpnPreferences.smartBalance(this)
        val connected=BlueVpnRuntimeGate.connectionActive(this)
        val mode=BlueVpnPreferences.selectionMode(this)
        val manualGuid=BlueVpnPreferences.manualServerGuid(this)
        serverHealthViews.forEach { (guid, view) ->
            val candidate=groups.values.asSequence().flatten().firstOrNull { it.guid==guid } ?: return@forEach
            val liveDelay = MmkvManager.decodeServerAffiliationInfo(guid)?.testDelayMillis ?: candidate.delay
            val liveCandidate = if (liveDelay == candidate.delay) candidate else candidate.copy(delay = liveDelay)
            val active=(connected&&guid==selectedGuid)||(mode==BlueVpnSelectionMode.MANUAL_SERVER&&manualGuid==guid)
            val next=serverHealthLabel(liveCandidate,active,automatic&&connected&&guid==selectedGuid)
            if(view.text?.toString()!=next)view.text=next
        }
    }

    private fun stableServerRows(
        location: BlueVpnLocation,
        servers: List<BlueVpnLocationUtil.Candidate>,
    ): List<Pair<BlueVpnLocationUtil.Candidate, Int>> {
        val prefs = getSharedPreferences("bluevpn_server_labels", MODE_PRIVATE)
        val prefix = "ordinal:" + location.key + ":"
        var next = prefs.all
            .filterKeys { it.startsWith(prefix) }
            .values
            .mapNotNull { it as? Int }
            .maxOrNull() ?: 0
        val editor = prefs.edit()
        val rows = servers
            .sortedBy { BlueVpnLocationUtil.serverIdentity(it.profile) }
            .map { candidate ->
                val identity = BlueVpnLocationUtil.serverIdentity(candidate.profile)
                val key = prefix + identity
                var ordinal = prefs.getInt(key, 0)
                if (ordinal <= 0) {
                    next += 1
                    ordinal = next
                    editor.putInt(key, ordinal)
                }
                candidate to ordinal
            }
        editor.apply()
        return rows.sortedBy { it.second }
    }

    private fun signalLevel(candidate: BlueVpnLocationUtil.Candidate): Int {
        if (BlueVpnPreferences.isSessionInactive(this, candidate.guid)) return 0
        return when {
            candidate.delay in 1..80 -> 4
            candidate.delay in 81..160 -> 3
            candidate.delay in 161..280 -> 2
            candidate.delay > 280 -> 1
            else -> when (BlueVpnLocationUtil.healthScore(this, candidate)) {
                in 82..100 -> 4
                in 68..81 -> 3
                in 45..67 -> 2
                in 1..44 -> 1
                else -> 0
            }
        }
    }

    private fun signalBars(candidate: BlueVpnLocationUtil.Candidate): String =
        when (signalLevel(candidate)) {
            4 -> "▂▄▆█"
            3 -> "▂▄▆"
            2 -> "▂▄"
            1 -> "▂"
            else -> "—"
        }

    private fun signalQuality(candidate: BlueVpnLocationUtil.Candidate): String =
        when (signalLevel(candidate)) {
            4 -> "عالی"
            3 -> "خوب"
            2 -> "متوسط"
            1 -> "ضعیف"
            else -> "در حال سنجش"
        }

    private fun serverHealthLabel(
        candidate: BlueVpnLocationUtil.Candidate,
        active: Boolean,
        automaticActive: Boolean,
    ): String {
        val state = when {
            automaticActive -> "فعال • خودکار"
            active -> "فعال • دستی"
            BlueVpnPreferences.isSessionInactive(this, candidate.guid) -> "موقتاً نامناسب"
            candidate.delay > 0 -> candidate.delay.toString() + " ms • " + signalQuality(candidate)
            else -> "در حال سنجش"
        }
        return signalBars(candidate) + "  " + state
    }

    private fun createServerRow(
        group: LocationGroup,
        candidate: BlueVpnLocationUtil.Candidate,
        ordinal: Int,
        premium: Boolean,
    ): View {
        val selectedGuid = MmkvManager.getSelectServer().orEmpty()
        val connected = BlueVpnRuntimeGate.connectionActive(this)
        val automatic = BlueVpnPreferences.smartBalance(this)
        val mode = BlueVpnPreferences.selectionMode(this)
        val manualGuid = BlueVpnPreferences.manualServerGuid(this)
        val automaticActive = automatic && connected && candidate.guid == selectedGuid
        val manualActive = mode == BlueVpnSelectionMode.MANUAL_SERVER && manualGuid == candidate.guid
        val active = automaticActive || manualActive || (connected && candidate.guid == selectedGuid)

        val serverCard = card(
            radius = 18,
            fill = if (active) palette.surfaceStrong else palette.surface,
            stroke = if (active) palette.accent else palette.stroke,
        ).apply {
            strokeWidth = dp(if (active) 2 else 1)
            cardElevation = dp(if (active) 2 else 1).toFloat()
            isClickable = true
            isFocusable = true
            contentDescription = group.location.title + " " + ordinal + "؛ " + serverHealthLabel(candidate, active, automaticActive)
            BlueVpnUiGuard.bind(this) {
                if (!premium) openSubscriptionForPremium()
                else selectServer(group, candidate, ordinal)
            }
        }
        val row = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            setPadding(dp(16), dp(10), dp(16), dp(10))
        }
        serverCard.addView(row)

        val titleBox = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER_VERTICAL
        }
        titleBox.addView(textView(group.location.title + " " + ordinal, 14f, palette.textPrimary, Gravity.END).apply {
            setTypeface(typeface, Typeface.BOLD)
        })
        val health = textView(
            serverHealthLabel(candidate, active, automaticActive),
            11f,
            when {
                active -> palette.accent
                signalLevel(candidate) >= 3 -> if (palette.dark) 0xFF66D19E.toInt() else 0xFF18875C.toInt()
                signalLevel(candidate) == 2 -> if (palette.dark) 0xFFFFCC66.toInt() else 0xFF9A6B00.toInt()
                signalLevel(candidate) == 1 -> if (palette.dark) 0xFFFF8B8B.toInt() else 0xFFC13B3B.toInt()
                else -> palette.textMuted
            },
            Gravity.END,
        ).apply {
            setPadding(0, dp(5), 0, 0)
            letterSpacing = 0.01f
        }
        serverHealthViews[candidate.guid] = health
        titleBox.addView(health)
        row.addView(titleBox, LinearLayout.LayoutParams(0, -1, 1f))

        val action = when {
            automaticActive -> "خودکار"
            manualActive -> "دستی"
            !premium -> "🔒"
            else -> "انتخاب"
        }
        row.addView(textView(action, 10.5f, if (active) palette.accent else palette.textSecondary, Gravity.CENTER).apply {
            setTypeface(typeface, Typeface.BOLD)
        }, LinearLayout.LayoutParams(dp(62), dp(38)))
        return serverCard
    }

    private fun createLocationSection(
        group: LocationGroup,
        active: Boolean,
        premium: Boolean,
    ): View {
        val expanded = group.location.key in expandedLocationKeys
        val automatic = BlueVpnPreferences.smartBalance(this)
        val outer = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL }
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
            contentDescription = group.location.title + "؛ " + group.servers.size + " سرور؛ " + if (expanded) "باز" else "بسته"
            BlueVpnUiGuard.bind(this) {
                if (expanded) expandedLocationKeys.remove(group.location.key)
                else expandedLocationKeys.add(group.location.key)
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
        val availabilityView = textView(
            group.servers.size.toString() + " سرور • " + availabilityLabel(group.location, group.servers),
            10.5f,
            palette.textMuted,
            Gravity.END,
        ).apply {
            setPadding(0, dp(5), 0, 0)
            maxLines = 1
            ellipsize = android.text.TextUtils.TruncateAt.END
        }
        healthStatusViews[group.location.key] = availabilityView
        content.addView(availabilityView)
        row.addView(content, LinearLayout.LayoutParams(0, -1, 1f))

        val favoriteButton = TextView(this).apply {
            text = if (group.favorite) "★" else "☆"
            textSize = 18f
            gravity = Gravity.CENTER
            includeFontPadding = false
            background = rounded(android.graphics.Color.TRANSPARENT, 15)
            setTextColor(if (group.favorite) palette.accent else palette.textMuted)
            contentDescription = if (group.favorite) "حذف از علاقه‌مندی" else "افزودن به علاقه‌مندی"
            BlueVpnUiGuard.bind(this) {
                BlueVpnExperience.toggleFavorite(this@BlueVpnServersActivity, group.location.key)
                renderLocations()
            }
        }
        row.addView(favoriteButton, LinearLayout.LayoutParams(dp(38), dp(40)))

        val chooseCountry = textView(
            when {
                !premium -> "🔒"
                active && automatic -> "خودکار"
                active -> "فعال"
                else -> "انتخاب کشور"
            },
            if (!premium) 16f else 10f,
            if (active) palette.accent else palette.textSecondary,
            Gravity.CENTER,
        ).apply {
            setTypeface(typeface, Typeface.BOLD)
            isClickable = true
            isFocusable = true
            BlueVpnUiGuard.bind(this) {
                if (!premium) openSubscriptionForPremium()
                else selectGroup(
                    group = group,
                    automatic = BlueVpnPreferences.smartBalance(this@BlueVpnServersActivity),
                    selectedLocation = null,
                )
            }
        }
        row.addView(chooseCountry, LinearLayout.LayoutParams(dp(76), dp(40)))
        row.addView(textView(if (expanded) "⌃" else "⌄", 18f, palette.textMuted, Gravity.CENTER),
            LinearLayout.LayoutParams(dp(28), dp(40)))

        outer.addView(header, LinearLayout.LayoutParams(-1, dp(74)))

        if (expanded) {
            val serverBox = LinearLayout(this).apply {
                orientation = LinearLayout.VERTICAL
                setPadding(dp(8), dp(6), dp(8), 0)
            }
            stableServerRows(group.location, group.servers).forEachIndexed { index, pair ->
                val candidate = pair.first
                val ordinal = pair.second
                serverBox.addView(
                    createServerRow(group, candidate, ordinal, premium),
                    LinearLayout.LayoutParams(-1, dp(70)).apply {
                        if (index > 0) topMargin = dp(5)
                    },
                )
            }
            outer.addView(serverBox, LinearLayout.LayoutParams(-1, -2))
        }
        return outer
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

    private fun rememberLocationScroll() {
        if (!::locationsScrollView.isInitialized) return
        getSharedPreferences("bluevpn_locations_ui", MODE_PRIVATE)
            .edit()
            .putInt("scroll_y", locationsScrollView.scrollY)
            .apply()
    }

    private fun finishWithLocationResult() {
        rememberLocationScroll()
        finish()
    }


    private fun selectAutomatic() {
        val entitlement = BlueVpnEntitlement.resolveUi(this)
        if (!entitlement.canConnect) {
            Toast.makeText(this, entitlement.connectionNotice, Toast.LENGTH_LONG).show()
            return
        }
        val currentGuid = MmkvManager.getSelectServer().orEmpty()
        // Tapping AUTO is also an explicit request to re-evaluate an already
        // automatic session, so Home must rebuild the live candidate queue.
        val changed = true
        // Commit AUTO before ranking and explicitly invalidate the previous
        // sticky choice. Otherwise the old manually selected location can win
        // again and the UI appears to have two active modes at once.
        BlueVpnPreferences.setAutomaticSelection(this)
        BlueVpnSmartSelector.resetForAutomaticSelection(this, currentGuid)
        val cachedPool = BlueVpnLocationUtil.cachedCandidates(this)
        if (cachedPool.isEmpty()) {
            Toast.makeText(this, "Pool هنوز آماده نیست؛ در حال بارگذاری سرورها", Toast.LENGTH_SHORT).show()
            loadCandidates(force = false, selectAutomaticAfterLoad = true)
            return
        }
        // Ranking reads historical/AI/MMKV metadata. Keep that work off the UI
        // thread; only commit the chosen GUID after the worker returns.
        lifecycleScope.launch(Dispatchers.Default) {
            val ranked = BlueVpnSmartSelector.connectionOrder(
                this@BlueVpnServersActivity,
                cachedPool,
            )
            val best = ranked.firstOrNull()
            val decision = best?.let {
                BlueVpnSmartSelector.recordAutomaticConnectionChoice(
                    this@BlueVpnServersActivity,
                    it,
                    ranked.size,
                )
            }
            withContext(Dispatchers.Main) {
                if (isFinishing || isDestroyed) return@withContext
                if (decision == null) {
                    Toast.makeText(
                        this@BlueVpnServersActivity,
                        "سرور مجاز و سالمی در Pool فعلی پیدا نشد",
                        Toast.LENGTH_LONG,
                    ).show()
                    loadCandidates(force = true)
                    return@withContext
                }
                if (!BlueVpnRuntimeGate.connectionActive(this@BlueVpnServersActivity)) {
                    MmkvManager.setSelectServer(decision.candidate.guid)
                }
                setResult(Activity.RESULT_OK, Intent()
                    .putExtra(EXTRA_LOCATION_CHANGED, changed)
                    .putExtra(EXTRA_LOCATION_KEY, "")
                    .putExtra(EXTRA_LOCATION_TITLE, "انتخاب هوشمند"))
                Toast.makeText(
                    this@BlueVpnServersActivity,
                    "${decision.candidate.location.flag} ${decision.candidate.location.title} • انتخاب خودکار فعال شد",
                    Toast.LENGTH_SHORT,
                ).show()
                if (BlueVpnRuntimeGate.connectionActive(this@BlueVpnServersActivity)) {
                    // Home owns live handover. Returning RESULT_OK is what actually
                    // starts the switch from the currently connected country.
                    finishWithLocationResult()
                } else {
                    updateEntitlementUi()
                    renderLocations()
                }
            }
        }
    }

    private fun selectGroup(group: LocationGroup, automatic: Boolean, selectedLocation: String?) {
        if (BlueVpnEntitlement.resolveUi(this).tier != BlueVpnPlanTier.PREMIUM) {
            openSubscriptionForPremium()
            return
        }
        val currentPreferred = BlueVpnPreferences.preferredLocation(this).ifBlank { selectedLocation.orEmpty() }
        val changed = automatic || currentPreferred != group.location.key
        BlueVpnPreferences.setManualLocationSelection(this, group.location.key)
        if (!BlueVpnRuntimeGate.connectionActive(this)) {
            BlueVpnLocationUtil.cachedCandidates(this)
                .firstOrNull { it.location.key == group.location.key }
                ?.let { MmkvManager.setSelectServer(it.guid) }
        }
        setResult(Activity.RESULT_OK, Intent()
            .putExtra(EXTRA_LOCATION_CHANGED, changed)
            .putExtra(EXTRA_LOCATION_KEY, group.location.key)
            .putExtra(EXTRA_LOCATION_TITLE, "${group.location.flag} ${group.location.title}"))
        Toast.makeText(this, if (changed) "${group.location.title} انتخاب شد" else "${group.location.title} فعال است", Toast.LENGTH_SHORT).show()
        if (BlueVpnRuntimeGate.connectionActive(this)) {
            // The Home Activity receives the result and performs the live location
            // handover. Staying here left the old connected GUID (e.g. Netherlands)
            // visually active even after choosing Germany.
            finishWithLocationResult()
        } else {
            updateEntitlementUi()
            renderLocations()
        }
    }

    private fun selectServer(
        group: LocationGroup,
        candidate: BlueVpnLocationUtil.Candidate,
        ordinal: Int,
    ) {
        if (BlueVpnEntitlement.resolveUi(this).tier != BlueVpnPlanTier.PREMIUM) {
            openSubscriptionForPremium()
            return
        }
        val oldMode = BlueVpnPreferences.selectionMode(this)
        val oldGuid = BlueVpnPreferences.manualServerGuid(this)
        val changed = oldMode != BlueVpnSelectionMode.MANUAL_SERVER || oldGuid != candidate.guid
        BlueVpnPreferences.setManualServerSelection(this, group.location.key, candidate.guid)
        if (!BlueVpnRuntimeGate.connectionActive(this)) {
            MmkvManager.setSelectServer(candidate.guid)
        }
        val title = group.location.flag + " " + group.location.title + " " + ordinal
        setResult(Activity.RESULT_OK, Intent()
            .putExtra(EXTRA_LOCATION_CHANGED, changed)
            .putExtra(EXTRA_LOCATION_KEY, group.location.key)
            .putExtra(EXTRA_LOCATION_TITLE, title))
        Toast.makeText(
            this,
            if (changed) group.location.title + " " + ordinal + " انتخاب شد" else group.location.title + " " + ordinal + " فعال است",
            Toast.LENGTH_SHORT,
        ).show()
        if (BlueVpnRuntimeGate.connectionActive(this)) {
            finishWithLocationResult()
        } else {
            updateEntitlementUi()
            renderLocations()
        }
    }
    private fun stopRefreshing() {
        renderHandler.removeCallbacks(refreshTimeoutRunnable)
        if (!::refreshButton.isInitialized) return
        refreshButton.isEnabled = true
        refreshButton.text = "تازه‌سازی"
    }

    private fun tabButton(label: String, action: () -> Unit): TextView = TextView(this).apply {
        text = label
        textSize = 11f
        gravity = Gravity.CENTER
        includeFontPadding = false
        background = rounded(android.graphics.Color.TRANSPARENT, 18)
        setTextColor(palette.textSecondary)
        isClickable = true
        isFocusable = true
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
        rippleColor = ColorStateList.valueOf(android.graphics.Color.TRANSPARENT)
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
