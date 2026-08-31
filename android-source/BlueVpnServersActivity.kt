package com.v2ray.ang.ui

import android.app.Activity
import android.content.Intent
import android.content.res.ColorStateList
import android.graphics.Color
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
import android.widget.HorizontalScrollView as QualityStrip
import android.widget.LinearLayout
import android.widget.TextView
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
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
import com.v2ray.ang.bluevpn.BlueVpnLocationListRow
import com.v2ray.ang.bluevpn.BlueVpnLocationRowDiff
import com.v2ray.ang.bluevpn.BlueVpnPalette
import com.v2ray.ang.bluevpn.BlueVpnPerformance
import com.v2ray.ang.bluevpn.BlueVpnPreferences
import com.v2ray.ang.bluevpn.BlueVpnRuntimeGate
import com.v2ray.ang.bluevpn.BlueVpnRefreshCoordinator
import com.v2ray.ang.bluevpn.BlueVpnLatencyPhase
import com.v2ray.ang.bluevpn.BlueVpnLatencyPolicy
import com.v2ray.ang.bluevpn.BlueVpnLatencySnapshot
import com.v2ray.ang.bluevpn.BlueVpnTheme
import com.v2ray.ang.bluevpn.BlueVpnTapsellManager
import com.v2ray.ang.bluevpn.BlueVpnUiGuard
import com.v2ray.ang.handler.MmkvManager
import com.v2ray.ang.viewmodel.MainViewModel
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.util.concurrent.Callable
import java.util.concurrent.Executors

class BlueVpnServersActivity : HelperBaseActivity() {

    companion object {
        const val EXTRA_LOCATION_CHANGED = "bluevpn.extra.LOCATION_CHANGED"
        const val EXTRA_LOCATION_KEY = "bluevpn.extra.LOCATION_KEY"
        const val EXTRA_LOCATION_TITLE = "bluevpn.extra.LOCATION_TITLE"

        private const val STATE_TAB = "bluevpn.locations.state.TAB"
        private const val STATE_QUALITY = "bluevpn.locations.state.QUALITY"
        private const val STATE_QUERY = "bluevpn.locations.state.QUERY"
        private const val STATE_EXPANDED = "bluevpn.locations.state.EXPANDED"
        private const val STATE_SCROLL_Y = "bluevpn.locations.state.SCROLL_Y"

        private const val TAG_SERVER_SURFACE = "bluevpn.locations.server.surface"
        private const val TAG_SERVER_RAIL = "bluevpn.locations.server.rail"
        private const val TAG_SERVER_TITLE = "bluevpn.locations.server.title"
        private const val TAG_SERVER_HEALTH = "bluevpn.locations.server.health"
        private const val TAG_SERVER_SIGNAL = "bluevpn.locations.server.signal"
        private const val TAG_SERVER_ACTION = "bluevpn.locations.server.action"
        private const val TAG_COUNTRY_SURFACE = "bluevpn.locations.country.surface"
        private const val TAG_COUNTRY_AVAILABILITY = "bluevpn.locations.country.availability"
        private const val TAG_COUNTRY_ACTION = "bluevpn.locations.country.action"
    }

    private enum class LocationTab { ALL, FAVORITES, RECENT }

    private enum class QualityFilter {
        ALL,
        FAST,
        USABLE,
        NEEDS_TEST,
    }

    private data class LocationGroup(
        val location: BlueVpnLocation,
        val servers: List<BlueVpnLocationUtil.Candidate>,
        val usableRoutes: Int,
        val healthScore: Int,
        val favorite: Boolean,
    )

    private inner class LocationsAdapter :
        ListAdapter<BlueVpnLocationListRow, LocationsAdapter.RowHolder>(
            BlueVpnLocationRowDiff,
        ) {

        init {
            setHasStableIds(true)
        }

        inner class RowHolder(
            val host: FrameLayout,
        ) : RecyclerView.ViewHolder(host)

        override fun getItemId(position: Int): Long =
            getItem(position).stableId.hashCode().toLong()

        override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): RowHolder {
            val host = FrameLayout(parent.context).apply {
                layoutParams = RecyclerView.LayoutParams(
                    ViewGroup.LayoutParams.MATCH_PARENT,
                    ViewGroup.LayoutParams.WRAP_CONTENT,
                )
            }
            return RowHolder(host)
        }

        override fun onBindViewHolder(
            holder: RowHolder,
            position: Int,
            payloads: MutableList<Any>,
        ) {
            val item = getItem(position)
            if (
                item is BlueVpnLocationListRow.Server &&
                payloads.contains(BlueVpnLocationRowDiff.PAYLOAD_SERVER_STATE) &&
                bindServerStatePayload(holder, item)
            ) {
                return
            }
            if (
                item is BlueVpnLocationListRow.Server &&
                payloads.contains(BlueVpnLocationRowDiff.PAYLOAD_LATENCY) &&
                bindLatencyPayload(holder, item)
            ) {
                return
            }
            if (
                item is BlueVpnLocationListRow.Country &&
                payloads.contains(BlueVpnLocationRowDiff.PAYLOAD_COUNTRY_ACTIVE) &&
                bindCountryActivePayload(holder, item)
            ) {
                return
            }
            super.onBindViewHolder(holder, position, payloads)
        }

        override fun onBindViewHolder(holder: RowHolder, position: Int) {
            val item = getItem(position)
            holder.host.removeAllViews()

            val content = when (item) {
                is BlueVpnLocationListRow.Country -> {
                    val group = renderedGroupsByKey[item.locationKey] ?: return
                    createLocationSection(
                        group = group,
                        active = item.active,
                        premium = BlueVpnEntitlement.resolveUi(this@BlueVpnServersActivity)
                            .manualSelectionAllowed,
                    )
                }

                is BlueVpnLocationListRow.Server -> {
                    val group = renderedGroupsByKey[item.locationKey] ?: return
                    val candidate = renderedCandidatesByGuid[item.guid] ?: return
                    createServerRow(
                        group = group,
                        candidate = candidate,
                        ordinal = item.ordinal,
                        premium = item.premium,
                    )
                }
            }

            val params = FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT,
            ).apply {
                topMargin = if (item is BlueVpnLocationListRow.Server) dp(5) else dp(5)
                bottomMargin = if (item is BlueVpnLocationListRow.Country) dp(2) else 0
                marginStart = if (item is BlueVpnLocationListRow.Server) dp(18) else 0
                marginEnd = if (item is BlueVpnLocationListRow.Server) dp(4) else 0
            }
            holder.host.addView(content, params)
        }

        private fun bindLatencyPayload(
            holder: RowHolder,
            item: BlueVpnLocationListRow.Server,
        ): Boolean = true

    private fun bindServerStatePayload(
            holder: RowHolder,
            item: BlueVpnLocationListRow.Server,
        ): Boolean {
            val surface = holder.host.findViewWithTag<MaterialCardView>(TAG_SERVER_SURFACE)
                ?: return false
            val rail = holder.host.findViewWithTag<View>(TAG_SERVER_RAIL)
                ?: return false
            val title = holder.host.findViewWithTag<TextView>(TAG_SERVER_TITLE)
                ?: return false
            val action = holder.host.findViewWithTag<TextView>(TAG_SERVER_ACTION)
                ?: return false

            surface.setCardBackgroundColor(
                if (item.active) {
                    if (palette.dark) 0xFF13213A.toInt() else 0xFFF1F5FF.toInt()
                } else palette.surface
            )
            surface.strokeColor = if (item.active) palette.accent else palette.stroke
            surface.strokeWidth = dp(1)
            rail.background = rounded(
                if (item.active) palette.accent else android.graphics.Color.TRANSPARENT,
                3,
            )
            rail.alpha = if (item.active) 1f else 0f
            title.setTypeface(
                title.typeface,
                if (item.active) Typeface.BOLD else Typeface.NORMAL,
            )

            action.text = when {
                item.automaticActive -> "خودکار"
                item.manualActive -> "دستی"
                !item.premium -> "🔒"
                item.active -> "فعال"
                else -> "انتخاب"
            }
            action.setTextColor(if (item.active) palette.accent else palette.textSecondary)
            action.background = rounded(
                if (item.active) {
                    if (palette.dark) 0xFF213454.toInt() else 0xFFE7EFFF.toInt()
                } else palette.surfaceStrong,
                11,
            )
            return true
        }

    private fun bindCountryActivePayload(
            holder: RowHolder,
            item: BlueVpnLocationListRow.Country,
        ): Boolean {
            val surface = holder.host.findViewWithTag<MaterialCardView>(TAG_COUNTRY_SURFACE)
                ?: return false
            val availability =
                holder.host.findViewWithTag<TextView>(TAG_COUNTRY_AVAILABILITY)
                    ?: return false
            val action = holder.host.findViewWithTag<TextView>(TAG_COUNTRY_ACTION)
                ?: return false

            surface.setCardBackgroundColor(
                if (item.active) {
                    if (palette.dark) 0xFF121B2D.toInt() else 0xFFF4F7FD.toInt()
                } else {
                    palette.surface
                }
            )
            surface.strokeColor = if (item.active) palette.accent else palette.stroke
            availability.setTextColor(if (item.active) palette.accent else palette.textMuted)
            action.text = when {
                !BlueVpnEntitlement.resolveUi(this@BlueVpnServersActivity).manualSelectionAllowed -> "🔒"
                item.automaticActive -> "AUTO"
                item.active -> "فعال"
                else -> "انتخاب"
            }
            action.setTextColor(if (item.active) palette.accent else palette.textSecondary)
            action.background = rounded(
                if (item.active) {
                    if (palette.dark) 0xFF213454.toInt() else 0xFFE7EFFF.toInt()
                } else {
                    palette.surfaceStrong
                },
                11,
            )
            return true
        }
    }

    private val mainViewModel: MainViewModel by viewModels()
    private lateinit var palette: BlueVpnPalette
    private var themeDarkAtCreate = true
    private lateinit var locationsRecyclerView: RecyclerView
    private lateinit var locationsAdapter: LocationsAdapter
    private var renderedGroupsByKey: Map<String, LocationGroup> = emptyMap()
    private var renderedCandidatesByGuid: Map<String, BlueVpnLocationUtil.Candidate> = emptyMap()
    private lateinit var emptyText: TextView
    private lateinit var nativeBannerHost: FrameLayout
    private lateinit var refreshButton: MaterialButton
    private lateinit var entitlementSubtitle: TextView
    private lateinit var automaticSubtitle: TextView
    private lateinit var allTabButton: TextView
    private lateinit var favoritesTabButton: TextView
    private lateinit var recentTabButton: TextView
    private lateinit var searchField: EditText
    private lateinit var qualitySummary: TextView
    private lateinit var qualityProgress: TextView
    private lateinit var qualityTestButton: TextView
    private lateinit var qualityProgressFill: View
    private lateinit var qualityProgressRemainder: View
    private lateinit var qualityExcellentStat: TextView
    private lateinit var qualityStableStat: TextView
    private lateinit var qualityRetryStat: TextView
    private val qualityFilterButtons = linkedMapOf<QualityFilter, TextView>()
    private var selectedTab = LocationTab.ALL
    private var selectedQualityFilter = QualityFilter.ALL
    private var query = ""
    private var queryText = ""
    private var restoredScrollY: Int? = null
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
    private val refreshCoordinator = BlueVpnRefreshCoordinator()
    private var activeRefreshToken = 0L
    private var healthSweepRequested = false
    private var healthSweepInProgress = false
    private var fallbackProbeInProgress = false
    private var fallbackProbeToken = 0L
    private var renderedPremiumMode: Boolean? = null
    private var lastRenderedStructureFingerprint: String = ""
    private val expandedLocationKeys = linkedSetOf<String>()
    private val latencyPrefs by lazy {
        getSharedPreferences("bluevpn_latency_samples", MODE_PRIVATE)
    }
    private val healthRefreshRunnable = Runnable {
        val snapshot = BlueVpnLocationUtil.cachedCandidates(this)
        updateQualityDashboard(snapshot)
        refreshVisibleHealthPresentation()
    }
    private val searchRunnable = Runnable { renderLocationsFromTop() }
    private val renderRunnable = Runnable {
        renderLocationsNow(renderGeneration)
    }
    private val refreshTimeoutRunnable = Runnable {
        val token = activeRefreshToken
        if (token > 0L && refreshCoordinator.timeout(token)) {
            activeRefreshToken = 0L
            stopRefreshingVisual()
            candidateLoadError = "بروزرسانی در مهلت مقرر کامل نشد؛ فهرست فعلی حفظ شد"
            updateEntitlementUi()
        }
    }
    private val candidateReloadRunnable = Runnable {
        val force = candidateReloadPending
        candidateReloadPending = false
        loadCandidates(force = force)
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        if (savedInstanceState != null) {
            selectedTab = runCatching {
                LocationTab.valueOf(savedInstanceState.getString(STATE_TAB).orEmpty())
            }.getOrDefault(LocationTab.ALL)
            selectedQualityFilter = runCatching {
                QualityFilter.valueOf(savedInstanceState.getString(STATE_QUALITY).orEmpty())
            }.getOrDefault(QualityFilter.ALL)
            queryText = savedInstanceState.getString(STATE_QUERY).orEmpty()
            query = BlueVpnLocationUtil.normalizeForSearch(queryText)
            expandedLocationKeys.clear()
            expandedLocationKeys.addAll(
                savedInstanceState.getStringArrayList(STATE_EXPANDED).orEmpty()
            )
            restoredScrollY = savedInstanceState
                .getInt(STATE_SCROLL_Y, 0)
                .coerceAtLeast(0)
            initialScrollRestored = true
        } else {
            restorePersistedLocationUiState()
        }

        window.setWindowAnimations(0)
        palette = BlueVpnTheme.palette(this)
        themeDarkAtCreate = palette.dark
        window.setBackgroundDrawable(android.graphics.drawable.ColorDrawable(palette.background))
        BlueVpnTheme.applySystemBars(this)
        setContentView(createScreen())
        if (::searchField.isInitialized && searchField.text.toString() != queryText) {
            searchField.setText(queryText)
            searchField.setSelection(searchField.text.length)
        }
        updateTabs()
        updateEntitlementUi()

        mainViewModel.startListenBroadcast()
        mainViewModel.updateListAction.observe(this) {
            // Noisy runtime/ping broadcasts must not dirty the whole locations cache.
            // Real subscription mutations invalidate it at the producer.
            scheduleCandidateReload(force = false, delayMs = 1_200L)
        }
        mainViewModel.updateTestResultAction.observe(this) { event ->
            // Latency is internal ranking evidence. Never repaint/reorder customer UI.
            recordPublishedLatencySamples(event)
            if (event == "batch-complete") healthSweepInProgress = false
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

    override fun onSaveInstanceState(outState: Bundle) {
        persistLocationUiState()
        outState.putString(STATE_TAB, selectedTab.name)
        outState.putString(STATE_QUALITY, selectedQualityFilter.name)
        outState.putString(STATE_QUERY, queryText)
        outState.putStringArrayList(STATE_EXPANDED, ArrayList(expandedLocationKeys))
        outState.putInt(
            STATE_SCROLL_Y,
            if (::locationsRecyclerView.isInitialized) {
                locationsRecyclerView.computeVerticalScrollOffset()
            } else restoredScrollY ?: 0,
        )
        super.onSaveInstanceState(outState)
    }

    override fun onPause() {
        persistLocationUiState()
        rememberLocationScroll()
        renderGeneration++
        searchHandler.removeCallbacks(searchRunnable)
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
        refreshToken: Long? = null,
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
        if (
            ::locationsAdapter.isInitialized &&
            locationsAdapter.itemCount == 0 &&
            BlueVpnLocationUtil.cachedCandidates(this).isEmpty()
        ) {
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
                }

                // Coalesce the importer's burst of broadcasts into at most one
                // trailing retry, and retry only while the pool is still empty.
                val retry = candidateReloadPending && loaded.isEmpty()
                candidateReloadPending = retry
                if (retry) {
                    renderHandler.removeCallbacks(candidateReloadRunnable)
                    renderHandler.postDelayed(candidateReloadRunnable, 500L)
                }

                refreshToken?.let { token ->
                    if (refreshCoordinator.finish(token)) {
                        activeRefreshToken = 0L
                        stopRefreshingVisual()
                    }
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
        markLatencyMeasurementStarted(candidates)

        // testAllRealPing() is the stock v2rayNG measurement pipeline already used
        // elsewhere in BlueVPN. It publishes results through updateTestResultAction.
        mainViewModel.testAllRealPing()

        // Fail-safe only: if upstream does not publish a completion event, allow a
        // later manual refresh to start another sweep and repaint unresolved rows
        // as timeout rather than leaving them permanently "در حال سنجش".
        renderHandler.postDelayed({
            healthSweepInProgress = false
            refreshVisibleHealthPresentation()
        }, BlueVpnLatencyPolicy.MEASUREMENT_TIMEOUT_MS)
    }

    private fun runManualQualitySweep() {
        val candidates = BlueVpnLocationUtil.cachedCandidates(this)
        if (candidates.isEmpty()) {
            Toast.makeText(this, "هنوز سروری برای تست دریافت نشده است", Toast.LENGTH_SHORT).show()
            return
        }
        if (mainViewModel.isRunning.value == true) {
            Toast.makeText(
                this,
                "برای تست گروهی، ابتدا اتصال فعلی را قطع کنید",
                Toast.LENGTH_LONG,
            ).show()
            return
        }
        if (healthSweepInProgress) return

        healthSweepRequested = true
        healthSweepInProgress = true
        markLatencyMeasurementStarted(candidates)
        updateQualityDashboard(candidates)
        refreshVisibleHealthPresentation()
        mainViewModel.testAllRealPing()
        renderHandler.postDelayed({
            if (!fallbackProbeInProgress && healthSweepInProgress) {
                val unresolved = BlueVpnLocationUtil.cachedCandidates(this)
                    .filter(::needsFallbackProbe)
                if (unresolved.isEmpty()) {
                    healthSweepInProgress = false
                    updateQualityDashboard(BlueVpnLocationUtil.cachedCandidates(this))
                    refreshVisibleHealthPresentation()
                } else {
                    startFallbackQualitySweep(unresolved)
                }
            }
        }, BlueVpnLatencyPolicy.MEASUREMENT_TIMEOUT_MS)
    }

    private fun updateQualityFilterButtons() {
        qualityFilterButtons.forEach { (filter, button) ->
            val active = selectedQualityFilter == filter
            val tone = when (filter) {
                QualityFilter.ALL -> palette.accent
                QualityFilter.FAST ->
                    if (palette.dark) 0xFF38C98F.toInt() else 0xFF14835B.toInt()
                QualityFilter.USABLE ->
                    if (palette.dark) 0xFF65A0FF.toInt() else 0xFF326FD1.toInt()
                QualityFilter.NEEDS_TEST ->
                    if (palette.dark) 0xFFF0B955.toInt() else 0xFF9B6900.toInt()
            }
            val idleFill = when (filter) {
                QualityFilter.ALL -> palette.surfaceStrong
                QualityFilter.FAST ->
                    if (palette.dark) 0xFF16352C.toInt() else 0xFFE8F7F0.toInt()
                QualityFilter.USABLE ->
                    if (palette.dark) 0xFF1C2D4A.toInt() else 0xFFEAF1FF.toInt()
                QualityFilter.NEEDS_TEST ->
                    if (palette.dark) 0xFF3A301D.toInt() else 0xFFFFF2D8.toInt()
            }
            button.background = rounded(
                if (active) tone else idleFill,
                12,
                if (active) tone else palette.stroke,
            )
            button.setTextColor(
                if (active) android.graphics.Color.WHITE else tone,
            )
        }
    }

    private fun candidateMatchesQuality(
        candidate: BlueVpnLocationUtil.Candidate,
    ): Boolean {
        val snapshot = latencySnapshot(candidate)
        val score = serverQualityScore(candidate)
        return when (selectedQualityFilter) {
            QualityFilter.ALL -> true
            QualityFilter.FAST ->
                snapshot.phase == BlueVpnLatencyPhase.FRESH && score >= 78
            QualityFilter.USABLE ->
                (
                    snapshot.phase == BlueVpnLatencyPhase.FRESH ||
                        snapshot.phase == BlueVpnLatencyPhase.STALE
                    ) &&
                    score >= 52
            QualityFilter.NEEDS_TEST ->
                snapshot.phase == BlueVpnLatencyPhase.UNKNOWN ||
                    snapshot.phase == BlueVpnLatencyPhase.MEASURING ||
                    snapshot.phase == BlueVpnLatencyPhase.TIMEOUT ||
                    snapshot.phase == BlueVpnLatencyPhase.OFFLINE ||
                    score < 52
        }
    }

    private fun updateQualityDashboard(
        candidates: List<BlueVpnLocationUtil.Candidate>,
    ) {
        if (!::qualitySummary.isInitialized) return
        val total = candidates.size
        val snapshots = candidates.map { candidate ->
            Triple(candidate, latencySnapshot(candidate), serverQualityScore(candidate))
        }
        val tested = snapshots.count { (_, value, _) ->
            value.phase != BlueVpnLatencyPhase.UNKNOWN &&
                value.phase != BlueVpnLatencyPhase.MEASURING
        }
        val excellent = snapshots.count { (_, value, score) ->
            value.phase == BlueVpnLatencyPhase.FRESH && score >= 86
        }
        val stable = snapshots.count { (_, value, score) ->
            (
                value.phase == BlueVpnLatencyPhase.FRESH ||
                    value.phase == BlueVpnLatencyPhase.STALE
                ) &&
                score in 52..85
        }
        val retry = snapshots.count { (_, value, score) ->
            value.phase == BlueVpnLatencyPhase.UNKNOWN ||
                value.phase == BlueVpnLatencyPhase.MEASURING ||
                value.phase == BlueVpnLatencyPhase.TIMEOUT ||
                value.phase == BlueVpnLatencyPhase.OFFLINE ||
                score < 52
        }
        val scored = snapshots.map { it.third }.filter { it > 0 }
        val averageScore = if (scored.isEmpty()) 0 else scored.average().toInt().coerceIn(0, 100)
        val bestLatency = snapshots
            .map { it.second.latencyMs }
            .filter { it > 0L }
            .minOrNull()
        val percent = if (total <= 0) 0 else ((tested * 100f) / total).toInt().coerceIn(0, 100)

        qualitySummary.text = when {
            total <= 0 -> "پس از دریافت سرورها، کیفیت اینجا نمایش داده می‌شود"
            healthSweepInProgress -> "$tested از $total سنجیده شد • تست زنده در حال اجرا"
            tested <= 0 -> "برای رتبه‌بندی واقعی سرورها، «تست همه» را اجرا کنید"
            bestLatency != null ->
                "$excellent عالی • $stable پایدار • $retry نیاز بررسی • بهترین ${bestLatency}ms"
            else -> "$excellent عالی • $stable پایدار • $retry نیاز بررسی • میانگین $averageScore"
        }
        qualityProgress.text = "$percent٪"
        if (::qualityExcellentStat.isInitialized) {
            qualityExcellentStat.text = "A+ عالی\n$excellent سرور"
            qualityStableStat.text = "B پایدار\n$stable سرور"
            qualityRetryStat.text = "نیاز بررسی\n$retry سرور"
        }
        if (::qualityProgressFill.isInitialized && ::qualityProgressRemainder.isInitialized) {
            (qualityProgressFill.layoutParams as LinearLayout.LayoutParams).weight = percent.toFloat()
            (qualityProgressRemainder.layoutParams as LinearLayout.LayoutParams).weight =
                (100 - percent).toFloat()
            qualityProgressFill.background = if (percent >= 100 && total > 0) {
                gradientRounded(
                    if (palette.dark) 0xFF35C98F.toInt() else 0xFF16845C.toInt(),
                    if (palette.dark) 0xFF46D7B0.toInt() else 0xFF2FAF83.toInt(),
                    3,
                )
            } else {
                gradientRounded(palette.accent, 0xFF725BFF.toInt(), 3)
            }
            qualityProgressFill.requestLayout()
            qualityProgressRemainder.requestLayout()
        }
        qualityTestButton.text = if (healthSweepInProgress) "در حال تست…" else "تست همه"
        qualityTestButton.isEnabled = !healthSweepInProgress && total > 0
        qualityTestButton.alpha = if (qualityTestButton.isEnabled) 1f else 0.72f
    }

    private fun createScreen(): View {
        palette = BlueVpnTheme.palette(this)
        val frame = FrameLayout(this).apply {
            layoutDirection = View.LAYOUT_DIRECTION_RTL
            setBackgroundColor(palette.background)
        }
        val root = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(16), dp(8), dp(16), dp(10))
            layoutDirection = View.LAYOUT_DIRECTION_RTL
        }
        frame.addView(root, FrameLayout.LayoutParams(-1, -1))

        root.addView(createHeader(), LinearLayout.LayoutParams(-1, dp(56)))
        root.addView(createTabs(), LinearLayout.LayoutParams(-1, dp(48)).apply { topMargin = dp(6) })
        root.addView(createSearchField(), LinearLayout.LayoutParams(-1, dp(48)).apply { topMargin = dp(8) })
        // Customer UI stays instant: quality diagnostics are background-only.
        root.addView(automaticServerCard(), LinearLayout.LayoutParams(-1, dp(62)).apply {
            topMargin = dp(8)
            bottomMargin = dp(8)
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

        locationsAdapter = LocationsAdapter()
        locationsRecyclerView = RecyclerView(this).apply {
            layoutManager = LinearLayoutManager(this@BlueVpnServersActivity)
            adapter = locationsAdapter
            overScrollMode = View.OVER_SCROLL_NEVER
            itemAnimator = null
            setHasFixedSize(false)
            setItemViewCacheSize(18)
            setPadding(0, 0, 0, dp(16))
            clipToPadding = false
        }
        root.addView(locationsRecyclerView, LinearLayout.LayoutParams(-1, 0, 1f))
        return frame
    }

    private fun attachFreeNativeBanner() {
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

        refreshButton = smallButton("↻").apply {
            textSize = 20f
            contentDescription = "بررسی دوباره سرورها"
            BlueVpnUiGuard.bind(this, intervalMs = 1_200L) {
                if (refreshCoordinator.isRefreshing()) return@bind
                val token = refreshCoordinator.begin()
                activeRefreshToken = token
                isEnabled = false
                text = "…"
                entitlementRepairAttempted = false
                refreshEntitlementState(force = true, refreshToken = token)
                renderHandler.removeCallbacks(refreshTimeoutRunnable)
                // Network sync owns up to 35s; UI deadline must be later, not racing it.
                renderHandler.postDelayed(refreshTimeoutRunnable, 42_000L)
            }
        }
        row.addView(refreshButton, LinearLayout.LayoutParams(dp(48), dp(48)))

        val titleBox = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER_VERTICAL or Gravity.END
            setPadding(dp(10), 0, dp(10), 0)
        }
        titleBox.addView(textView("مکان‌ها", 24f, palette.textPrimary, Gravity.END).apply {
            setTypeface(typeface, Typeface.BOLD)
        })
        entitlementSubtitle = textView("", 10f, palette.textMuted, Gravity.END).apply {
            setPadding(0, dp(2), 0, 0)
            maxLines = 1
            ellipsize = android.text.TextUtils.TruncateAt.END
        }
        titleBox.addView(entitlementSubtitle)
        row.addView(titleBox, LinearLayout.LayoutParams(0, -1, 1f))

        row.addView(smallButton("×").apply {
            textSize = 24f
            contentDescription = "بستن مکان‌ها"
            BlueVpnUiGuard.bind(this) {
                rememberLocationScroll()
                finish()
            }
        }, LinearLayout.LayoutParams(dp(48), dp(48)))
        return row
    }

    private fun createTabs(): View {
        val card = card(radius = 16, fill = palette.surfaceStrong, stroke = palette.stroke)
        val row = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER
            setPadding(dp(3), dp(3), dp(3), dp(3))
        }
        card.addView(row)
        allTabButton = tabButton("همه") {
            if (selectedTab != LocationTab.ALL) {
                selectedTab = LocationTab.ALL
                updateTabs()
                renderLocationsFromTop()
            }
        }
        favoritesTabButton = tabButton("علاقه‌مندی") {
            if (selectedTab != LocationTab.FAVORITES) {
                selectedTab = LocationTab.FAVORITES
                updateTabs()
                renderLocationsFromTop()
            }
        }
        recentTabButton = tabButton("اخیر") {
            if (selectedTab != LocationTab.RECENT) {
                selectedTab = LocationTab.RECENT
                updateTabs()
                renderLocationsFromTop()
            }
        }
        row.addView(allTabButton, LinearLayout.LayoutParams(0, -1, 1f))
        row.addView(favoritesTabButton, LinearLayout.LayoutParams(0, -1, 1f).apply { marginStart = dp(4); marginEnd = dp(4) })
        row.addView(recentTabButton, LinearLayout.LayoutParams(0, -1, 1f))
        return card
    }

    private fun createSearchField(): View = EditText(this).apply {
        searchField = this
        hint = "جست‌وجوی کشور یا سرور"
        textSize = 12.5f
        setTextColor(palette.textPrimary)
        setHintTextColor(palette.textMuted)
        isSingleLine = true
        gravity = Gravity.CENTER_VERTICAL or Gravity.END
        setPadding(dp(14), 0, dp(14), 0)
        background = rounded(palette.surface, 15, palette.stroke)
        addTextChangedListener(object : TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) = Unit
            override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {
                queryText = s?.toString().orEmpty()
                query = BlueVpnLocationUtil.normalizeForSearch(queryText)
                searchHandler.removeCallbacks(searchRunnable)
                searchHandler.postDelayed(
                    searchRunnable,
                    if (BlueVpnPerformance.isLowEnd(this@BlueVpnServersActivity)) 120L else 70L,
                )
            }
            override fun afterTextChanged(s: Editable?) = Unit
        })
    }

    private fun createQualityDashboard(): View {
        val dashboard = card(
            radius = 22,
            fill = if (palette.dark) 0xFF101A2D.toInt() else 0xFFF5F8FF.toInt(),
            stroke = if (palette.dark) 0xFF31486F.toInt() else 0xFFC9D9F2.toInt(),
        ).apply {
            cardElevation = dp(1).toFloat()
        }
        val column = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(12), dp(10), dp(12), dp(9))
        }
        dashboard.addView(column)

        val headline = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
        }
        val radar = textView("◉", 20f, Color.WHITE, Gravity.CENTER).apply {
            background = gradientRounded(palette.accent, 0xFF725BFF.toInt(), 15)
            contentDescription = "وضعیت کیفیت شبکه"
        }
        headline.addView(radar, LinearLayout.LayoutParams(dp(42), dp(42)).apply {
            marginEnd = dp(10)
        })

        val copy = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER_VERTICAL
        }
        copy.addView(textView("کیفیت زنده سرورها", 14f, palette.textPrimary, Gravity.END).apply {
            setTypeface(typeface, Typeface.BOLD)
        })
        qualitySummary = textView("در حال آماده‌سازی سنجش…", 9.5f, palette.textMuted, Gravity.END).apply {
            maxLines = 1
            ellipsize = android.text.TextUtils.TruncateAt.END
            setPadding(0, dp(2), 0, 0)
        }
        copy.addView(qualitySummary)
        headline.addView(copy, LinearLayout.LayoutParams(0, -1, 1f))

        qualityProgress = textView("۰٪", 9f, palette.accent, Gravity.CENTER).apply {
            setTypeface(Typeface.MONOSPACE, Typeface.BOLD)
            background = rounded(
                if (palette.dark) 0xFF22395F.toInt() else 0xFFE1EBFF.toInt(),
                11,
            )
        }
        headline.addView(qualityProgress, LinearLayout.LayoutParams(dp(46), dp(30)).apply {
            marginStart = dp(6)
        })

        qualityTestButton = textView("تست همه", 9.5f, Color.WHITE, Gravity.CENTER).apply {
            setTypeface(typeface, Typeface.BOLD)
            background = gradientRounded(palette.accent, 0xFF725BFF.toInt(), 12)
            isClickable = true
            isFocusable = true
            BlueVpnUiGuard.bind(this) { runManualQualitySweep() }
        }
        headline.addView(qualityTestButton, LinearLayout.LayoutParams(dp(72), dp(36)).apply {
            marginStart = dp(6)
        })
        column.addView(headline, LinearLayout.LayoutParams(-1, dp(44)))

        val progressTrack = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            background = rounded(
                if (palette.dark) 0xFF293650.toInt() else 0xFFDDE7F5.toInt(),
                3,
            )
            clipToOutline = true
        }
        qualityProgressFill = View(this).apply {
            background = gradientRounded(palette.accent, 0xFF725BFF.toInt(), 3)
        }
        qualityProgressRemainder = View(this).apply {
            background = android.graphics.drawable.ColorDrawable(Color.TRANSPARENT)
        }
        progressTrack.addView(
            qualityProgressFill,
            LinearLayout.LayoutParams(0, -1, 0f),
        )
        progressTrack.addView(
            qualityProgressRemainder,
            LinearLayout.LayoutParams(0, -1, 100f),
        )
        column.addView(progressTrack, LinearLayout.LayoutParams(-1, dp(5)).apply {
            topMargin = dp(4)
        })

        val scroll = QualityStrip(this).apply {
            isHorizontalScrollBarEnabled = false
            overScrollMode = View.OVER_SCROLL_NEVER
            layoutDirection = View.LAYOUT_DIRECTION_RTL
        }
        val filters = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            setPadding(0, dp(5), 0, 0)
        }
        listOf(
            QualityFilter.ALL to "همه",
            QualityFilter.FAST to "A / سریع",
            QualityFilter.USABLE to "B / پایدار",
            QualityFilter.NEEDS_TEST to "ضعیف / تست",
        ).forEach { (filter, label) ->
            val chip = textView(label, 9.3f, palette.textSecondary, Gravity.CENTER).apply {
                setTypeface(typeface, Typeface.BOLD)
                isClickable = true
                isFocusable = true
                contentDescription = "فیلتر کیفیت $label"
                BlueVpnUiGuard.bind(this) {
                    if (selectedQualityFilter != filter) {
                        selectedQualityFilter = filter
                        updateQualityFilterButtons()
                        renderLocationsFromTop()
                    }
                }
            }
            qualityFilterButtons[filter] = chip
            val width = when (filter) {
                QualityFilter.ALL -> 68
                QualityFilter.FAST -> 84
                QualityFilter.USABLE -> 92
                QualityFilter.NEEDS_TEST -> 96
            }
            filters.addView(chip, LinearLayout.LayoutParams(dp(width), dp(32)).apply {
                marginStart = dp(4)
            })
        }
        scroll.addView(filters)
        column.addView(scroll, LinearLayout.LayoutParams(-1, dp(42)))
        updateQualityFilterButtons()
        return dashboard
    }

    private fun qualityMetric(
        label: String,
        tone: Int,
        fill: Int,
    ): TextView = textView("$label\n۰ سرور", 9f, tone, Gravity.CENTER).apply {
        setTypeface(typeface, Typeface.BOLD)
        setLineSpacing(0f, 0.92f)
        maxLines = 2
        background = rounded(fill, 12, tone)
    }

    private fun automaticServerCard(): View {
        val active = BlueVpnPreferences.smartBalance(this@BlueVpnServersActivity)
        val card = card(
            radius = 18,
            fill = if (active) {
                if (palette.dark) 0xFF121D33.toInt() else 0xFFF0F5FF.toInt()
            } else palette.surface,
            stroke = if (active) palette.accent else palette.stroke,
        ).apply {
            isClickable = true
            isFocusable = true
            strokeWidth = dp(if (active) 1 else 1)
            cardElevation = dp(1).toFloat()
            BlueVpnUiGuard.bind(this) { selectAutomatic() }
        }
        val row = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            setPadding(dp(12), dp(8), dp(12), dp(8))
        }
        card.addView(row)

        val icon = textView("✦", 18f, palette.accent, Gravity.CENTER).apply {
            background = rounded(
                if (palette.dark) 0xFF213357.toInt() else 0xFFE5EEFF.toInt(),
                14,
            )
        }
        row.addView(icon, LinearLayout.LayoutParams(dp(42), dp(42)).apply { marginEnd = dp(10) })

        val box = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER_VERTICAL
        }
        box.addView(textView("انتخاب هوشمند", 15f, palette.textPrimary, Gravity.END).apply {
            setTypeface(typeface, Typeface.BOLD)
        })
        automaticSubtitle = textView("", 10f, palette.textMuted, Gravity.END).apply {
            setPadding(0, dp(3), 0, 0)
            maxLines = 1
            ellipsize = android.text.TextUtils.TruncateAt.END
        }
        box.addView(automaticSubtitle)
        row.addView(box, LinearLayout.LayoutParams(0, -1, 1f))

        row.addView(
            textView(if (active) "فعال" else "انتخاب", 10f, if (active) palette.accent else palette.textSecondary, Gravity.CENTER).apply {
                setTypeface(typeface, Typeface.BOLD)
                background = rounded(
                    if (active) {
                        if (palette.dark) 0xFF203252.toInt() else 0xFFE6EEFF.toInt()
                    } else palette.surfaceStrong,
                    12,
                )
            },
            LinearLayout.LayoutParams(dp(58), dp(34)),
        )
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
    private fun refreshEntitlementState(force: Boolean, refreshToken: Long? = null) {
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
                    refreshToken?.let { refreshCoordinator.beginPoolReload(it) }
                    loadCandidates(force = true, refreshToken = refreshToken)
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

                candidateLoadError = result.exceptionOrNull()?.let { error ->
                    when {
                        error.message?.contains("بروزرسانی حساب از سرور کامل نشد") == true ->
                            "بروزرسانی حساب کامل نشد؛ فهرست فعلی حفظ شد"
                        else -> "دریافت سرورها ناموفق بود؛ دوباره تلاش کنید"
                    }
                }.orEmpty()
                updateEntitlementUi()

                if (result.isSuccess) {
                    BlueVpnLocationUtil.invalidateCache()
                    refreshToken?.let { refreshCoordinator.beginPoolReload(it) }
                    loadCandidates(force = true, refreshToken = refreshToken)
                } else {
                    // A control-plane timeout must not destroy or re-enumerate the
                    // currently usable local pool. Keep the visible list intact.
                    refreshToken?.let { token ->
                        if (refreshCoordinator.finish(token)) {
                            activeRefreshToken = 0L
                            stopRefreshingVisual()
                        }
                    }
                    refreshVisibleHealthPresentation()
                }

                if (accountSyncPending) {
                    accountSyncPending = false
                    // Coalesce repeated taps into one trailing manual refresh.
                    val nextToken = refreshCoordinator.begin()
                    activeRefreshToken = nextToken
                    refreshEntitlementState(force = true, refreshToken = nextToken)
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
            append(selectedQualityFilter.name).append('|')
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

    private fun renderLocationsFromTop() {
        if (::locationsRecyclerView.isInitialized) {
            locationsRecyclerView.scrollToPosition(0)
        }
        // The next render should preserve zero rather than resurrecting a saved
        // deep offset from the unfiltered master list.
        initialScrollRestored = true
        renderLocations()
    }

    private fun renderLocations() {
        if (!::locationsAdapter.isInitialized || isFinishing || isDestroyed) return
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
            !::locationsAdapter.isInitialized ||
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
            if (candidateLoadInProgress && locationsAdapter.itemCount > 0) {
                emptyText.visibility = View.GONE
                return
            }
            locationsAdapter.submitList(emptyList())
            renderedGroupsByKey = emptyMap()
            renderedCandidatesByGuid = emptyMap()
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
        val preservedScrollY = if (::locationsRecyclerView.isInitialized) {
            locationsRecyclerView.computeVerticalScrollOffset()
        } else {
            0
        }
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

        val candidateGroups = candidates
            .groupBy { it.location.key }
            .mapNotNull { (_, servers) ->
                val location = servers.firstOrNull()?.location ?: return@mapNotNull null
                // A config containing only an IP or a generic remark has no
                // trustworthy country before its first successful exit trace.
                // Keep those routes available to automatic selection, but never
                // present "unknown" as if it were a manually selectable country.
                if (location.key == "unknown") return@mapNotNull null
                val visibleServers = servers
                if (visibleServers.isEmpty()) return@mapNotNull null
                val usable = visibleServers.count { !BlueVpnPreferences.isSessionInactive(this, it.guid) }
                LocationGroup(
                    location = location,
                    servers = visibleServers,
                    usableRoutes = usable,
                    healthScore = 0,
                    favorite = BlueVpnExperience.isFavorite(this, location.key),
                )
            }

        // Direct server search needs stable ordinals, but computing them repeatedly
        // during filter + row construction doubles SharedPreferences work. Build the
        // search matches once per country and reuse them for the final flat list.
        val searchRowsByLocation = if (query.isBlank()) {
            emptyMap()
        } else {
            candidateGroups.associate { group ->
                group.location.key to stableServerRows(group.location, group.servers)
                    .filter { (candidate, ordinal) ->
                        serverMatchesQuery(group, candidate, ordinal)
                    }
            }
        }

        val groups = candidateGroups
            .filter { group ->
                val locationSearchable = BlueVpnLocationUtil.normalizeForSearch(
                    group.location.title + " " +
                        group.location.key + " " +
                        BlueVpnLocationUtil.publicSearchAliases(group.location.key),
                )
                val matchesQuery =
                    query.isBlank() ||
                        locationSearchable.contains(query) ||
                        searchRowsByLocation[group.location.key].orEmpty().isNotEmpty()
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

        emptyText.text = when {
            selectedQualityFilter == QualityFilter.FAST ->
                "سرور سریعی در نتایج فعلی پیدا نشد؛ «تست همه» را بزنید"
            selectedQualityFilter == QualityFilter.USABLE ->
                "سرور قابل‌استفاده‌ای در نتایج سنجش پیدا نشد"
            selectedQualityFilter == QualityFilter.NEEDS_TEST ->
                "همه سرورهای این بخش سنجیده شده‌اند"
            selectedTab == LocationTab.FAVORITES ->
                "هنوز مکانی را به علاقه‌مندی اضافه نکرده‌اید"
            selectedTab == LocationTab.RECENT ->
                "هنوز اتصال موفقی ثبت نشده است"
            else -> "مکانی پیدا نشد"
        }
        emptyText.visibility = if (groups.isEmpty()) View.VISIBLE else View.GONE

        renderedGroupsByKey = groups.associateBy { it.location.key }
        renderedCandidatesByGuid = candidates.associateBy { it.guid }

        val connected = BlueVpnRuntimeGate.connectionActive(this)
        val automaticMode = BlueVpnPreferences.smartBalance(this)
        val manualGuid = BlueVpnPreferences.manualServerGuid(this)
        val selectionMode = BlueVpnPreferences.selectionMode(this)
        val rows = buildList {
            groups.forEach { group ->
                val groupActive =
                    activeLocationKey.isNotBlank() &&
                        group.location.key == activeLocationKey
                val locationSearchable = BlueVpnLocationUtil.normalizeForSearch(
                    group.location.title + " " +
                        group.location.key + " " +
                        BlueVpnLocationUtil.publicSearchAliases(group.location.key),
                )
                val locationMatchesQuery =
                    query.isNotBlank() && locationSearchable.contains(query)
                val userExpanded = group.location.key in expandedLocationKeys
                val searchMatches = searchRowsByLocation[group.location.key].orEmpty()
                val expandedBySearch =
                    query.isNotBlank() &&
                        !locationMatchesQuery &&
                        searchMatches.isNotEmpty()
                val expanded = userExpanded || expandedBySearch
                val matchingServerRows = when {
                    !expanded -> emptyList()
                    query.isBlank() || locationMatchesQuery ->
                        stableServerRows(group.location, group.servers)
                    else -> searchMatches
                }
                add(
                    BlueVpnLocationListRow.Country(
                        locationKey = group.location.key,
                        title = group.location.title,
                        flag = group.location.flag,
                        serverCount = group.servers.size,
                        expanded = expanded,
                        favorite = group.favorite,
                        active = groupActive,
                        automaticActive = groupActive && automaticMode && connected,
                        availability = group.servers.size.toString() + " سرور",
                    )
                )
                if (expanded) {
                    matchingServerRows.forEach { (candidate, ordinal) ->
                        val automaticActive =
                            automaticMode && connected && candidate.guid == selected
                        val manualActive =
                            selectionMode == BlueVpnSelectionMode.MANUAL_SERVER &&
                                manualGuid == candidate.guid
                        val serverActive =
                            automaticActive || manualActive ||
                                (connected && candidate.guid == selected)
                        add(
                            BlueVpnLocationListRow.Server(
                                guid = candidate.guid,
                                locationKey = group.location.key,
                                title = group.location.title,
                                ordinal = ordinal,
                                active = serverActive,
                                automaticActive = automaticActive,
                                manualActive = manualActive,
                                premium = manualSelectionAllowed,
                                latencyPhase = BlueVpnLatencyPhase.UNKNOWN,
                                latencyMs = 0L,
                                signalLevel = 0,
                                qualityScore = 0,
                            )
                        )
                    }
                }
            }
        }

        lastRenderedStructureFingerprint = locationStructureFingerprint(candidates)
        locationsAdapter.submitList(rows) {
            if (!::locationsRecyclerView.isInitialized) return@submitList
            val targetScrollY = restoredScrollY?.also {
                restoredScrollY = null
            } ?: if (!initialScrollRestored) {
                initialScrollRestored = true
                getSharedPreferences("bluevpn_locations_ui", MODE_PRIVATE)
                    .getInt(scrollPreferenceKey(), 0)
            } else {
                preservedScrollY
            }
            if (targetScrollY > 0) {
                locationsRecyclerView.post {
                    val currentScrollY =
                        locationsRecyclerView.computeVerticalScrollOffset()
                    val delta = targetScrollY - currentScrollY
                    if (delta != 0) {
                        locationsRecyclerView.scrollBy(0, delta)
                    }
                }
            }
        }
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
        // No-op by design: background quality changes never churn the RecyclerView.
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

    private fun serverMatchesQuery(
        group: LocationGroup,
        candidate: BlueVpnLocationUtil.Candidate,
        ordinal: Int,
    ): Boolean {
        if (query.isBlank()) return true
        val searchable = BlueVpnLocationUtil.normalizeForSearch(
            listOf(
                group.location.title + " " + ordinal,
                group.location.key + " " + ordinal,
                BlueVpnLocationUtil.publicSearchAliases(group.location.key) + " " + ordinal,
            ).joinToString(" "),
        )
        return searchable.contains(query)
    }

    private fun markLatencyMeasurementStarted(
        candidates: List<BlueVpnLocationUtil.Candidate>,
    ) {
        val now = System.currentTimeMillis()
        val editor = latencyPrefs.edit()
        candidates.forEach { candidate ->
            editor.putLong("started:" + candidate.guid, now)
        }
        editor.apply()
    }

    private fun recordPublishedLatencySamples(event: String?) {
        val now = System.currentTimeMillis()
        val candidates = BlueVpnLocationUtil.cachedCandidates(this)
        val editor = latencyPrefs.edit()

        val targetGuids = when {
            event == "batch-complete" -> candidates.map { it.guid }.toSet()
            event?.startsWith("current:") == true ->
                setOf(event.substringAfter("current:").substringBefore(":"))
                    .filter { it.isNotBlank() }
                    .toSet()
            else -> emptySet()
        }
        if (targetGuids.isEmpty()) return

        candidates.forEach { candidate ->
            if (candidate.guid !in targetGuids) return@forEach
            val liveDelay = MmkvManager.decodeServerAffiliationInfo(candidate.guid)
                ?.testDelayMillis ?: candidate.delay
            if (liveDelay > 0L) {
                editor.putLong("measured:" + candidate.guid, now)
            }
            if (event == "batch-complete" || liveDelay > 0L) {
                editor.remove("started:" + candidate.guid)
            }
        }
        editor.apply()
    }

    private fun latencySnapshot(
        candidate: BlueVpnLocationUtil.Candidate,
    ): BlueVpnLatencySnapshot {
        val now = System.currentTimeMillis()
        val liveDelay = MmkvManager.decodeServerAffiliationInfo(candidate.guid)
            ?.testDelayMillis ?: candidate.delay
        val fallbackAt = latencyPrefs.getLong("fallback_at:" + candidate.guid, 0L)
        val fallbackFresh = fallbackAt > 0L && now - fallbackAt in 0L..5 * 60_000L
        val fallbackDelay = if (fallbackFresh) {
            latencyPrefs.getLong("fallback:" + candidate.guid, 0L)
        } else {
            0L
        }
        val effectiveDelay = if (liveDelay > 0L) liveDelay else fallbackDelay
        val measuredAt = if (liveDelay > 0L) {
            latencyPrefs.getLong("measured:" + candidate.guid, 0L)
        } else {
            fallbackAt
        }
        return BlueVpnLatencyPolicy.resolve(
            latencyMs = effectiveDelay,
            measuredAtMs = measuredAt,
            nowMs = now,
            measuringSinceMs = latencyPrefs.getLong("started:" + candidate.guid, 0L),
            inactive = BlueVpnPreferences.isSessionInactive(this, candidate.guid),
        )
    }

    private fun usesFallbackProbe(candidate: BlueVpnLocationUtil.Candidate): Boolean {
        val liveDelay = MmkvManager.decodeServerAffiliationInfo(candidate.guid)
            ?.testDelayMillis ?: candidate.delay
        if (liveDelay > 0L) return false
        val at = latencyPrefs.getLong("fallback_at:" + candidate.guid, 0L)
        return at > 0L &&
            System.currentTimeMillis() - at in 0L..5 * 60_000L &&
            latencyPrefs.getLong("fallback:" + candidate.guid, 0L) > 0L
    }

    private fun needsFallbackProbe(candidate: BlueVpnLocationUtil.Candidate): Boolean {
        val liveDelay = MmkvManager.decodeServerAffiliationInfo(candidate.guid)
            ?.testDelayMillis ?: candidate.delay
        return liveDelay <= 0L && !usesFallbackProbe(candidate)
    }

    private fun startFallbackQualitySweep(
        candidates: List<BlueVpnLocationUtil.Candidate>,
    ) {
        if (candidates.isEmpty() || fallbackProbeInProgress) return
        fallbackProbeInProgress = true
        val token = ++fallbackProbeToken
        if (::qualitySummary.isInitialized) {
            qualitySummary.text = "تست اصلی کامل نشد • بررسی دسترسی شبکه…"
        }

        lifecycleScope.launch(Dispatchers.IO) {
            val workers = Executors.newFixedThreadPool(
                if (BlueVpnPerformance.isLowEnd(this@BlueVpnServersActivity)) 3 else 5
            )
            val results = try {
                workers.invokeAll(
                    candidates.map { candidate ->
                        Callable {
                            candidate.guid to (
                                BlueVpnLocationUtil.advisoryTcpLatency(
                                    candidate,
                                    timeoutMs = 360,
                                ) ?: 0L
                            )
                        }
                    }
                ).mapNotNull { future ->
                    runCatching { future.get() }.getOrNull()
                }
            } finally {
                workers.shutdownNow()
            }

            val now = System.currentTimeMillis()
            val editor = latencyPrefs.edit()
            results.forEach { (guid, latencyMs) ->
                if (latencyMs > 0L) {
                    editor.putLong("fallback:" + guid, latencyMs)
                    editor.putLong("fallback_at:" + guid, now)
                } else {
                    editor.remove("fallback:" + guid)
                    editor.remove("fallback_at:" + guid)
                }
            }
            editor.apply()

            withContext(Dispatchers.Main) {
                if (token != fallbackProbeToken || isFinishing || isDestroyed) {
                    return@withContext
                }
                fallbackProbeInProgress = false
                healthSweepInProgress = false
                val snapshot = BlueVpnLocationUtil.cachedCandidates(this@BlueVpnServersActivity)
                updateQualityDashboard(snapshot)
                refreshVisibleHealthPresentation()
            }
        }
    }

    private fun signalLevel(candidate: BlueVpnLocationUtil.Candidate): Int {
        val latency = latencySnapshot(candidate)
        if (latency.phase == BlueVpnLatencyPhase.OFFLINE) return 0
        val delay = latency.latencyMs
        return when {
            delay in 1..80 -> 4
            delay in 81..160 -> 3
            delay in 161..280 -> 2
            delay > 280 -> 1
            latency.phase == BlueVpnLatencyPhase.MEASURING -> 0
            latency.phase == BlueVpnLatencyPhase.TIMEOUT -> 0
            else -> when (BlueVpnLocationUtil.healthScore(this, candidate)) {
                in 82..100 -> 4
                in 68..81 -> 3
                in 45..67 -> 2
                in 1..44 -> 1
                else -> 0
            }
        }
    }

    private fun serverQualityScore(candidate: BlueVpnLocationUtil.Candidate): Int {
        val snapshot = latencySnapshot(candidate)
        if (
            snapshot.phase == BlueVpnLatencyPhase.OFFLINE ||
            snapshot.phase == BlueVpnLatencyPhase.TIMEOUT
        ) return 0

        val health = BlueVpnLocationUtil.healthScore(this, candidate).coerceIn(0, 100)
        if (
            snapshot.phase == BlueVpnLatencyPhase.UNKNOWN ||
            snapshot.phase == BlueVpnLatencyPhase.MEASURING
        ) {
            return if (health > 0) (health * 35 / 100).coerceIn(0, 45) else 0
        }

        val latencyScore = when (snapshot.latencyMs) {
            in 1..65 -> 100
            in 66..95 -> 94
            in 96..130 -> 87
            in 131..180 -> 78
            in 181..240 -> 68
            in 241..320 -> 56
            in 321..450 -> 42
            in 451..Long.MAX_VALUE -> 28
            else -> 0
        }
        val healthScore = if (health > 0) health else latencyScore
        val freshnessPenalty =
            if (snapshot.phase == BlueVpnLatencyPhase.STALE) 12 else 0

        return (
            ((latencyScore * 72) + (healthScore * 28)) / 100 -
                freshnessPenalty
            ).coerceIn(0, 100)
    }

    private fun qualityBadge(
        phase: BlueVpnLatencyPhase,
        level: Int,
        score: Int,
    ): String = when {
        phase == BlueVpnLatencyPhase.MEASURING -> "◌ LIVE"
        phase == BlueVpnLatencyPhase.TIMEOUT -> "× پاسخ نداد"
        phase == BlueVpnLatencyPhase.OFFLINE -> "× آفلاین"
        phase == BlueVpnLatencyPhase.UNKNOWN && score <= 0 -> "○ تست"
        score >= 90 -> "A+ • $score"
        score >= 78 -> "A • $score"
        score >= 64 -> "B • $score"
        score >= 50 -> "C • $score"
        level > 0 -> "D • $score"
        else -> "○ تست"
    }

    private fun signalBars(level: Int): String =
        when (level) {
            4 -> "▂▄▆█"
            3 -> "▂▄▆"
            2 -> "▂▄"
            1 -> "▂"
            else -> "—"
        }

    private fun signalBars(candidate: BlueVpnLocationUtil.Candidate): String =
        signalBars(signalLevel(candidate))

    private fun signalQuality(level: Int): String =
        when (level) {
            4 -> "عالی"
            3 -> "خوب"
            2 -> "متوسط"
            1 -> "ضعیف"
            else -> "در حال سنجش"
        }

    private fun signalQuality(candidate: BlueVpnLocationUtil.Candidate): String =
        signalQuality(signalLevel(candidate))

    private fun qualityPillColor(level: Int): Int =
        when (level) {
            4 -> if (palette.dark) 0xFF123F34.toInt() else 0xFFD9F8EB.toInt()
            3 -> if (palette.dark) 0xFF17382F.toInt() else 0xFFE3F7EE.toInt()
            2 -> if (palette.dark) 0xFF463719.toInt() else 0xFFFFF0C9.toInt()
            1 -> if (palette.dark) 0xFF49232C.toInt() else 0xFFFFE2E7.toInt()
            else -> if (palette.dark) 0xFF25334E.toInt() else 0xFFE8EEF8.toInt()
        }

    private fun qualitySurfaceColor(level: Int, active: Boolean): Int =
        when {
            active -> if (palette.dark) 0xFF172B4D.toInt() else 0xFFEAF2FF.toInt()
            level >= 3 -> if (palette.dark) 0xFF0D2420.toInt() else 0xFFF0FBF7.toInt()
            level == 2 -> if (palette.dark) 0xFF282116.toInt() else 0xFFFFF8E7.toInt()
            level == 1 -> if (palette.dark) 0xFF2A171E.toInt() else 0xFFFFF0F2.toInt()
            else -> if (palette.dark) 0xFF121722.toInt() else 0xFFF4F7FC.toInt()
        }

    private fun qualityStrokeColor(level: Int, active: Boolean): Int =
        when {
            active -> palette.accent
            level >= 3 -> if (palette.dark) 0xFF347A63.toInt() else 0xFF82D9B0.toInt()
            level == 2 -> if (palette.dark) 0xFF7A5D28.toInt() else 0xFFEFC873.toInt()
            level == 1 -> if (palette.dark) 0xFF7F3947.toInt() else 0xFFE8A0AD.toInt()
            else -> if (palette.dark) 0xFF32405D.toInt() else 0xFFC8D4E7.toInt()
        }

    private fun countryQualitySummary(
        location: BlueVpnLocation,
        servers: List<BlueVpnLocationUtil.Candidate>,
    ): String {
        val snapshots = servers.map { candidate ->
            Triple(candidate, latencySnapshot(candidate), serverQualityScore(candidate))
        }
        val tested = snapshots.count { (_, snapshot, _) ->
            snapshot.phase != BlueVpnLatencyPhase.UNKNOWN &&
                snapshot.phase != BlueVpnLatencyPhase.MEASURING
        }
        val excellent = snapshots.count { (_, snapshot, score) ->
            snapshot.phase == BlueVpnLatencyPhase.FRESH && score >= 86
        }
        val stable = snapshots.count { (_, snapshot, score) ->
            (
                snapshot.phase == BlueVpnLatencyPhase.FRESH ||
                    snapshot.phase == BlueVpnLatencyPhase.STALE
                ) &&
                score >= 52
        }
        val retry = snapshots.count { (_, snapshot, score) ->
            snapshot.phase == BlueVpnLatencyPhase.TIMEOUT ||
                snapshot.phase == BlueVpnLatencyPhase.OFFLINE ||
                score in 1..51
        }
        val bestLatency = snapshots
            .map { (_, snapshot, _) -> snapshot.latencyMs }
            .filter { it > 0L }
            .minOrNull()
        val bestScore = snapshots.maxOfOrNull { it.third } ?: 0

        return when {
            servers.isEmpty() -> "بدون سرور"
            healthSweepInProgress -> "$tested/${servers.size} سنجش زنده"
            excellent > 0 && bestLatency != null ->
                "A+ $excellent عالی • ${bestLatency}ms • $bestScore/100"
            stable > 0 -> "$stable پایدار • امتیاز برتر $bestScore/100"
            retry > 0 -> "$retry نیاز بررسی • تست دوباره"
            else -> availabilityLabel(location, servers)
        }
    }

    private fun countryFlagBadge(
        flag: String,
        sizeDp: Int,
        textSp: Float,
    ): MaterialCardView {
        val accent = countryBadgeAccent(flag)
        return card(
            radius = sizeDp / 2,
            fill = if (palette.dark) 0xFF142033.toInt() else Color.WHITE,
            stroke = accent,
        ).apply {
            strokeWidth = dp(if (sizeDp >= 50) 2 else 1)
            cardElevation = 0f
            clipToOutline = true
            addView(
                textView(flag, textSp + 1.5f, palette.textPrimary, Gravity.CENTER),
                FrameLayout.LayoutParams(-1, -1),
            )
            addView(
                View(this@BlueVpnServersActivity).apply {
                    background = rounded(accent, 2)
                    alpha = if (palette.dark) 0.92f else 0.82f
                },
                FrameLayout.LayoutParams(-1, dp(3), Gravity.BOTTOM),
            )
        }
    }

    private fun countryBadgeAccent(flag: String): Int {
        val index = (flag.hashCode() and Int.MAX_VALUE) % 6
        return intArrayOf(
            0xFF4B7DFF.toInt(),
            0xFF27C49A.toInt(),
            0xFF8A67F5.toInt(),
            0xFFF0A84A.toInt(),
            0xFF39BFD1.toInt(),
            0xFFE45F7C.toInt(),
        )[index]
    }

    private fun serverHealthColor(level: Int, active: Boolean): Int =
        when {
            level >= 3 -> if (palette.dark) 0xFF5CD6A6.toInt() else 0xFF13835A.toInt()
            level == 2 -> if (palette.dark) 0xFFF3BF59.toInt() else 0xFF9B6A00.toInt()
            level == 1 -> if (palette.dark) 0xFFFF7676.toInt() else 0xFFC83F3F.toInt()
            else -> palette.textMuted
        }

    private fun serverHealthLabel(item: BlueVpnLocationListRow.Server): String {
        val latencySuffix = if (item.latencyMs > 0L) {
            " • ${item.latencyMs} ms • ${item.qualityScore}/100"
        } else {
            ""
        }
        return when {
            item.automaticActive -> "فعال • خودکار" + latencySuffix
            item.active -> "فعال • دستی" + latencySuffix
            item.latencyPhase == BlueVpnLatencyPhase.OFFLINE -> "موقتاً نامناسب"
            item.latencyPhase == BlueVpnLatencyPhase.MEASURING -> "در حال سنجش" + " زنده"
            item.latencyPhase == BlueVpnLatencyPhase.TIMEOUT -> "تست کامل نشد • دوباره امتحان کنید"
            item.latencyPhase == BlueVpnLatencyPhase.FRESH ->
                item.latencyMs.toString() + " ms • امتیاز " + item.qualityScore
            item.latencyPhase == BlueVpnLatencyPhase.STALE ->
                item.latencyMs.toString() + " ms • قدیمی" + " • امتیاز " + item.qualityScore
            item.latencyMs > 0L ->
                item.latencyMs.toString() + " ms • ذخیره‌شده"
            else -> "هنوز سنجیده نشده"
        }
    }

    private fun serverHealthLabel(
        candidate: BlueVpnLocationUtil.Candidate,
        active: Boolean,
        automaticActive: Boolean,
    ): String {
        val latency = latencySnapshot(candidate)
        val score = serverQualityScore(candidate)
        val fallback = usesFallbackProbe(candidate)
        val latencySuffix = if (latency.latencyMs > 0L) {
            " • ${latency.latencyMs} ms • $score/100"
        } else {
            ""
        }
        return when {
            automaticActive -> "فعال • خودکار" + latencySuffix
            active -> "فعال • دستی" + latencySuffix
            latency.phase == BlueVpnLatencyPhase.OFFLINE -> "موقتاً نامناسب"
            latency.phase == BlueVpnLatencyPhase.MEASURING -> "در حال سنجش" + " زنده"
            latency.phase == BlueVpnLatencyPhase.TIMEOUT -> "تست کامل نشد • دوباره امتحان کنید"
            latency.phase == BlueVpnLatencyPhase.FRESH && fallback ->
                latency.latencyMs.toString() + " ms • پیش‌تست شبکه • امتیاز " + score
            latency.phase == BlueVpnLatencyPhase.FRESH ->
                latency.latencyMs.toString() + " ms • " + signalQuality(candidate) + " • امتیاز " + score
            latency.phase == BlueVpnLatencyPhase.STALE ->
                latency.latencyMs.toString() + " ms • قدیمی" + " • امتیاز " + score
            latency.latencyMs > 0L ->
                latency.latencyMs.toString() + " ms • ذخیره‌شده"
            else -> "هنوز سنجیده نشده"
        }
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

        val surface = card(
            radius = 16,
            fill = if (active) {
                if (palette.dark) 0xFF13213A.toInt() else 0xFFF1F5FF.toInt()
            } else palette.surface,
            stroke = if (active) palette.accent else palette.stroke,
        ).apply {
            tag = TAG_SERVER_SURFACE
            strokeWidth = dp(1)
            cardElevation = 0f
            isClickable = true
            isFocusable = true
            contentDescription = group.location.title + " سرور " + ordinal
            BlueVpnUiGuard.bind(this) {
                if (!premium) openSubscriptionForPremium()
                else selectServer(group, candidate, ordinal)
            }
        }

        val line = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            setPadding(dp(10), dp(6), dp(10), dp(6))
        }
        surface.addView(line)

        line.addView(
            View(this).apply {
                tag = TAG_SERVER_RAIL
                background = rounded(
                    if (active) palette.accent else android.graphics.Color.TRANSPARENT,
                    3,
                )
                alpha = if (active) 1f else 0f
            },
            LinearLayout.LayoutParams(dp(3), dp(34)).apply { marginEnd = dp(8) },
        )

        val copy = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER_VERTICAL
        }
        copy.addView(
            textView("سرور " + ordinal, 13.5f, palette.textPrimary, Gravity.END).apply {
                tag = TAG_SERVER_TITLE
                setTypeface(typeface, if (active) Typeface.BOLD else Typeface.NORMAL)
                maxLines = 1
            },
        )
        copy.addView(
            textView(group.location.title, 9.5f, palette.textMuted, Gravity.END).apply {
                setPadding(0, dp(2), 0, 0)
                maxLines = 1
            },
        )
        line.addView(copy, LinearLayout.LayoutParams(0, -1, 1f))

        val action = when {
            automaticActive -> "خودکار"
            manualActive -> "دستی"
            !premium -> "🔒"
            active -> "فعال"
            else -> "انتخاب"
        }
        line.addView(
            textView(
                action,
                9.5f,
                if (active) palette.accent else palette.textSecondary,
                Gravity.CENTER,
            ).apply {
                tag = TAG_SERVER_ACTION
                setTypeface(typeface, Typeface.BOLD)
                background = rounded(
                    if (active) {
                        if (palette.dark) 0xFF213454.toInt() else 0xFFE7EFFF.toInt()
                    } else palette.surfaceStrong,
                    11,
                )
            },
            LinearLayout.LayoutParams(dp(62), dp(34)).apply { marginStart = dp(6) },
        )
        return surface
    }

    private fun createLocationSection(
        group: LocationGroup,
        active: Boolean,
        premium: Boolean,
    ): View {
        val expanded = group.location.key in expandedLocationKeys
        val automatic = BlueVpnPreferences.smartBalance(this)
        val outer = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
        }

        val header = card(
            radius = 20,
            fill = if (active) {
                if (palette.dark) 0xFF13213A.toInt() else 0xFFF1F5FF.toInt()
            } else palette.surface,
            stroke = if (active) palette.accent else palette.stroke,
        ).apply {
            tag = TAG_COUNTRY_SURFACE
            strokeWidth = dp(1)
            cardElevation = 0f
            isClickable = true
            isFocusable = true
            contentDescription = group.location.title + "؛ " + group.servers.size + " سرور؛ " + if (expanded) "باز" else "بسته"
            BlueVpnUiGuard.bind(this) {
                if (expanded) {
                    expandedLocationKeys.remove(group.location.key)
                } else {
                    expandedLocationKeys.clear()
                    expandedLocationKeys.add(group.location.key)
                }
                renderLocations()
            }
        }

        val column = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(11), dp(7), dp(11), dp(7))
        }
        header.addView(column)

        val topRow = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
        }
        column.addView(topRow, LinearLayout.LayoutParams(-1, dp(52)))

        topRow.addView(
            countryFlagBadge(group.location.flag, sizeDp = 52, textSp = 28f),
            LinearLayout.LayoutParams(dp(52), dp(52)).apply { marginEnd = dp(11) },
        )

        val content = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER_VERTICAL
        }
        content.addView(
            textView(group.location.title, 16f, palette.textPrimary, Gravity.END).apply {
                setTypeface(typeface, Typeface.BOLD)
                maxLines = 1
                ellipsize = android.text.TextUtils.TruncateAt.END
            },
        )
        content.addView(
            textView(
                group.servers.size.toString() + " سرور دسته‌بندی‌شده",
                9.5f,
                palette.textMuted,
                Gravity.END,
            ).apply {
                setPadding(0, dp(3), 0, 0)
                maxLines = 1
            },
        )
        topRow.addView(content, LinearLayout.LayoutParams(0, -1, 1f))

        val favoriteButton = TextView(this).apply {
            text = if (group.favorite) "★" else "☆"
            textSize = 18f
            gravity = Gravity.CENTER
            includeFontPadding = false
            background = rounded(
                if (group.favorite) {
                    if (palette.dark) 0xFF3D321B.toInt() else 0xFFFFF1C9.toInt()
                } else {
                    android.graphics.Color.TRANSPARENT
                },
                16,
            )
            setTextColor(
                if (group.favorite) 0xFFE5A91B.toInt() else palette.textMuted,
            )
            contentDescription = if (group.favorite) "حذف از علاقه‌مندی" else "افزودن به علاقه‌مندی"
            BlueVpnUiGuard.bind(this) {
                BlueVpnExperience.toggleFavorite(this@BlueVpnServersActivity, group.location.key)
                renderLocations()
            }
        }
        topRow.addView(favoriteButton, LinearLayout.LayoutParams(dp(48), dp(48)).apply {
            marginStart = dp(2)
        })

        val chevron = textView(
            if (expanded) "⌃" else "⌄",
            17f,
            if (expanded) palette.accent else palette.textMuted,
            Gravity.CENTER,
        )
        topRow.addView(chevron, LinearLayout.LayoutParams(dp(44), dp(48)).apply {
            marginStart = dp(2)
        })

        val bottomRow = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
        }
        val availabilityView = textView(
            group.servers.size.toString() + " سرور",
            9.5f,
            if (active) palette.accent else palette.textMuted,
            Gravity.CENTER_VERTICAL or Gravity.END,
        ).apply {
            tag = TAG_COUNTRY_AVAILABILITY
            background = rounded(palette.surfaceStrong, 11)
            setPadding(dp(10), 0, dp(10), 0)
            maxLines = 1
            ellipsize = android.text.TextUtils.TruncateAt.END
        }
        bottomRow.addView(availabilityView, LinearLayout.LayoutParams(0, dp(34), 1f))

        val chooseCountry = textView(
            when {
                !premium -> "🔒"
                active && automatic -> "AUTO"
                active -> "فعال"
                else -> "انتخاب"
            },
            9.5f,
            if (active) palette.accent else palette.textSecondary,
            Gravity.CENTER,
        ).apply {
            tag = TAG_COUNTRY_ACTION
            setTypeface(typeface, Typeface.BOLD)
            background = rounded(
                if (active) {
                    if (palette.dark) 0xFF213454.toInt() else 0xFFE7EFFF.toInt()
                } else palette.surfaceStrong,
                11,
            )
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
        bottomRow.addView(
            chooseCountry,
            LinearLayout.LayoutParams(dp(64), dp(34)).apply { marginStart = dp(6) },
        )
        column.addView(bottomRow, LinearLayout.LayoutParams(-1, dp(34)).apply {
            topMargin = dp(5)
        })

        outer.addView(header, LinearLayout.LayoutParams(-1, dp(105)))

        // Expanded server rows are flattened into the RecyclerView adapter.
        // Keeping them out of the country View makes server rows virtualized and
        // lets DiffUtil add/remove only the affected items.
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

    private fun restorePersistedLocationUiState() {
        val prefs = getSharedPreferences("bluevpn_locations_ui", MODE_PRIVATE)
        selectedTab = runCatching {
            LocationTab.valueOf(prefs.getString("tab", LocationTab.ALL.name).orEmpty())
        }.getOrDefault(LocationTab.ALL)
        selectedQualityFilter = QualityFilter.ALL
        queryText = prefs.getString("query_text", "").orEmpty()
        query = BlueVpnLocationUtil.normalizeForSearch(queryText)
        expandedLocationKeys.clear()
        expandedLocationKeys.addAll(
            prefs.getStringSet("expanded_keys", emptySet()).orEmpty()
        )
        restoredScrollY = prefs.getInt(scrollPreferenceKey(), 0).coerceAtLeast(0)
        initialScrollRestored = true
    }

    private fun persistLocationUiState() {
        getSharedPreferences("bluevpn_locations_ui", MODE_PRIVATE)
            .edit()
            .putString("tab", selectedTab.name)
            .putString("quality_filter", selectedQualityFilter.name)
            .putString("query_text", queryText)
            .putStringSet("expanded_keys", LinkedHashSet(expandedLocationKeys))
            .apply()
    }

    private fun scrollPreferenceKey(): String {
        val queryBucket = if (query.isBlank()) "none" else query.hashCode().toString()
        return "scroll_y:" + selectedTab.name + ":" + selectedQualityFilter.name + ":" + queryBucket
    }

    private fun rememberLocationScroll() {
        if (!::locationsRecyclerView.isInitialized) return
        getSharedPreferences("bluevpn_locations_ui", MODE_PRIVATE)
            .edit()
            .putInt(
                scrollPreferenceKey(),
                locationsRecyclerView.computeVerticalScrollOffset(),
            )
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
            // Manual country selection should preview the strongest route in that
            // country, not whichever profile happened to be first in MMKV order.
            // The group is already entitlement-isolated, so trusted ranking avoids
            // re-enumerating the whole account inventory.
            BlueVpnSmartSelector.rankTrusted(this, group.servers)
                .firstOrNull()
                ?.candidate
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
    private fun stopRefreshingVisual() {
        renderHandler.removeCallbacks(refreshTimeoutRunnable)
        if (!::refreshButton.isInitialized) return
        refreshButton.isEnabled = true
        refreshButton.text = "↻"
    }

    private fun tabButton(label: String, action: () -> Unit): TextView = TextView(this).apply {
        text = label
        textSize = 10.5f
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

    private fun gradientRounded(
        start: Int,
        end: Int,
        radiusDp: Int,
    ): GradientDrawable = GradientDrawable(
        GradientDrawable.Orientation.LEFT_RIGHT,
        intArrayOf(start, end),
    ).apply {
        cornerRadius = dp(radiusDp).toFloat()
    }

    private fun dp(value: Int): Int = (value * resources.displayMetrics.density).toInt()
}
