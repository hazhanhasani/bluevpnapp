package com.v2ray.ang.ui

import android.Manifest
import android.animation.ValueAnimator
import android.app.Dialog
import android.content.BroadcastReceiver
import android.content.ComponentCallbacks2
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.content.pm.PackageManager
import android.content.res.ColorStateList
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import android.graphics.RenderEffect
import android.graphics.Shader
import android.graphics.Typeface
import android.graphics.drawable.ColorDrawable
import android.graphics.drawable.GradientDrawable
import android.net.TrafficStats
import android.net.VpnService
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.os.SystemClock
import android.util.Base64
import android.view.Gravity
import android.view.MotionEvent
import android.view.View
import android.view.ViewGroup
import android.view.animation.AccelerateDecelerateInterpolator
import android.view.animation.LinearInterpolator
import android.widget.FrameLayout
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.ProgressBar
import android.widget.ScrollView
import android.widget.TextView
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.widget.AppCompatTextView
import androidx.activity.viewModels
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import com.google.android.material.button.MaterialButton
import com.google.android.material.card.MaterialCardView
import com.v2ray.ang.AppConfig
import com.v2ray.ang.BuildConfig
import com.v2ray.ang.dto.TestServiceMessage
import com.v2ray.ang.R
import com.v2ray.ang.core.CoreServiceManager
import com.v2ray.ang.core.LauncherManager
import com.v2ray.ang.bluevpn.BlueVpnAccountManager
import com.v2ray.ang.bluevpn.BlueVpnBackgroundReliability
import com.v2ray.ang.bluevpn.BlueVpnBackgroundOptimizer
import com.v2ray.ang.bluevpn.BlueVpnAdsCarouselView
import com.v2ray.ang.bluevpn.BlueVpnAi
import com.v2ray.ang.bluevpn.BlueVpnDynamicBackgroundView
import com.v2ray.ang.bluevpn.BlueVpnPalette
import com.v2ray.ang.bluevpn.BlueVpnPerformance
import com.v2ray.ang.bluevpn.BlueVpnTheme
import com.v2ray.ang.bluevpn.BlueVpnConnectionMode
import com.v2ray.ang.bluevpn.BlueVpnExperience
import com.v2ray.ang.bluevpn.BlueVpnIntelligenceCore
import com.v2ray.ang.bluevpn.BlueVpnIrcfIntelligence
import com.v2ray.ang.bluevpn.BlueVpnFreeStoryAdGate
import com.v2ray.ang.bluevpn.BlueVpnLocationUtil
import com.v2ray.ang.bluevpn.BlueVpnNetworkRecoveryManager
import com.v2ray.ang.bluevpn.BlueVpnLiveReporter
import com.v2ray.ang.bluevpn.BlueVpnUpdateManager
import com.v2ray.ang.bluevpn.BlueVpnUiGuard
import com.v2ray.ang.bluevpn.BlueVpnPreferences
import com.v2ray.ang.bluevpn.BlueVpnRouteIntelligence
import com.v2ray.ang.bluevpn.BlueVpnRuntimeGate
import com.v2ray.ang.bluevpn.BlueVpnHandoverPhase
import com.v2ray.ang.bluevpn.BlueVpnHandoverState
import com.v2ray.ang.bluevpn.BlueVpnWarpEngine
import com.v2ray.ang.bluevpn.BlueVpnWarpKeepAliveService
import com.v2ray.ang.bluevpn.BlueVpnEntitlement
import com.v2ray.ang.bluevpn.BlueVpnPlanTier
import com.v2ray.ang.bluevpn.BlueVpnSelectionMode
import com.v2ray.ang.bluevpn.BlueVpnSmartSelector
import com.v2ray.ang.bluevpn.BlueVpnSupportNotifications
import com.v2ray.ang.bluevpn.BlueVpnTapsellManager
import com.v2ray.ang.handler.MmkvManager
import com.v2ray.ang.handler.SettingsManager
import com.v2ray.ang.handler.SubscriptionUpdater
import com.v2ray.ang.viewmodel.MainViewModel
import com.v2ray.ang.util.Utils
import com.v2ray.ang.helper.MessageHelper
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.net.HttpURLConnection
import java.net.InetSocketAddress
import java.net.Proxy
import java.net.Socket
import java.net.URL
import java.text.SimpleDateFormat
import java.util.Locale
import java.util.TimeZone
import java.util.concurrent.ExecutorCompletionService
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit
import kotlin.math.ceil
import kotlin.math.max

class BlueVpnHomeActivity : HelperBaseActivity() {

    private val mainViewModel: MainViewModel by viewModels()
    private val handler = Handler(Looper.getMainLooper())
    private val notificationPermissionLauncher =
        registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
            if (!granted && Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                Toast.makeText(
                    this,
                    "برای نمایش وضعیت و کنترل‌های اتصال، اعلان BlueVPN را از تنظیمات فعال کنید",
                    Toast.LENGTH_LONG,
                ).show()
            }
        }
    private lateinit var palette: BlueVpnPalette
    private var themeDarkAtCreate = true
    private var themeConnectionGraceUntil = 0L
    private var themeHealthRetryCount = 0
    private var dragStartRawX = 0f
    private var dragStartTranslation = 0f
    private var dragMoved = false
    private var lastConnectionToggleAt = 0L

    private lateinit var adsCarousel: BlueVpnAdsCarouselView
    private lateinit var connectButton: AppCompatTextView
    private lateinit var connectTrack: MaterialCardView
    private lateinit var connectHint: TextView
    private lateinit var orbHaloOuter: View
    private lateinit var orbHaloInner: View
    private var orbPulseAnimator: ValueAnimator? = null
    private var orbVisualState = OrbVisualState.IDLE
    private lateinit var statusText: TextView
    private lateinit var statusCaption: TextView
    private lateinit var statusDot: View
    private lateinit var serverName: TextView
    private lateinit var serverMeta: TextView
    private lateinit var serverStatusValue: TextView
    private lateinit var subscriptionSummary: TextView
    private lateinit var pingValue: TextView
    private lateinit var durationValue: TextView
    private lateinit var durationMetricLabel: TextView
    private lateinit var locationValue: TextView
    private lateinit var downloadSpeed: TextView
    private lateinit var uploadSpeed: TextView
    private lateinit var remainingVolume: TextView
    private lateinit var remainingTime: TextView
    private lateinit var qualityValue: TextView
    private lateinit var modeValue: TextView
    private lateinit var activeRoutesValue: TextView
    private lateinit var historyValue: TextView
    private lateinit var aiSummaryValue: TextView
    private lateinit var balancedModeButton: MaterialButton
    private lateinit var gamingModeButton: MaterialButton
    private lateinit var streamingModeButton: MaterialButton

    private var connectionVerified = false
    private var lastRx = 0L
    private var lastTx = 0L
    private var lastTrafficAt = 0L
    private var lastPingRequestAt = 0L
    private var sessionDownloadBytes = 0L
    private var sessionUploadBytes = 0L

    private var failoverActive = false
    private var failoverQueue: List<String> = emptyList()
    private var failoverReserveQueue: List<String> = emptyList()
    private var connectionEntitlementGuids: Set<String> = emptySet()
    private var connectionPreparationGeneration: Int = 0
    private var failoverIndex = -1
    private var attemptedGuid = ""
    private var waitingForPingResult = false
    private var healthProbeInProgress = false
    private var waitingForCoreStop = false
    private var coreStopRetryCount = 0
    private var verificationRound = 0
    private var existingSessionCheckInProgress = false
    private var existingSessionRetryCount = 0
    private var backgroundedAtElapsed = 0L
    private var lastVerifiedLatency = 0L
    private var serversOpenedWhileActive = false
    private var liveLocationSwitch = false
    private var switchTargetTitle = ""
    private val handoverState = BlueVpnHandoverState()
    private var accountSyncInProgress = false
    private var accountSyncForcePending = false
    private var lastForegroundAccountSyncAt = 0L
    private var lastForegroundFreePolicySyncAt = 0L
    private var userDisconnecting = false
    private var navigationLocked = false
    private var lastHistoryGuid = ""
    private var lastDashboardRefreshAt = 0L
    private var updateCheckScheduled = false
    private var accountLaunchInProgress = false
    private val navigationUnlock = Runnable {
        navigationLocked = false
        accountLaunchInProgress = false
    }
    private var candidateWarmupInProgress = false
    private var candidateWarmupForcePending = false
    private var freePreparationInProgress = false
    private var warpPreparationInProgress = false
    private var warpFallbackGeneration = -1
    private var entitlementReconcileInProgress = false
    private var retryConnectionAfterEntitlementReconcile = false
    private var startupPipelineStarted = false
    private var dashboardRefreshPosted = false
    private var dashboardForcePending = false
    private var lastGuestPreparationAt = 0L
    private var startupWarmupPosted = false
    private var candidateLoadInProgress = false
    private var networkSweepInProgress = false
    private var networkSweepGeneration = 0
    private var networkSweepStartedAt = 0L
    private var networkSweepTotal = 0
    private var networkSweepPollInFlight = false
    private var networkSweepGuids: List<String> = emptyList()
    private var networkSweepBlockingTotal = 0
    private var networkSweepCandidates: List<BlueVpnLocationUtil.Candidate> = emptyList()
    private var networkSweepSelectionMode: BlueVpnSelectionMode = BlueVpnSelectionMode.AUTO
    private var smoothedDownloadBps = 0.0
    private var lastThroughputLearningAt = 0L
    private var smoothedUploadBps = 0.0
    private var lastTrafficSampleElapsed = 0L
    private var lastRenderedFreeTimerText = ""
    private var lastNonZeroDownloadElapsed = 0L
    private var lastNonZeroUploadElapsed = 0L
    private var pendingConnectionRequest = false
    private var runtimeGateRetryScheduled = false
    private var runtimeGateWaitStartedAt = 0L
    private var terminalFailureStopping = false
    private var terminalFailureReason = ""
    private var lastCandidateFailureReason = ""
    private var verificationDeadlineGuid = ""
    private var recoveryCleanupRequired = false
    private var userInteractedAt = 0L
    private var coreFailureReceiverRegistered = false
    private var firstHomeResume = true
    private var freeStoryGateActive = false
    private var freeStoryGate: BlueVpnFreeStoryAdGate? = null

    /**
     * BlueVPN observes the stock v2rayNG activity broadcast only to advance
     * hidden-route failover immediately when upstream reports START_FAILURE.
     * It never starts/stops/configures the core itself; MainViewModel remains
     * the authoritative owner of RUNNING/NOT_RUNNING state.
     */
    private val coreFailureReceiver = object : BroadcastReceiver() {
        override fun onReceive(context: Context?, intent: Intent?) {
            if (intent?.getIntExtra("key", 0) != AppConfig.MSG_STATE_START_FAILURE) return
            if (!failoverActive || userDisconnecting || terminalFailureStopping) return

            val upstreamReason = intent.getStringExtra("content")
                ?.trim()
                .orEmpty()
                .ifBlank { "هسته اتصال نتوانست این مسیر را شروع کند" }
            handler.removeCallbacks(attemptTimeout)
            failCurrentAndTryNext(normalizeCoreStartFailure(upstreamReason))
        }
    }

    private fun normalizeCoreStartFailure(reason: String): String {
        val compact = reason.replace(Regex("\\s+"), " ").trim()
        val lower = compact.lowercase(Locale.US)
        return when {
            "failed to parse json" in lower ||
                "parse json config" in lower ||
                "invalid character" in lower ->
                "کانفیگ این مسیر نامعتبر بود؛ مسیر از این نوبت کنار گذاشته شد و سرور بعدی بررسی می‌شود"
            compact.isBlank() -> "هسته اتصال نتوانست این مسیر را شروع کند؛ سرور بعدی بررسی می‌شود"
            else -> compact.take(180)
        }
    }

    private lateinit var freeTimerBadge: TextView
    private lateinit var connectingOverlay: FrameLayout
    private lateinit var connectingGlobe: ConnectingGlobeView
    private lateinit var connectingTitle: TextView
    private lateinit var connectingCaption: TextView
    private lateinit var connectingNotice: TextView
    private lateinit var connectingLocation: TextView

    private var startupOptimizationShown = false
    private var startupOptimizationActive = false
    private var startupServerTestStarted = false
    private var startupProgress = 0
    private var startupDialog: Dialog? = null
    private var startupProgressBar: ProgressBar? = null
    private var startupProgressText: TextView? = null
    private var startupStageText: TextView? = null

    private val attemptTimeout = Runnable {
        if (!failoverActive || userDisconnecting || waitingForCoreStop) {
            return@Runnable
        }
        failCurrentAndTryNext("هسته Xray در زمان مجاز شروع نشد")
    }

    private val verificationTimeout = Runnable {
        val guid = verificationDeadlineGuid
        if (
            guid.isBlank() ||
            !failoverActive ||
            userDisconnecting ||
            waitingForCoreStop ||
            attemptedGuid != guid ||
            connectionVerified
        ) {
            return@Runnable
        }
        verificationDeadlineGuid = ""
        failCurrentAndTryNext(
            "تأیید اینترنت این مسیر در زمان مجاز کامل نشد؛ سرور بعدی بررسی می‌شود"
        )
    }

    private val coreStopTimeout = object : Runnable {
        override fun run() {
            if (!failoverActive || userDisconnecting || !waitingForCoreStop) return

            if (coreStopRetryCount < 1) {
                coreStopRetryCount += 1
                LauncherManager.stopService(this@BlueVpnHomeActivity)
                handler.postDelayed(this, 4_000L)
                return
            }

            // Failure to stop the previous service is a runtime/lifecycle issue,
            // not evidence that the next profile is invalid. Do not quarantine it.
            waitingForCoreStop = false
            finishFailoverWithError("اتصال قبلی کامل متوقف نشد؛ دوباره تلاش کنید")
        }
    }

    private val disconnectRetry = object : Runnable {
        private var attempt = 0

        fun reset() {
            attempt = 0
        }

        override fun run() {
            if (!userDisconnecting) return

            if (mainViewModel.isRunning.value == true) {
                LauncherManager.stopService(this@BlueVpnHomeActivity)
            }

            attempt += 1
            if (
                mainViewModel.isRunning.value == true &&
                attempt < 4
            ) {
                handler.postDelayed(this, 280L)
            } else {
                userDisconnecting = false
                BlueVpnPreferences.clearConnected(
                    this@BlueVpnHomeActivity
                )
                BlueVpnRuntimeGate.endConnection(this@BlueVpnHomeActivity)
                renderConnectionState(false)
            }
        }
    }

    private val requestPing = Runnable {
        if (!failoverActive) return@Runnable
        if (!isExactAttemptRunning()) return@Runnable

        waitingForPingResult = true
        mainViewModel.testCurrentServerRealPing()
    }

    private val delayedAdsStart = Runnable {
        if (
            !isFinishing && !isDestroyed &&
            ::adsCarousel.isInitialized &&
            !failoverActive && !userDisconnecting
        ) {
            adsCarousel.start()
        }
    }

    private val statsTicker = object : Runnable {
        override fun run() {
            updateLiveStats()
            val connected = mainViewModel.isRunning.value == true &&
                !failoverActive &&
                connectionVerified
            handler.postDelayed(this, if (connected) 1_000L else 3_000L)
        }
    }

    private val networkSweepTicker: Runnable = object : Runnable {
        override fun run() {
            if (!networkSweepInProgress || networkSweepPollInFlight) return
            networkSweepPollInFlight = true
            val generation = networkSweepGeneration
            val guids = networkSweepGuids.toList()
            val startedAt = networkSweepStartedAt
            lifecycleScope.launch(Dispatchers.Default) {
                var tested = 0
                var healthy = 0
                var failed = 0
                var best = Long.MAX_VALUE
                guids.forEach { guid ->
                    val delay = MmkvManager.decodeServerAffiliationInfo(guid)?.testDelayMillis ?: 0L
                    if (delay != 0L) tested += 1
                    if (delay > 0L) {
                        healthy += 1
                        if (delay < best) best = delay
                    } else if (delay < 0L) {
                        failed += 1
                    }
                }
                val elapsed = SystemClock.elapsedRealtime() - startedAt
                val complete = guids.isNotEmpty() && tested >= guids.size
                // Do not make the user wait for a full 100-200 route sweep.
                // The blocking phase only needs a trustworthy quorum; the full
                // entitlement inventory stays available as progressive failover.
                val quorumTarget = minOf(4, guids.size).coerceAtLeast(1)
                val earlyQuorum = elapsed >= 700L && tested >= quorumTarget && healthy >= 1
                val timeout = elapsed >= 2_200L
                withContext(Dispatchers.Main) {
                    networkSweepPollInFlight = false
                    if (!networkSweepInProgress || generation != networkSweepGeneration) return@withContext
                    val bestText = if (best != Long.MAX_VALUE) " • بهترین ${best}ms" else ""
                    if (::connectingCaption.isInitialized) {
                        connectingCaption.text = "اسکن سریع $tested از ${guids.size} مسیر • سالم $healthy$bestText"
                    }
                    if (::connectingLocation.isInitialized) {
                        connectingLocation.text = when {
                            healthy >= 1 && tested >= guids.size -> "رتبه‌بندی نهایی بر اساس شبکه شما"
                            healthy >= 1 -> "در حال دسته‌بندی کیفیت اتصال‌ها"
                            else -> "در حال تست کانفیگ‌ها با اینترنت شما"
                        }
                    }
                    if (complete || earlyQuorum || timeout) {
                        finishNetworkSweep(
                            generation,
                            timedOut = timeout && !complete && !earlyQuorum,
                            earlyQuorum = earlyQuorum,
                        )
                    } else {
                        handler.postDelayed({ if (networkSweepInProgress) handler.post(networkSweepTicker) }, 280L)
                    }
                }
            }
        }
    }

    private val freeSessionTicker = object : Runnable {
        override fun run() {
            if (BlueVpnAccountManager.enforceFreeSession(this@BlueVpnHomeActivity)) {
                connectionVerified = false
                cancelFailover()
                renderConnectionState(false)
                statusText.text = "زمان اتصال رایگان پایان یافت"
                statusCaption.text = "برای اتصال رایگان بعدی دوباره دکمه اتصال را بزنید"
                Toast.makeText(
                    this@BlueVpnHomeActivity,
                    "زمان اتصال رایگان پایان یافت",
                    Toast.LENGTH_LONG,
                ).show()
            }

            val entitlement = BlueVpnEntitlement.resolveUi(this@BlueVpnHomeActivity)
            val activeTimedFree = entitlement.isFree &&
                entitlement.timeLimited &&
                (
                    connectionVerified ||
                    mainViewModel.isRunning.value == true ||
                    failoverActive
                )

            if (activeTimedFree) updateFreeTimerBadge()
            handler.postDelayed(this, if (activeTimedFree) 1_000L else 15_000L)
        }
    }


    private val startupProgressTicker = object : Runnable {
        override fun run() {
            if (!startupOptimizationActive) return

            if (startupProgress < 92) {
                val step = when {
                    startupProgress < 20 -> 4
                    startupProgress < 45 -> 3
                    startupProgress < 75 -> 2
                    else -> 1
                }
                updateStartupProgress(
                    (startupProgress + step).coerceAtMost(92)
                )
            }

            handler.postDelayed(this, 360L)
        }
    }

    private val startupOptimizationTimeout = Runnable {
        if (startupOptimizationActive) {
            finishStartupOptimization(timedOut = true)
        }
    }

    private val requestVpnPermission =
        registerForActivityResult(
            ActivityResultContracts.StartActivityForResult()
        ) {
            if (it.resultCode == RESULT_OK) {
                startCurrentCandidate()
            } else {
                cancelFailover()
                renderConnectionState(false)
            }
        }

    private val selectLocationLauncher =
        registerForActivityResult(
            ActivityResultContracts.StartActivityForResult()
        ) { result ->
            val changed =
                result.resultCode == RESULT_OK &&
                    result.data?.getBooleanExtra(
                        BlueVpnServersActivity.EXTRA_LOCATION_CHANGED,
                        false
                    ) == true

            val selectedTitle =
                result.data?.getStringExtra(
                    BlueVpnServersActivity.EXTRA_LOCATION_TITLE
                ).orEmpty()

            requestDashboardRefresh(force = true)

            if (changed && serversOpenedWhileActive) {
                handoverState.beginSelection()
                startLiveLocationSwitch(selectedTitle)
            }

            serversOpenedWhileActive = false
            navigationLocked = false
        }

    private val accountLauncher =
        registerForActivityResult(
            ActivityResultContracts.StartActivityForResult()
        ) { result ->
            navigationLocked = false
            accountLaunchInProgress = false
            if (BlueVpnAccountManager.premiumEntitlementActive(this)) {
                if (!startupOptimizationShown) {
                    startStartupOptimization()
                } else {
                    // A normal Back from Account is routine/cache-first. RESULT_OK is
                    // reserved for an auth/account mutation that can justify an
                    // authoritative refresh; payment activation already syncs in the
                    // account Activity itself.
                    syncManagedAccount(force = result.resultCode == RESULT_OK)
                }
            } else {
                // No live Premium entitlement means the account belongs to the Free
                // plan, even when the user is still authenticated. Clear any old
                // Premium candidate queue and prepare the exact Free pool.
                connectionPreparationGeneration += 1
                cancelFailover()
                connectionVerified = false
                BlueVpnPreferences.clearConnected(this)
                renderConnectionState(false)
                prepareFreePlanAccess(force = false)
                requestDashboardRefresh(force = true)
                refreshSubscriptionInfo(force = false)
                if (BlueVpnAccountManager.hasSession(this) && !startupOptimizationShown) {
                    startStartupOptimization()
                }
            }
        }


    private enum class OrbVisualState {
        IDLE,
        CONNECTING,
        CONNECTED,
        ERROR,
    }

    private class PowerGlyphView(
        context: android.content.Context,
    ) : AppCompatTextView(context) {
        private val glyphPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
            style = Paint.Style.STROKE
            strokeCap = Paint.Cap.ROUND
        }
        private var glyphColor = Color.WHITE

        fun setGlyphColor(color: Int) {
            glyphColor = color
            invalidate()
        }

        override fun onDraw(canvas: Canvas) {
            super.onDraw(canvas)
            glyphPaint.color = glyphColor
            glyphPaint.strokeWidth = width.coerceAtMost(height) * 0.055f
            val cx = width / 2f
            val cy = height / 2f + height * 0.035f
            val radius = width.coerceAtMost(height) * 0.24f
            val gap = 52f
            canvas.drawArc(
                cx - radius,
                cy - radius,
                cx + radius,
                cy + radius,
                -90f + gap,
                360f - gap * 2f,
                false,
                glyphPaint,
            )
            canvas.drawLine(
                cx,
                cy - radius * 1.28f,
                cx,
                cy - radius * 0.15f,
                glyphPaint,
            )
        }
    }

    private class ConnectingGlobeView(
        context: Context,
        private val lowEnd: Boolean,
    ) : View(context) {
        private val stroke = Paint(Paint.ANTI_ALIAS_FLAG).apply {
            style = Paint.Style.STROKE
            strokeCap = Paint.Cap.ROUND
        }
        private val fill = Paint(Paint.ANTI_ALIAS_FLAG).apply {
            style = Paint.Style.FILL
        }
        private val label = Paint(Paint.ANTI_ALIAS_FLAG).apply {
            textAlign = Paint.Align.CENTER
            typeface = Typeface.create(Typeface.DEFAULT, Typeface.BOLD_ITALIC)
        }
        private var accent = Color.parseColor("#3978FF")
        private var phase = 0f
        private var pulse = 0f
        private var animator: ValueAnimator? = null

        fun setAccent(color: Int) {
            accent = color
            invalidate()
        }

        fun start() {
            if (animator?.isRunning == true) return
            // Low-end devices still animate. The old implementation disabled the
            // animator completely, which made the connecting screen look frozen.
            animator = ValueAnimator.ofFloat(0f, 1f).apply {
                duration = if (lowEnd) 2_800L else 2_000L
                repeatCount = ValueAnimator.INFINITE
                interpolator = LinearInterpolator()
                addUpdateListener { value ->
                    val t = value.animatedValue as Float
                    phase = t * 360f
                    pulse = (0.5f - 0.5f * kotlin.math.cos(t * Math.PI * 2.0).toFloat())
                    postInvalidateOnAnimation()
                }
                start()
            }
        }

        fun stop() {
            animator?.cancel()
            animator = null
        }

        override fun onDetachedFromWindow() {
            stop()
            super.onDetachedFromWindow()
        }

        override fun onDraw(canvas: Canvas) {
            super.onDraw(canvas)
            val side = width.coerceAtMost(height).toFloat()
            val cx = width / 2f
            val cy = height / 2f
            val radius = side * 0.36f

            // A soft breathing halo entirely inside the component. The previous
            // outer partial arc looked like a stray line before the globe.
            fill.color = Color.argb(14 + (pulse * 18f).toInt(), Color.red(accent), Color.green(accent), Color.blue(accent))
            canvas.drawCircle(cx, cy, radius * (1.18f + pulse * 0.025f), fill)

            stroke.color = Color.argb(220, Color.red(accent), Color.green(accent), Color.blue(accent))
            stroke.strokeWidth = side * 0.014f
            canvas.drawCircle(cx, cy, radius, stroke)
            canvas.drawOval(cx - radius * 0.46f, cy - radius, cx + radius * 0.46f, cy + radius, stroke)
            canvas.drawOval(cx - radius, cy - radius * 0.42f, cx + radius, cy + radius * 0.42f, stroke)

            // Two moving nodes stay on the globe itself, so the animation feels
            // continuous without an orphan progress line.
            val angle1 = Math.toRadians(phase.toDouble())
            val angle2 = Math.toRadians((phase + 180f).toDouble())
            fill.color = Color.WHITE
            canvas.drawCircle(
                cx + kotlin.math.cos(angle1).toFloat() * radius,
                cy + kotlin.math.sin(angle1).toFloat() * radius * 0.42f,
                side * 0.021f,
                fill,
            )
            fill.color = Color.argb(205, Color.red(accent), Color.green(accent), Color.blue(accent))
            canvas.drawCircle(
                cx + kotlin.math.cos(angle2).toFloat() * radius * 0.46f,
                cy + kotlin.math.sin(angle2).toFloat() * radius,
                side * 0.017f,
                fill,
            )

            label.color = Color.WHITE
            label.textSize = side * 0.105f
            canvas.drawText("BlueVPN", cx, cy + label.textSize * 0.34f, label)
        }
    }

    private class HeaderGlyphView(
        context: android.content.Context,
        private val kind: String,
        private val colorProvider: () -> Int,
    ) : View(context) {
        private val iconPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
            style = Paint.Style.STROKE
            strokeCap = Paint.Cap.ROUND
            strokeJoin = Paint.Join.ROUND
        }

        override fun onDraw(canvas: Canvas) {
            super.onDraw(canvas)
            iconPaint.color = colorProvider()
            iconPaint.strokeWidth = width.coerceAtMost(height) * 0.055f
            val cx = width / 2f
            val cy = height / 2f
            if (kind == "menu") {
                val left = width * 0.27f
                val right = width * 0.73f
                canvas.drawLine(left, cy - height * 0.16f, right, cy - height * 0.16f, iconPaint)
                canvas.drawLine(left, cy, right, cy, iconPaint)
                canvas.drawLine(left, cy + height * 0.16f, right, cy + height * 0.16f, iconPaint)
            } else {
                val r = width * 0.11f
                canvas.drawCircle(cx, cy - height * 0.12f, r, iconPaint)
                canvas.drawArc(
                    width * 0.29f,
                    cy - height * 0.01f,
                    width * 0.71f,
                    cy + height * 0.34f,
                    190f,
                    160f,
                    false,
                    iconPaint,
                )
            }
        }
    }

    /**
     * Builds the complete BlueVPN home screen without inflating XML.
     * The layout intentionally uses only lightweight platform Views and
     * MaterialCardView so cold start stays fast on older Android devices.
     */
    private fun createScreen(): View {
        palette = BlueVpnTheme.palette(this)
        val root = FrameLayout(this).apply {
            layoutDirection = View.LAYOUT_DIRECTION_RTL
            fitsSystemWindows = true
            clipChildren = false
            clipToPadding = false
            setBackgroundColor(palette.background)
        }
        root.addView(
            BlueVpnDynamicBackgroundView(this),
            FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT,
            ),
        )

        val content = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            layoutDirection = View.LAYOUT_DIRECTION_RTL
            setPadding(dpHome(18), dpHome(8), dpHome(18), dpHome(14))
        }
        root.addView(
            content,
            FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT,
            ),
        )

        content.addView(
            createHeader(),
            LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                dpHome(54),
            ),
        )
        content.addView(
            createBrandBlock(),
            LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                dpHome(74),
            ).apply { topMargin = dpHome(8) },
        )
        content.addView(
            createOrbStage(),
            LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                0,
                1f,
            ).apply {
                topMargin = dpHome(4)
                bottomMargin = dpHome(12)
            },
        )
        // Real-time connection telemetry lives in the intentionally reserved
        // area directly below the connection control. It shows actual UID/VPN
        // throughput plus the Free countdown or exact Premium connected time.
        content.addView(
            createLiveConnectionMetrics(),
            LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                dpHome(64),
            ).apply {
                marginStart = dpHome(4)
                marginEnd = dpHome(4)
                bottomMargin = dpHome(8)
            },
        )

        // Compact campaign banner: directly below the connection telemetry and
        // immediately above server selection, matching the primary user flow.
        adsCarousel = BlueVpnAdsCarouselView(this)
        content.addView(
            adsCarousel,
            LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                0,
            ).apply {
                marginStart = dpHome(4)
                marginEnd = dpHome(4)
                topMargin = dpHome(2)
                bottomMargin = dpHome(10)
            },
        )
        // Location selection and connection status are intentionally one surface.
        // Internal route scoring/AI remains background-only and is never exposed
        // as a separate card in the public UI.
        content.addView(
            createServerCard(),
            LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                dpHome(108),
            ),
        )
        content.addView(
            createActionRow(),
            LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                dpHome(54),
            ).apply { topMargin = dpHome(10) },
        )
        content.addView(createCompatibilityFields())

        connectingOverlay = createConnectingOverlay()
        root.addView(
            connectingOverlay,
            FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT,
            ),
        )
        return root
    }

    private fun createHeader(): View {
        val row = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            layoutDirection = View.LAYOUT_DIRECTION_RTL
        }

        row.addView(
            headerIcon("menu", R.id.bluevpn_action_settings, "تنظیمات"),
            LinearLayout.LayoutParams(dpHome(56), dpHome(56)),
        )
        row.addView(
            uiText("", 10f, palette.textMuted, bold = true, gravity = Gravity.CENTER).apply {
                id = R.id.bluevpn_premium_badge
                maxLines = 1
            },
            LinearLayout.LayoutParams(dpHome(64), dpHome(44)).apply {
                marginStart = dpHome(8)
            },
        )
        // Free-session countdown and Premium connected duration are rendered
        // in the live metrics strip under the main connection control. Keeping
        // the header clean also prevents the countdown from competing with the
        // version badge on small displays.
        row.addView(View(this), LinearLayout.LayoutParams(0, 1, 1f))
        row.addView(
            headerIcon("account", R.id.bluevpn_action_subscription, "حساب و اشتراک"),
            LinearLayout.LayoutParams(dpHome(56), dpHome(56)),
        )
        return row
    }

    // Legacy regression marker: «اتصال رایگان تا ۶۰ دقیقه» is now rendered
    // dynamically from the Free entitlement and is never shown for Premium.
    private fun createConnectingOverlay(): FrameLayout {
        val overlay = FrameLayout(this).apply {
            visibility = View.GONE
            isClickable = true
            isFocusable = true
            setBackgroundColor(palette.background)
        }
        val body = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER_HORIZONTAL
            layoutDirection = View.LAYOUT_DIRECTION_RTL
            setPadding(dpHome(24), dpHome(28), dpHome(24), dpHome(24))
        }
        overlay.addView(
            body,
            FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT,
            ),
        )

        val top = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
        }
        top.addView(
            MaterialButton(this).apply {
                text = "لغو"
                textSize = 11f
                isAllCaps = false
                insetTop = 0
                insetBottom = 0
                cornerRadius = dpHome(16)
                setTextColor(palette.textSecondary)
                backgroundTintList = ColorStateList.valueOf(palette.surfaceStrong)
                strokeColor = ColorStateList.valueOf(palette.stroke)
                strokeWidth = dpHome(1)
                BlueVpnUiGuard.bind(this, intervalMs = 700L) {
                    stopConnectionImmediately()
                }
            },
            LinearLayout.LayoutParams(dpHome(82), dpHome(44)),
        )
        top.addView(View(this), LinearLayout.LayoutParams(0, 1, 1f))
        top.addView(
            uiText("BlueVPN", 14f, palette.accent, bold = true, gravity = Gravity.CENTER),
            LinearLayout.LayoutParams(dpHome(100), dpHome(44)),
        )
        body.addView(top, LinearLayout.LayoutParams(-1, dpHome(48)))

        body.addView(View(this), LinearLayout.LayoutParams(1, 0, 0.30f))

        connectingGlobe = ConnectingGlobeView(
            this,
            BlueVpnPerformance.isLowEnd(this),
        ).apply { setAccent(palette.accent) }
        body.addView(
            connectingGlobe,
            LinearLayout.LayoutParams(dpHome(226), dpHome(226)),
        )

        connectingTitle = uiText(
            "در حال اتصال",
            22f,
            palette.textPrimary,
            bold = true,
            gravity = Gravity.CENTER,
        )
        body.addView(
            connectingTitle,
            LinearLayout.LayoutParams(-1, dpHome(42)).apply { topMargin = dpHome(12) },
        )

        connectingLocation = uiText(
            "انتخاب خودکار",
            14f,
            palette.accent,
            bold = true,
            gravity = Gravity.CENTER,
        )
        body.addView(connectingLocation, LinearLayout.LayoutParams(-1, dpHome(34)))

        connectingCaption = uiText(
            "در حال انتخاب بهترین اتصال",
            11f,
            palette.textMuted,
            gravity = Gravity.CENTER,
        ).apply { maxLines = 2 }
        body.addView(connectingCaption, LinearLayout.LayoutParams(-1, dpHome(48)))

        body.addView(View(this), LinearLayout.LayoutParams(1, 0, 0.55f))

        val notice = glassCard(
            18,
            palette.stroke,
            palette.surface,
        )
        connectingNotice = uiText(
            BlueVpnEntitlement.resolveUi(this).connectionNotice,
            10.5f,
            palette.textSecondary,
            gravity = Gravity.CENTER,
        ).apply {
            maxLines = 3
            setPadding(dpHome(16), dpHome(8), dpHome(16), dpHome(8))
        }
        notice.addView(connectingNotice)
        body.addView(notice, LinearLayout.LayoutParams(-1, dpHome(58)))
        return overlay
    }

    private fun createBrandBlock(): View {
        val box = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER
        }
        box.addView(
            uiText("BlueVPN", 31f, palette.accent, bold = true, gravity = Gravity.CENTER).apply {
                typeface = Typeface.create(Typeface.DEFAULT, Typeface.BOLD_ITALIC)
                letterSpacing = 0.02f
            },
            LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                dpHome(45),
            ),
        )
        subscriptionSummary = uiText(
            "در حال آماده‌سازی حساب",
            10.5f,
            palette.textMuted,
            gravity = Gravity.CENTER,
        ).apply {
            id = R.id.bluevpn_subscription_summary
            maxLines = 1
            ellipsize = android.text.TextUtils.TruncateAt.END
        }
        box.addView(
            subscriptionSummary,
            LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                dpHome(24),
            ),
        )
        return box
    }

    private fun headerIcon(
        kind: String,
        idValue: Int,
        description: String,
    ): View = HeaderGlyphView(this, kind) { palette.textSecondary }.apply {
        id = idValue
        contentDescription = description
        isClickable = true
        isFocusable = true
        background = roundedGradient(
            intArrayOf(palette.surfaceStrong, palette.surface),
            17,
            palette.stroke,
        )
        elevation = dpHome(1).toFloat()
    }

    private fun createOrbStage(): View {
        val stage = FrameLayout(this).apply {
            clipChildren = false
            clipToPadding = false
            setPadding(dpHome(4), dpHome(4), dpHome(4), dpHome(4))
        }

        statusDot = View(this).apply {
            id = R.id.bluevpn_status_dot
            background = circleDrawable(palette.textMuted)
        }
        stage.addView(
            statusDot,
            FrameLayout.LayoutParams(dpHome(8), dpHome(8), Gravity.TOP or Gravity.CENTER_HORIZONTAL).apply {
                topMargin = dpHome(10)
                marginStart = dpHome(132)
            },
        )

        statusText = uiText(
            "آماده اتصال",
            20f,
            palette.textPrimary,
            bold = true,
            gravity = Gravity.CENTER,
        ).apply {
            id = R.id.bluevpn_status_text
            maxLines = 2
            includeFontPadding = false
        }
        stage.addView(
            statusText,
            FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                dpHome(48),
                Gravity.TOP or Gravity.CENTER_HORIZONTAL,
            ).apply {
                topMargin = dpHome(12)
                marginStart = dpHome(12)
                marginEnd = dpHome(12)
            },
        )

        statusCaption = uiText(
            "بهترین اتصال به‌صورت خودکار انتخاب می‌شود",
            11f,
            palette.textSecondary,
            gravity = Gravity.CENTER,
        ).apply {
            id = R.id.bluevpn_status_caption
            maxLines = 2
            includeFontPadding = false
            alpha = 1f
        }
        stage.addView(
            statusCaption,
            FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                dpHome(44),
                Gravity.TOP or Gravity.CENTER_HORIZONTAL,
            ).apply {
                topMargin = dpHome(58)
                marginStart = dpHome(20)
                marginEnd = dpHome(20)
            },
        )

        orbHaloOuter = View(this).apply {
            background = radialHaloDrawable(palette.accent, if (palette.dark) 34 else 20)
            alpha = if (palette.dark) 0.30f else 0.20f
        }
        stage.addView(
            orbHaloOuter,
            FrameLayout.LayoutParams(dpHome(330), dpHome(170), Gravity.BOTTOM or Gravity.CENTER_HORIZONTAL).apply {
                bottomMargin = dpHome(2)
            },
        )

        orbHaloInner = View(this).apply {
            background = roundedGradient(
                intArrayOf(
                    Color.argb(if (palette.dark) 42 else 24, Color.red(palette.accent), Color.green(palette.accent), Color.blue(palette.accent)),
                    Color.TRANSPARENT,
                ),
                58,
            )
            alpha = 0.58f
        }
        stage.addView(
            orbHaloInner,
            FrameLayout.LayoutParams(dpHome(292), dpHome(130), Gravity.BOTTOM or Gravity.CENTER_HORIZONTAL).apply {
                bottomMargin = dpHome(10)
            },
        )

        connectTrack = glassCard(
            54,
            palette.stroke,
            palette.surfaceStrong,
        ).apply {
            isClickable = true
            isFocusable = true
            contentDescription = "کلید اتصال BlueVPN"
            elevation = dpHome(3).toFloat()
        }
        stage.addView(
            connectTrack,
            FrameLayout.LayoutParams(dpHome(286), dpHome(104), Gravity.BOTTOM or Gravity.CENTER_HORIZONTAL).apply {
                bottomMargin = dpHome(18)
            },
        )

        val trackContent = FrameLayout(this).apply {
            setPadding(dpHome(8), dpHome(8), dpHome(8), dpHome(8))
        }
        connectTrack.addView(
            trackContent,
            FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT,
            ),
        )

        connectHint = uiText(
            "برای اتصال لمس یا بکشید",
            12f,
            palette.textSecondary,
            bold = true,
            gravity = Gravity.CENTER,
        ).apply {
            maxLines = 1
            setPadding(dpHome(98), 0, dpHome(12), 0)
        }
        trackContent.addView(
            connectHint,
            FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT,
            ),
        )

        connectButton = PowerGlyphView(this).apply {
            id = R.id.bluevpn_connect_button
            text = ""
            gravity = Gravity.CENTER
            isClickable = true
            isFocusable = true
            contentDescription = "اتصال یا قطع BlueVPN"
            elevation = dpHome(8).toFloat()
            includeFontPadding = false
        }
        trackContent.addView(
            connectButton,
            FrameLayout.LayoutParams(dpHome(88), dpHome(88), Gravity.LEFT or Gravity.CENTER_VERTICAL),
        )

        qualityValue = uiText("—", 9f, palette.accent, bold = true, gravity = Gravity.CENTER).apply {
            id = R.id.bluevpn_quality_value
            visibility = View.GONE
        }
        stage.addView(qualityValue, FrameLayout.LayoutParams(1, 1))

        applyOrbVisual(OrbVisualState.IDLE)
        return stage
    }

    private fun miniStat(
        label: String,
        bind: (TextView) -> Unit,
    ): View {
        val card = glassCard(
            18,
            Color.parseColor("#262831"),
            Color.argb(205, 17, 17, 22),
        )
        val box = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER
            setPadding(dpHome(3), dpHome(5), dpHome(3), dpHome(5))
        }
        card.addView(box)
        box.addView(uiText(label, 8f, Color.parseColor("#6F727E"), gravity = Gravity.CENTER))
        val value = uiText("—", 10.5f, Color.parseColor("#D8D9DF"), bold = true, gravity = Gravity.CENTER)
        box.addView(value)
        bind(value)
        return card
    }

    private fun createServerCard(): View {
        val card = glassCard(
            24,
            palette.stroke,
            palette.surface,
        ).apply {
            id = R.id.bluevpn_server_card
            isClickable = true
            isFocusable = true
            elevation = dpHome(2).toFloat()
        }

        val row = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            setPadding(dpHome(16), dpHome(10), dpHome(16), dpHome(10))
        }
        card.addView(row)

        val indicator = View(this).apply {
            background = circleDrawable(palette.accent)
        }
        row.addView(indicator, LinearLayout.LayoutParams(dpHome(12), dpHome(12)).apply {
            marginEnd = dpHome(12)
        })

        val details = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER_VERTICAL
            setPadding(dpHome(8), 0, dpHome(8), 0)
        }
        serverName = uiText("انتخاب خودکار", 15f, palette.textPrimary, bold = true).apply {
            id = R.id.bluevpn_server_name
            maxLines = 1
            ellipsize = android.text.TextUtils.TruncateAt.END
        }
        serverMeta = uiText("بهترین مسیر همان لوکیشن به‌صورت خودکار انتخاب می‌شود", 10f, palette.textMuted).apply {
            id = R.id.bluevpn_server_meta
            maxLines = 1
            ellipsize = android.text.TextUtils.TruncateAt.END
        }
        serverStatusValue = uiText("آماده اتصال", 9.5f, Color.parseColor("#747886")).apply {
            maxLines = 1
            ellipsize = android.text.TextUtils.TruncateAt.END
            setPadding(0, dpHome(3), 0, 0)
        }
        details.addView(serverName)
        details.addView(serverMeta)
        details.addView(serverStatusValue)
        row.addView(details, LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.MATCH_PARENT, 1f))

        val arrow = HeaderGlyphView(this, "menu") { palette.textMuted }.apply {
            rotation = 90f
            contentDescription = "نمایش مکان‌ها"
        }
        row.addView(arrow, LinearLayout.LayoutParams(dpHome(38), dpHome(38)))
        return card
    }

    private fun createModeRow(): View {
        val row = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER
        }
        balancedModeButton = modeButton("خودکار", R.id.bluevpn_mode_balanced)
        gamingModeButton = modeButton("حالت دوم", R.id.bluevpn_mode_gaming)
        streamingModeButton = modeButton("حالت سوم", R.id.bluevpn_mode_streaming)
        row.addView(balancedModeButton, LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.MATCH_PARENT, 1f))
        row.addView(gamingModeButton, LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.MATCH_PARENT, 1f).apply { marginStart = dpHome(6) })
        row.addView(streamingModeButton, LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.MATCH_PARENT, 1f).apply { marginStart = dpHome(6) })
        return row
    }

    private fun createActionRow(): View {
        val card = glassCard(
            18,
            palette.stroke,
            palette.surface,
        )
        val row = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER
            setPadding(dpHome(10), dpHome(4), dpHome(10), dpHome(4))
        }
        card.addView(row)

        fun metric(
            title: String,
            idValue: Int,
            bind: (TextView) -> Unit,
        ): View {
            val box = LinearLayout(this).apply {
                orientation = LinearLayout.VERTICAL
                gravity = Gravity.CENTER
            }
            box.addView(uiText(title, 8.5f, palette.textMuted, gravity = Gravity.CENTER))
            val value = uiText("—", 11f, palette.textPrimary, bold = true, gravity = Gravity.CENTER).apply {
                id = idValue
                maxLines = 1
            }
            box.addView(value)
            bind(value)
            return box
        }

        row.addView(
            metric("حجم باقی‌مانده", R.id.bluevpn_remaining_volume) {
                remainingVolume = it
            },
            LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.MATCH_PARENT, 1f),
        )
        row.addView(
            View(this).apply { setBackgroundColor(palette.stroke) },
            LinearLayout.LayoutParams(dpHome(1), dpHome(28)),
        )
        row.addView(
            metric("زمان باقی‌مانده", R.id.bluevpn_remaining_time) {
                remainingTime = it
            },
            LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.MATCH_PARENT, 1f),
        )
        return card
    }

    private fun createLiveConnectionMetrics(): View {
        val row = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER
            layoutDirection = View.LAYOUT_DIRECTION_RTL
            clipChildren = false
            clipToPadding = false
        }

        fun metricCard(
            icon: String,
            label: String,
            valueId: Int,
            iconColor: Int,
            bind: (TextView, TextView) -> Unit,
        ): FrameLayout {
            // Telemetry is one visual strip, not three actionable cards. Keeping
            // borders/backgrounds here made Free and Premium homes look boxed-in
            // and the faint light-theme strokes resembled broken separators.
            val card = FrameLayout(this).apply {
                setBackgroundColor(Color.TRANSPARENT)
                elevation = 0f
            }
            val inner = LinearLayout(this).apply {
                orientation = LinearLayout.VERTICAL
                gravity = Gravity.CENTER
                setPadding(dpHome(6), dpHome(5), dpHome(6), dpHome(5))
            }
            card.addView(
                inner,
                FrameLayout.LayoutParams(
                    ViewGroup.LayoutParams.MATCH_PARENT,
                    ViewGroup.LayoutParams.MATCH_PARENT,
                ),
            )
            val header = LinearLayout(this).apply {
                orientation = LinearLayout.HORIZONTAL
                gravity = Gravity.CENTER
            }
            header.addView(
                uiText(icon, 11.5f, iconColor, bold = true, gravity = Gravity.CENTER),
                LinearLayout.LayoutParams(dpHome(18), dpHome(18)),
            )
            val labelView = uiText(
                label,
                7.8f,
                palette.textMuted,
                bold = true,
                gravity = Gravity.CENTER,
            ).apply { maxLines = 1 }
            header.addView(labelView)
            inner.addView(header)
            val value = uiText(
                "—",
                11.5f,
                palette.textPrimary,
                bold = true,
                gravity = Gravity.CENTER,
            ).apply {
                id = valueId
                maxLines = 1
            }
            inner.addView(value)
            bind(value, labelView)
            return card
        }

        fun addMetric(card: View, start: Int = 0, end: Int = 0) {
            row.addView(
                card,
                LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.MATCH_PARENT, 1f).apply {
                    marginStart = dpHome(start)
                    marginEnd = dpHome(end)
                },
            )
        }

        addMetric(
            metricCard(
                "↑",
                "آپلود",
                R.id.bluevpn_upload_speed,
                Color.parseColor("#2ECF91"),
            ) { value, _ -> uploadSpeed = value },
            end = 3,
        )
        val durationCard = metricCard(
            "◷",
            "مدت اتصال",
            R.id.bluevpn_duration_value,
            palette.accent,
        ) { value, labelView ->
            durationValue = value
            durationMetricLabel = labelView
            freeTimerBadge = value
        }.apply {
            contentDescription = "زمان باقی‌مانده؛ برای دریافت زمان هدیه لمس کنید"
            isClickable = true
            isFocusable = true
            BlueVpnUiGuard.bind(this, intervalMs = 900L) {
                if (!BlueVpnEntitlement.resolveUi(this@BlueVpnHomeActivity).isFree) {
                    return@bind
                }
                BlueVpnTapsellManager.showRewarded(
                    activity = this@BlueVpnHomeActivity,
                    onRewarded = { grantedMinutes ->
                        updateFreeTimerBadge()
                        Toast.makeText(
                            this@BlueVpnHomeActivity,
                            "$grantedMinutes دقیقه به زمان رایگان اضافه شد",
                            Toast.LENGTH_LONG,
                        ).show()
                    },
                    onUnavailable = {
                        Toast.makeText(
                            this@BlueVpnHomeActivity,
                            "فعلاً تبلیغ جایزه‌ای در دسترس نیست",
                            Toast.LENGTH_SHORT,
                        ).show()
                    },
                )
            }
        }
        addMetric(
            durationCard,
            start = 3,
            end = 3,
        )
        addMetric(
            metricCard(
                "↓",
                "دانلود",
                R.id.bluevpn_download_speed,
                Color.parseColor("#FF6174"),
            ) { value, _ -> downloadSpeed = value },
            start = 3,
        )
        return row
    }

    private fun createCompatibilityFields(): View {
        val hidden = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            visibility = View.GONE
        }
        fun hiddenText(idValue: Int): TextView = TextView(this).apply { id = idValue }

        locationValue = hiddenText(R.id.bluevpn_location_value)
        modeValue = hiddenText(R.id.bluevpn_mode_value)
        activeRoutesValue = hiddenText(R.id.bluevpn_active_routes_value)
        historyValue = hiddenText(R.id.bluevpn_history_value)
        aiSummaryValue = hiddenText(R.id.bluevpn_ai_summary)
        pingValue = hiddenText(R.id.bluevpn_ping_value)
        balancedModeButton = MaterialButton(this).apply { id = R.id.bluevpn_mode_balanced }
        gamingModeButton = MaterialButton(this).apply { id = R.id.bluevpn_mode_gaming }
        streamingModeButton = MaterialButton(this).apply { id = R.id.bluevpn_mode_streaming }

        listOf(
            locationValue,
            modeValue,
            activeRoutesValue,
            historyValue,
            aiSummaryValue,
            pingValue,
            balancedModeButton,
            gamingModeButton,
            streamingModeButton,
            View(this).apply { id = R.id.bluevpn_action_servers },
            MaterialButton(this).apply { id = R.id.bluevpn_refresh_subscription },
        ).forEach { hidden.addView(it) }
        return hidden
    }

    private fun createFloatingStatCard(
        label: String,
        primaryValue: String,
        secondaryValue: String,
        bind: (TextView, TextView) -> Unit,
    ): MaterialCardView {
        val card = glassCard(
            21,
            Color.parseColor("#315D97"),
            Color.argb(188, 10, 36, 74),
        ).apply { elevation = dpHome(5).toFloat() }
        val frostLayer = View(this).apply {
            background = roundedGradient(
                intArrayOf(
                    Color.argb(105, 105, 166, 255),
                    Color.argb(40, 65, 64, 160),
                    Color.argb(22, 20, 46, 88),
                ),
                21,
            )
            alpha = 0.42f
            applyOptionalFrostBlur(this)
        }
        card.addView(
            frostLayer,
            FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT,
            ),
        )
        val column = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER
            setPadding(dpHome(7), dpHome(7), dpHome(7), dpHome(7))
        }
        card.addView(
            column,
            FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT,
            ),
        )
        column.addView(uiText(label, 8.5f, Color.parseColor("#8FAED6"), gravity = Gravity.CENTER))
        val primary = uiText(primaryValue, 12f, Color.WHITE, bold = true, gravity = Gravity.CENTER)
        val secondary = uiText(secondaryValue, 7.5f, Color.parseColor("#85A2CA"), gravity = Gravity.CENTER)
        column.addView(primary)
        column.addView(secondary)
        bind(primary, secondary)
        return card
    }

    private fun applyOptionalFrostBlur(view: View) {
        if (
            Build.VERSION.SDK_INT >= Build.VERSION_CODES.S &&
            !BlueVpnPerformance.isLowEnd(this)
        ) {
            val radius = dpHome(7).toFloat()
            view.setRenderEffect(
                RenderEffect.createBlurEffect(
                    radius,
                    radius,
                    Shader.TileMode.CLAMP,
                ),
            )
        }
    }

    private fun modeButton(label: String, idValue: Int): MaterialButton =
        MaterialButton(this).apply {
            id = idValue
            text = label
            textSize = 10f
            isAllCaps = false
            cornerRadius = dpHome(16)
            insetTop = 0
            insetBottom = 0
            elevation = 0f
        }

    private fun actionButton(label: String, idValue: Int): AppCompatTextView =
        AppCompatTextView(this).apply {
            id = idValue
            text = label
            textSize = 10.5f
            setTextColor(Color.parseColor("#E3EDFF"))
            typeface = Typeface.DEFAULT_BOLD
            gravity = Gravity.CENTER
            isClickable = true
            isFocusable = true
            background = roundedGradient(
                intArrayOf(Color.argb(235, 25, 25, 31), Color.argb(225, 14, 14, 19)),
                19,
                Color.parseColor("#2B2D35"),
            )
            elevation = dpHome(2).toFloat()
        }

    private fun uiText(
        value: String,
        sizeSp: Float,
        color: Int,
        bold: Boolean = false,
        gravity: Int = Gravity.START or Gravity.CENTER_VERTICAL,
    ): AppCompatTextView = AppCompatTextView(this).apply {
        text = value
        textSize = sizeSp
        setTextColor(color)
        this.gravity = gravity
        includeFontPadding = false
        if (bold) typeface = Typeface.DEFAULT_BOLD
    }

    private fun glassCard(
        radiusDp: Int,
        stroke: Int,
        fill: Int,
    ): MaterialCardView = MaterialCardView(this).apply {
        radius = dpHome(radiusDp).toFloat()
        setCardBackgroundColor(fill)
        cardElevation = 0f
        strokeWidth = dpHome(1)
        strokeColor = stroke
        preventCornerOverlap = true
        useCompatPadding = false
    }

    private fun roundedGradient(
        colors: IntArray,
        radiusDp: Int,
        stroke: Int? = null,
    ): GradientDrawable = GradientDrawable(
        GradientDrawable.Orientation.TL_BR,
        colors,
    ).apply {
        cornerRadius = dpHome(radiusDp).toFloat()
        if (stroke != null) setStroke(dpHome(1), stroke)
    }

    private fun circleDrawable(color: Int): GradientDrawable =
        GradientDrawable().apply {
            shape = GradientDrawable.OVAL
            setColor(color)
        }

    private fun radialHaloDrawable(color: Int, alpha: Int): GradientDrawable =
        GradientDrawable(
            GradientDrawable.Orientation.TOP_BOTTOM,
            intArrayOf(
                Color.argb(alpha, Color.red(color), Color.green(color), Color.blue(color)),
                Color.argb(20, Color.red(color), Color.green(color), Color.blue(color)),
                Color.TRANSPARENT,
            ),
        ).apply {
            shape = GradientDrawable.OVAL
            gradientType = GradientDrawable.RADIAL_GRADIENT
            gradientRadius = dpHome(118).toFloat()
        }

    private fun applyOrbVisual(state: OrbVisualState) {
        orbVisualState = state
        if (!::connectButton.isInitialized) return

        val knobPalette = when (state) {
            OrbVisualState.IDLE -> intArrayOf(
                if (palette.dark) Color.parseColor("#5A5D66") else Color.parseColor("#D4DAE7"),
                if (palette.dark) Color.parseColor("#3F4149") else Color.parseColor("#B8C2D5"),
            )
            OrbVisualState.CONNECTING -> intArrayOf(
                Color.parseColor("#6E91FF"),
                palette.accentStrong,
            )
            OrbVisualState.CONNECTED -> intArrayOf(
                palette.accent,
                palette.accentStrong,
            )
            OrbVisualState.ERROR -> intArrayOf(
                palette.danger,
                Color.parseColor("#96374E"),
            )
        }
        val accent = when (state) {
            OrbVisualState.CONNECTED -> palette.accent
            OrbVisualState.ERROR -> palette.danger
            OrbVisualState.CONNECTING -> Color.parseColor("#7394FF")
            OrbVisualState.IDLE -> palette.stroke
        }

        connectButton.text = ""
        connectButton.background = GradientDrawable(
            GradientDrawable.Orientation.TL_BR,
            knobPalette,
        ).apply {
            shape = GradientDrawable.OVAL
            setStroke(dpHome(1), if (palette.dark) Color.argb(150, 255, 255, 255) else Color.argb(80, 20, 30, 55))
        }
        (connectButton as? PowerGlyphView)?.setGlyphColor(
            if (state == OrbVisualState.IDLE && !palette.dark) palette.textSecondary else Color.WHITE
        )
        connectButton.alpha = if (connectButton.isEnabled) 1f else 0.72f
        connectTrack.setCardBackgroundColor(
            when (state) {
                OrbVisualState.CONNECTED -> if (palette.dark) Color.parseColor("#111A2F") else Color.parseColor("#E4ECFF")
                OrbVisualState.ERROR -> if (palette.dark) Color.parseColor("#241319") else Color.parseColor("#FCE8EC")
                else -> palette.surfaceStrong
            }
        )
        connectTrack.strokeColor = accent
        if (::connectHint.isInitialized) {
            connectHint.setTextColor(palette.textSecondary)
            if (state == OrbVisualState.CONNECTED) {
                connectHint.setPadding(dpHome(12), 0, dpHome(98), 0)
            } else {
                connectHint.setPadding(dpHome(98), 0, dpHome(12), 0)
            }
        }
        orbHaloOuter.background = radialHaloDrawable(accent, if (state == OrbVisualState.CONNECTED) 48 else 30)
        orbHaloInner.background = roundedGradient(
            intArrayOf(Color.argb(38, Color.red(accent), Color.green(accent), Color.blue(accent)), Color.TRANSPARENT),
            58,
        )

        connectTrack.post {
            val target = if (state == OrbVisualState.CONNECTED) knobTravel() else 0f
            connectButton.animate()
                .translationX(target)
                .setDuration(240L)
                .setInterpolator(AccelerateDecelerateInterpolator())
                .start()
        }

        setOrbPulseEnabled(state == OrbVisualState.CONNECTING)
    }

    private fun knobTravel(): Float =
        (connectTrack.width - connectButton.width - dpHome(16))
            .coerceAtLeast(0)
            .toFloat()

    private fun handleConnectionGesture(event: MotionEvent): Boolean {
        if (!connectButton.isEnabled) return true
        when (event.actionMasked) {
            MotionEvent.ACTION_DOWN -> {
                userInteractedAt = SystemClock.elapsedRealtime()
                dragStartRawX = event.rawX
                dragStartTranslation = connectButton.translationX
                dragMoved = false
                connectButton.animate().cancel()
                return true
            }
            MotionEvent.ACTION_MOVE -> {
                val delta = event.rawX - dragStartRawX
                if (kotlin.math.abs(delta) > dpHome(5)) dragMoved = true
                connectButton.translationX = (dragStartTranslation + delta)
                    .coerceIn(0f, knobTravel())
                return true
            }
            MotionEvent.ACTION_CANCEL -> {
                applyOrbVisual(orbVisualState)
                return true
            }
            MotionEvent.ACTION_UP -> {
                val travel = knobTravel()
                val wasConnected = orbVisualState == OrbVisualState.CONNECTED
                if (!dragMoved || travel <= 0f) {
                    toggleConnection()
                } else {
                    val wantsConnected = connectButton.translationX >= travel * 0.48f
                    if (wantsConnected != wasConnected) {
                        toggleConnection()
                    } else {
                        applyOrbVisual(orbVisualState)
                    }
                }
                connectTrack.performClick()
                return true
            }
        }
        return false
    }

    private fun updateConnectLabel(value: String) {
        if (::connectButton.isInitialized) connectButton.text = ""
        if (::connectHint.isInitialized) connectHint.text = value
    }

    private fun setOrbPulseEnabled(enabled: Boolean) {
        if (!enabled || BlueVpnPerformance.isLowEnd(this)) {
            orbPulseAnimator?.cancel()
            orbPulseAnimator = null
            if (::orbHaloOuter.isInitialized) {
                orbHaloOuter.scaleX = 1f
                orbHaloOuter.scaleY = 1f
                orbHaloOuter.alpha = 0.42f
            }
            if (::orbHaloInner.isInitialized) {
                orbHaloInner.scaleX = 1f
                orbHaloInner.scaleY = 1f
                orbHaloInner.alpha = 0.55f
            }
            return
        }
        if (orbPulseAnimator?.isRunning == true) return

        orbPulseAnimator = ValueAnimator.ofFloat(0f, 1f).apply {
            duration = 1_350L
            repeatCount = ValueAnimator.INFINITE
            repeatMode = ValueAnimator.REVERSE
            interpolator = AccelerateDecelerateInterpolator()
            addUpdateListener { animator ->
                val value = animator.animatedValue as Float
                val outerScale = 0.94f + (0.13f * value)
                val innerScale = 0.98f + (0.05f * value)
                orbHaloOuter.scaleX = outerScale
                orbHaloOuter.scaleY = outerScale
                orbHaloOuter.alpha = 0.28f + (0.25f * value)
                orbHaloInner.scaleX = innerScale
                orbHaloInner.scaleY = innerScale
                orbHaloInner.alpha = 0.42f + (0.24f * value)
            }
            start()
        }
    }

    private fun registerCoreFailureReceiver() {
        if (coreFailureReceiverRegistered) return
        ContextCompat.registerReceiver(
            this,
            coreFailureReceiver,
            IntentFilter(AppConfig.BROADCAST_ACTION_ACTIVITY),
            Utils.receiverFlags(),
        )
        coreFailureReceiverRegistered = true
    }

    private fun ensureNotificationPermission() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU) return
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED) return
        notificationPermissionLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        palette = BlueVpnTheme.palette(this)
        themeDarkAtCreate = palette.dark
        if (BlueVpnTheme.isTransitionRecent(this)) {
            themeConnectionGraceUntil = SystemClock.elapsedRealtime() + 12_000L
            connectionVerified = BlueVpnPreferences.connectedAt(this) > 0L
            // A visual-only restart must not repeat startup optimization or
            // touch the already running VPN service.
            startupOptimizationShown = true
        }
        window.setBackgroundDrawable(ColorDrawable(palette.background))
        BlueVpnTheme.applySystemBars(this)
        setContentView(createScreen())
        ensureNotificationPermission()
        BlueVpnSupportNotifications.schedule(this)
        if (BlueVpnUiGuard.consumeRecoveryNotice(this)) {
            val freeWarp =
                BlueVpnAccountManager.isFreeMode(this) &&
                    BlueVpnAccountManager.warpFreeEnabled(this)
            val transportAlive =
                CoreServiceManager.isRunning() &&
                    (!freeWarp || BlueVpnWarpEngine.isRunning())
            val hadConnectedSession = BlueVpnPreferences.connectedAt(this) > 0L

            if (transportAlive) {
                // Activity/process recovery is not a VPN failure. Preserve both
                // Premium and Free/WARP when their actual transports are alive.
                recoveryCleanupRequired = false
                connectionVerified = hadConnectedSession
                Toast.makeText(
                    this,
                    "BlueVPN بازیابی شد؛ اتصال فعال بدون قطع‌شدن حفظ شد",
                    Toast.LENGTH_LONG,
                ).show()
            } else {
                recoveryCleanupRequired = hadConnectedSession
                connectionVerified = false
                BlueVpnPreferences.clearConnected(this)
                Toast.makeText(
                    this,
                    "BlueVPN بازیابی شد؛ مسیر قبلی واقعاً متوقف شده بود و دوباره بررسی می‌شود",
                    Toast.LENGTH_LONG,
                ).show()
            }
        }

        connectButton = findViewById(R.id.bluevpn_connect_button)
        statusText = findViewById(R.id.bluevpn_status_text)
        statusCaption = findViewById(R.id.bluevpn_status_caption)
        statusDot = findViewById(R.id.bluevpn_status_dot)
        serverName = findViewById(R.id.bluevpn_server_name)
        serverMeta = findViewById(R.id.bluevpn_server_meta)
        subscriptionSummary = findViewById(R.id.bluevpn_subscription_summary)
        pingValue = findViewById(R.id.bluevpn_ping_value)
        durationValue = findViewById(R.id.bluevpn_duration_value)
        locationValue = findViewById(R.id.bluevpn_location_value)
        downloadSpeed = findViewById(R.id.bluevpn_download_speed)
        uploadSpeed = findViewById(R.id.bluevpn_upload_speed)
        remainingVolume = findViewById(R.id.bluevpn_remaining_volume)
        remainingTime = findViewById(R.id.bluevpn_remaining_time)
        qualityValue = findViewById(R.id.bluevpn_quality_value)
        modeValue = findViewById(R.id.bluevpn_mode_value)
        activeRoutesValue = findViewById(
            R.id.bluevpn_active_routes_value
        )
        historyValue = findViewById(R.id.bluevpn_history_value)
        aiSummaryValue = findViewById(R.id.bluevpn_ai_summary)
        balancedModeButton = findViewById(
            R.id.bluevpn_mode_balanced
        )
        gamingModeButton = findViewById(
            R.id.bluevpn_mode_gaming
        )
        streamingModeButton = findViewById(
            R.id.bluevpn_mode_streaming
        )

        findViewById<TextView>(
            R.id.bluevpn_premium_badge
        ).text = BuildConfig.VERSION_NAME

        connectTrack.setOnTouchListener { _, event ->
            handleConnectionGesture(event)
        }
        connectButton.setOnTouchListener { _, event ->
            handleConnectionGesture(event)
        }
        BlueVpnUiGuard.bind(connectButton, intervalMs = 700L) {
            toggleConnection()
        }

        BlueVpnUiGuard.bind(findViewById<View>(R.id.bluevpn_server_card), intervalMs = 260L) {
            openServers()
        }
        BlueVpnUiGuard.bind(findViewById<View>(R.id.bluevpn_action_servers), intervalMs = 260L) {
            openServers()
        }
        BlueVpnUiGuard.bind(findViewById<View>(R.id.bluevpn_action_subscription), intervalMs = 260L) {
            openAccount()
        }
        BlueVpnUiGuard.bind(findViewById<View>(R.id.bluevpn_action_settings), intervalMs = 220L) {
            openSettings()
        }
        findViewById<MaterialButton>(
            R.id.bluevpn_refresh_subscription
        ).apply {
            text = "همگام‌سازی هنگام اجرای برنامه"
            isEnabled = false
            isClickable = false
            alpha = 1f
        }

        mainViewModel.isRunning.observe(this) { running ->
            val active = running == true
            if (active) {
                BlueVpnRuntimeGate.markConnectionActive(this)
            }

            if (terminalFailureStopping) {
                // A terminal failover error has already been rendered. The daemon
                // may still report RUNNING until its asynchronous STOP_SUCCESS /
                // NOT_RUNNING broadcast arrives. Never reinterpret that stale
                // RUNNING state as an existing session that needs verification;
                // doing so reopened the full-screen "connecting" UI after an
                // explicit "location unavailable" result.
                if (!active) {
                    terminalFailureStopping = false
                    BlueVpnRuntimeGate.endConnection(this)
                    connectButton.isEnabled = true
                    updateConnectLabel("تلاش دوباره")
                    reconcileDeferredEntitlementIfIdle()
                } else {
                    hideConnectingOverlay()
                    connectButton.isEnabled = false
                    updateConnectLabel("در حال توقف")
                    applyOrbVisual(OrbVisualState.ERROR)
                    statusText.text = "لوکیشن در دسترس نیست"
                    statusCaption.visibility = View.VISIBLE
                    statusCaption.text = terminalFailureReason.ifBlank {
                        "این لوکیشن فعلاً پاسخ نداد؛ بعداً دوباره امتحان کنید"
                    }
                }
                return@observe
            }

            if (userDisconnecting) {
                if (active) {
                    LauncherManager.stopService(this)
                } else {
                    userDisconnecting = false
                    disconnectRetry.reset()
                    handler.removeCallbacks(
                        disconnectRetry
                    )
                    BlueVpnRuntimeGate.endConnection(this)
                    renderConnectionState(false)
                    reconcileDeferredEntitlementIfIdle()
                }
                return@observe
            }

            if (freeStoryGateActive) {
                // Advertising is presentation-only. Never downgrade/clear a
                // verified VPN session while a story is visible.
                if (!active) {
                    freeStoryGate?.release()
                    freeStoryGate = null
                    freeStoryGateActive = false
                }
            }

            when {
                active && failoverActive && waitingForCoreStop -> {
                    // We are still observing the old CoreVpnService. Never verify
                    // the next GUID against traffic from the previous route.
                    if (premiumInstantUiEnabled()) {
                        renderPremiumInstantConnectedUi()
                    } else {
                        updateConnectLabel("لغو اتصال")
                        connectButton.isEnabled = true
                        statusText.text = "در حال تغییر اتصال"
                        statusCaption.text = "در انتظار توقف کامل اتصال قبلی"
                    }
                }

                !active && failoverActive && waitingForCoreStop -> {
                    // v2rayNG confirmed that its service/core is fully stopped.
                    // Only now select/start the next exact GUID. This replaces the
                    // old fixed 90 ms restart race.
                    waitingForCoreStop = false
                    coreStopRetryCount = 0
                    handler.removeCallbacks(coreStopTimeout)
                    val guid = attemptedGuid
                    if (guid.isNotBlank()) {
                        // v2rayNG 2.3.5 stops its core asynchronously. Keep a
                        // short drain window before starting the next hidden route.
                        handler.postDelayed({
                            if (failoverActive && attemptedGuid == guid && !userDisconnecting) {
                                startExactCandidateCore(guid)
                            }
                        }, 650L)
                    }
                }

                active && failoverActive -> {
                    // RUNNING proves only that Xray/VpnService started. BlueVPN
                    // must not expose CONNECTED until a real request traverses the
                    // selected tunnel. This prevents "connected but no internet"
                    // routes from winning merely because the core started.
                    handler.removeCallbacks(attemptTimeout)
                    renderVerifyingState()
                    scheduleConnectionVerification()
                }

                active -> {
                    // Activity recreation can observe an already-running core.
                    // Re-verify end-to-end Internet instead of promoting RUNNING
                    // directly to a healthy BlueVPN connection.
                    if (!connectionVerified && !existingSessionCheckInProgress) {
                        verifyExistingRunningSession(preserveServiceOnFailure = true)
                    } else if (connectionVerified) {
                        renderConnectionState(true)
                    }
                }

                !failoverActive -> {
                    connectionVerified = false
                    existingSessionCheckInProgress = false
                    BlueVpnPreferences.clearConnected(this)
                    BlueVpnRuntimeGate.endConnection(this)
                    renderConnectionState(false)

                    if (
                        recoveryCleanupRequired &&
                        pendingConnectionRequest &&
                        !userDisconnecting &&
                        !isFinishing &&
                        !isDestroyed
                    ) {
                        recoveryCleanupRequired = false
                        pendingConnectionRequest = false
                        handler.postDelayed({ beginSmartConnection() }, 180L)
                    } else {
                        reconcileDeferredEntitlementIfIdle()
                    }
                }
            }
        }


        mainViewModel.updateTestResultAction.observe(this) { result ->
            // Ping results update the existing pool; they must not trigger a
            // subscription rebuild or candidate warm-up while connecting.
            BlueVpnLocationUtil.invalidateCache()

            val upstreamDelay = parseV2rayNgDelayMs(result)
            if (upstreamDelay != null && mainViewModel.isRunning.value == true) {
                lastVerifiedLatency = upstreamDelay
                // Stock v2rayNG real-ping is authoritative proof that the active
                // candidate is alive. Promote it immediately; the HTTP probe is
                // only a fallback for routes whose upstream ping is inconclusive.
                if (failoverActive && isExactAttemptRunning()) {
                    completeFailover(upstreamDelay)
                    return@observe
                }
                if (existingSessionCheckInProgress && !connectionVerified) {
                    completeExistingSessionVerification(upstreamDelay)
                    return@observe
                }
                if (connectionVerified) recordCurrentConnection(upstreamDelay)
            }

            if (startupOptimizationActive) {
                finishStartupOptimization()
            } else {
                handlePingResult()
            }
        }

        mainViewModel.updateListAction.observe(this) {
            // v2rayNG list notifications are frequent during import/testing.
            // Keep this observer lightweight; the owner that changed the
            // subscription explicitly performs one warm-up when needed.
            BlueVpnLocationUtil.invalidateCache()
            requestDashboardRefresh(force = false)

            if (
                startupOptimizationActive &&
                !startupServerTestStarted
            ) {
                startStartupServerTest()
            }
        }

        // Match stock v2rayNG MainActivity ordering: register the service
        // broadcast receiver and initialize core assets during onCreate, before
        // the user can press Connect. Delaying receiver registration until the
        // first frame created a race where START_SUCCESS could be missed.
        registerCoreFailureReceiver()
        mainViewModel.startListenBroadcast()
        mainViewModel.initAssets(assets)

        // Ads must never run before the stock v2rayNG receiver/assets setup.
        // Keep monetization outside the critical VPN startup path.
        handler.postDelayed({
            if (!isFinishing && !isDestroyed) {
                BlueVpnTapsellManager.warmUp(this)
            }
        }, BlueVpnPerformance.adSdkWarmupDelayMs(this))

        enforceReliableVpnSettings()

        // First frame is always local-only. One coordinated background pipeline
        // owns account/free config, MMKV decoding and cloud location sync so
        // onCreate/onResume can never start the same work twice.
        refreshSubscriptionInfo(force = false)
        requestDashboardRefresh(force = true)
        scheduleStartupPipeline()
    }

    override fun onStart() {
        super.onStart()
        navigationLocked = false
        handler.removeCallbacks(statsTicker)
        handler.post(statsTicker)
        handler.removeCallbacks(freeSessionTicker)
        handler.post(freeSessionTicker)
        if (::connectButton.isInitialized) {
            applyOrbVisual(orbVisualState)
        }
        if (::connectingOverlay.isInitialized && connectingOverlay.visibility == View.VISIBLE) {
            connectingGlobe.start()
        }
        handler.removeCallbacks(delayedAdsStart)
        handler.postDelayed(
            delayedAdsStart,
            BlueVpnPerformance.bannerDelayMs(this),
        )
    }

    override fun onStop() {
        backgroundedAtElapsed = SystemClock.elapsedRealtime()
        if (freeStoryGateActive && !isChangingConfigurations && !isFinishing) {
            // The ad may be dismissed when Home/Recent Apps is used, but the
            // already-verified VPN session must stay connected.
            freeStoryGate?.abort()
        }
        handler.removeCallbacks(delayedAdsStart)
        if (::adsCarousel.isInitialized) adsCarousel.stop()
        handler.removeCallbacks(statsTicker)
        handler.removeCallbacks(freeSessionTicker)
        setOrbPulseEnabled(false)
        if (::connectingGlobe.isInitialized) connectingGlobe.stop()
        super.onStop()
    }

    override fun onTrimMemory(level: Int) {
        if (level >= ComponentCallbacks2.TRIM_MEMORY_RUNNING_LOW) {
            if (::adsCarousel.isInitialized) adsCarousel.trimMemory()
            setOrbPulseEnabled(false)
            BlueVpnLocationUtil.invalidateResolvedCache()
        }
        super.onTrimMemory(level)
    }

    override fun onDestroy() {
        handler.removeCallbacks(attemptTimeout)
        handler.removeCallbacks(coreStopTimeout)
        handler.removeCallbacks(requestPing)
        handler.removeCallbacks(startupProgressTicker)
        handler.removeCallbacks(startupOptimizationTimeout)
        handler.removeCallbacks(disconnectRetry)
        handler.removeCallbacks(freeSessionTicker)
        handler.removeCallbacks(networkSweepTicker)
        handler.removeCallbacks(delayedAdsStart)
        handler.removeCallbacks(navigationUnlock)
        if (isFinishing && mainViewModel.isRunning.value != true) {
            BlueVpnRuntimeGate.endConnection(this)
        }
        startupDialog?.dismiss()
        startupDialog = null
        freeStoryGate?.release()
        freeStoryGate = null
        freeStoryGateActive = false
        setOrbPulseEnabled(false)
        if (::connectingGlobe.isInitialized) connectingGlobe.stop()
        if (::adsCarousel.isInitialized) adsCarousel.release()
        if (coreFailureReceiverRegistered) {
            runCatching { unregisterReceiver(coreFailureReceiver) }
            coreFailureReceiverRegistered = false
        }
        super.onDestroy()
    }

    override fun onResume() {
        super.onResume()
        BlueVpnNetworkRecoveryManager.start(applicationContext)
        BlueVpnTheme.applySystemBars(this)
        if (BlueVpnTheme.isDark(this) != themeDarkAtCreate) {
            window.setWindowAnimations(0)
            recreate()
            overridePendingTransition(0, 0)
            return
        }
        val initialResume = firstHomeResume
        firstHomeResume = false
        navigationLocked = false
        BlueVpnUpdateManager.resumePendingInstall(this)
        applyEntitlementPresentation(BlueVpnEntitlement.resolveUi(this))
        if (
            !BlueVpnAccountManager.premiumEntitlementActive(this) &&
            BlueVpnAccountManager.freeAccessEnabled(this) &&
            !BlueVpnAccountManager.warpFreeEnabled(this)
        ) {
            // Pool-only Free must proactively materialize the curated
            // subscription so background AI can test it before the user taps
            // Connect. WARP being disabled is not a reason to disable Free.
            prepareFreePlanAccess(force = false)
        }
        BlueVpnBackgroundReliability.observeAndMaybeOptimize(this)
        BlueVpnAccountManager.enforceFreeSession(this)
        if (BlueVpnAccountManager.consumeFreeExpiredNotice(this)) {
            Toast.makeText(
                this,
                "زمان اتصال رایگان پایان یافت؛ برای اتصال مجدد دکمه اتصال را بزنید",
                Toast.LENGTH_LONG,
            ).show()
        }

        if (!updateCheckScheduled) {
            updateCheckScheduled = true
            handler.postDelayed({
                updateCheckScheduled = false
                if (!isFinishing && !isDestroyed) {
                    BlueVpnUpdateManager.check(this)
                }
            }, BlueVpnPerformance.updateCheckDelayMs(this))
        }

        scheduleStartupPipeline()
        // onCreate already rendered the local entitlement/dashboard and owns the
        // startup pipeline. Android immediately calls onResume after onCreate;
        // repeating account/UI refresh work here doubled MMKV/JSON reads during
        // the first visible frame. Only later foreground resumes need this path.
        if (!initialResume) {
            val backgroundDuration = if (backgroundedAtElapsed > 0L) {
                SystemClock.elapsedRealtime() - backgroundedAtElapsed
            } else 0L
            backgroundedAtElapsed = 0L
            if (
                backgroundDuration >= 15_000L &&
                mainViewModel.isRunning.value == true &&
                !failoverActive &&
                !userDisconnecting
            ) {
                // Some Android vendors keep VpnService/Xray in RUNNING while
                // the data plane freezes during Doze. Re-prove real egress after
                // wake and permit one clean restart after repeated probe failure.
                connectionVerified = false
                handler.postDelayed({
                    if (!isFinishing && !isDestroyed && mainViewModel.isRunning.value == true) {
                        verifyExistingRunningSession(
                            preserveServiceOnFailure = true,
                            forceRecoveryOnFailure = true,
                        )
                    }
                }, 350L)
            }
            if (!BlueVpnAccountManager.premiumEntitlementActive(this)) {
                val policyNow = SystemClock.elapsedRealtime()
                if (policyNow - lastForegroundFreePolicySyncAt > 30_000L) {
                    lastForegroundFreePolicySyncAt = policyNow
                    lifecycleScope.launch(Dispatchers.IO) {
                        val refreshed = BlueVpnAccountManager
                            .refreshFreePolicy(this@BlueVpnHomeActivity, force = true)
                            .isSuccess
                        withContext(Dispatchers.Main) {
                            if (!refreshed || isFinishing || isDestroyed) return@withContext
                            BlueVpnAccountManager.enforceFreeSession(this@BlueVpnHomeActivity)
                            requestDashboardRefresh(force = true)
                            refreshSubscriptionInfo(force = true)
                            updateFreeTimerBadge()
                        }
                    }
                }
            }
            if (BlueVpnAccountManager.hasSession(this)) {
                val now = SystemClock.elapsedRealtime()
                if (now - lastForegroundAccountSyncAt > 120_000L) {
                    lastForegroundAccountSyncAt = now
                    // Foreground resume is cache-first. Do not poll providers or
                    // rebuild subscriptions while a tunnel is connecting/running.
                    handler.postDelayed({
                        if (!isFinishing && !isDestroyed &&
                            !failoverActive && !userDisconnecting &&
                            mainViewModel.isRunning.value != true
                        ) {
                            syncManagedAccount(force = false)
                        }
                    }, 450L)
                }
            }

            handler.post {
                requestDashboardRefresh(force = false)
                refreshSubscriptionInfo(force = false)
            }
        }
    }

private fun scheduleStartupPipeline() {
    if (startupPipelineStarted || isFinishing || isDestroyed) return
    startupPipelineStarted = true
    window.decorView.post {
        if (isFinishing || isDestroyed) return@post
        lifecycleScope.launch(Dispatchers.IO) {
            val hadSession = BlueVpnAccountManager.hasSession(this@BlueVpnHomeActivity)
            val premiumAtLaunch = BlueVpnAccountManager.premiumEntitlementActive(this@BlueVpnHomeActivity)
            // Free policy (enabled/session minutes/sources) is server-authored and
            // must refresh even when a healthy Free pool is already installed.
            // Previously a ready pool skipped config fetch entirely, so changing
            // 60 -> 30 minutes in WordPress could remain stale indefinitely.
            if (!premiumAtLaunch) {
                BlueVpnAccountManager.refreshFreePolicy(
                    this@BlueVpnHomeActivity,
                    force = true,
                ).getOrNull()
            }
            // Only a real Premium entitlement may skip Free preparation. An
            // authenticated account with no active subscription is still a Free
            // account and must receive the same Free pool as a guest.
            val needsFreeBootstrap = !premiumAtLaunch && (
                !BlueVpnAccountManager.freeAccessEnabled(this@BlueVpnHomeActivity) ||
                    !BlueVpnAccountManager.hasInstalledFreeServers(this@BlueVpnHomeActivity)
                )
            val preparedFree = if (needsFreeBootstrap) {
                BlueVpnAccountManager.prepareFreeAccess(
                    this@BlueVpnHomeActivity,
                    force = false,
                ).getOrDefault(false)
            } else false

            withContext(Dispatchers.Main) {
                if (isFinishing || isDestroyed) return@withContext
                if (needsFreeBootstrap) {
                    // Refresh entitlement presentation even when pool installation
                    // failed: mobile config may still have changed UNAVAILABLE ->
                    // FREE, and the Connect button can retry preparation explicitly.
                    if (preparedFree) {
                        BlueVpnLocationUtil.invalidateCache()
                        mainViewModel.reloadServerList()
                    }
                    requestDashboardRefresh(force = true)
                    refreshSubscriptionInfo(force = false)
                }
                showWelcomeIfNeeded()
                scheduleIdleCandidateWarmup()
                if (hadSession && !startupOptimizationShown) {
                    handler.postDelayed({
                        if (!isFinishing && !isDestroyed) startStartupOptimization()
                    }, BlueVpnPerformance.accountSyncDelayMs(this@BlueVpnHomeActivity))
                }
            }
        }
    }
}

private fun scheduleIdleCandidateWarmup() {
    if (startupWarmupPosted || isFinishing || isDestroyed) return
    startupWarmupPosted = true
    handler.postDelayed({
        startupWarmupPosted = false
        if (isFinishing || isDestroyed || failoverActive || userDisconnecting) return@postDelayed
        val recentlyTouched = SystemClock.elapsedRealtime() - userInteractedAt < 4_000L
        if (recentlyTouched) {
            scheduleIdleCandidateWarmup()
            return@postDelayed
        }
        warmCandidatesThenRefresh(force = false)
        if (BlueVpnAccountManager.hasSession(this)) {
            lifecycleScope.launch(Dispatchers.IO) {
                BlueVpnLocationUtil.syncCloudLocations(
                    this@BlueVpnHomeActivity,
                    force = false,
                )
            }
        }
    }, BlueVpnPerformance.startupWarmupDelayMs(this))
}

private fun requestDashboardRefresh(force: Boolean = false) {
    dashboardForcePending = dashboardForcePending || force
    if (dashboardRefreshPosted || isFinishing || isDestroyed) return
    dashboardRefreshPosted = true
    handler.post {
        dashboardRefreshPosted = false
        if (isFinishing || isDestroyed) return@post
        val requestedForce = dashboardForcePending
        dashboardForcePending = false
        refreshDashboard(force = requestedForce)
    }
}

private fun setConnectionMode(
    mode: BlueVpnConnectionMode,
) {
    BlueVpnExperience.setMode(this, mode)
    refreshExperienceDashboard()

    Toast.makeText(
        this,
        "${mode.title} فعال شد",
        Toast.LENGTH_SHORT,
    ).show()
}

private fun refreshModeButtons() {
    val selected = BlueVpnExperience.mode(this)

    listOf(
        BlueVpnConnectionMode.BALANCED to balancedModeButton,
        BlueVpnConnectionMode.GAMING to gamingModeButton,
        BlueVpnConnectionMode.STREAMING to streamingModeButton,
    ).forEach { (mode, button) ->
        val active = selected == mode

        button.backgroundTintList =
            ColorStateList.valueOf(
                Color.parseColor(
                    if (active) "#315FD9" else "#19191F"
                )
            )
        button.strokeColor =
            ColorStateList.valueOf(
                Color.parseColor(
                    if (active) "#5D88F0" else "#2B2C34"
                )
            )
        button.setTextColor(
            Color.parseColor(
                if (active) "#FFFFFF" else "#8C8F9A"
            )
        )
    }

    modeValue.text = selected.title
}

private fun refreshExperienceDashboard(
    cachedCandidates: List<BlueVpnLocationUtil.Candidate>? = null,
) {
    if (
        !::qualityValue.isInitialized ||
        !::modeValue.isInitialized
    ) {
        return
    }
    // These fields are compatibility-only and live inside a GONE container in
    // the current unified UI. Do not spend CPU/MMKV/AI reads updating invisible
    // text on every dashboard refresh. If a future UI makes either area visible,
    // the original calculations automatically become active again.
    val compatibilityParentVisible =
        (aiSummaryValue.parent as? View)?.visibility == View.VISIBLE
    if (qualityValue.visibility != View.VISIBLE && !compatibilityParentVisible) {
        return
    }

    refreshModeButtons()

    val all = cachedCandidates ?: BlueVpnLocationUtil.cachedCandidates(this)
    val active = all.count {
        it.delay >= 0L &&
            !BlueVpnPreferences.isSessionInactive(
                this,
                it.guid,
            )
    }
    activeRoutesValue.text = active.toString()

    val selectedGuid = MmkvManager.getSelectServer()
    val selected = all.firstOrNull {
        it.guid == selectedGuid
    }

    val score = selected?.let {
        BlueVpnLocationUtil.healthScore(
            this,
            it,
        )
    } ?: all
        .maxOfOrNull {
            BlueVpnLocationUtil.healthScore(
                this,
                it,
            )
        }
        ?: 0

    qualityValue.text =
        if (score > 0) {
            "$score • ${BlueVpnExperience.qualityLabel(score)}"
        } else {
            "در انتظار تست"
        }

    qualityValue.setTextColor(
        Color.parseColor(
            BlueVpnExperience.qualityColor(score)
        )
    )

    historyValue.text =
        BlueVpnExperience.recentSummary(this)
    if (::aiSummaryValue.isInitialized) {
        aiSummaryValue.text = BlueVpnAi.localSummary(this)
    }
}

private fun recordCurrentConnection(
    delayMs: Long,
) {
    val guid = MmkvManager.getSelectServer()
        ?.takeIf { it.isNotBlank() }
        ?: return

    if (lastHistoryGuid == guid) return

    val candidate = BlueVpnLocationUtil
        .cachedCandidates(this)
        .firstOrNull { it.guid == guid }
        ?: MmkvManager.decodeServerConfig(guid)?.let { profile ->
            if (!BlueVpnLocationUtil.isUsable(profile)) null else
                BlueVpnLocationUtil.Candidate(
                    guid = guid,
                    profile = profile,
                    location = BlueVpnLocationUtil.detect(profile.remarks, profile.server),
                    delay = MmkvManager.decodeServerAffiliationInfo(guid)?.testDelayMillis ?: 0L,
                )
        }
        ?: return

    val effectiveDelay =
        delayMs.takeIf { it > 0L }
            ?: candidate.delay

    val score = BlueVpnLocationUtil.healthScore(
        this,
        candidate,
    )

    BlueVpnExperience.recordConnection(
        this,
        candidate.location,
        effectiveDelay,
        score,
    )
    BlueVpnAi.startSession(
        this,
        candidate,
        effectiveDelay,
        score,
    )
    lastHistoryGuid = guid
    refreshExperienceDashboard(BlueVpnLocationUtil.cachedCandidates(this))
}

private fun showWelcomeIfNeeded() {
    if (!BlueVpnExperience.shouldShowWelcome(this)) return
    // First install must land directly on the connection screen.
    // A blocking account/welcome dialog would defeat guest access.
    BlueVpnExperience.markWelcomeShown(this)
}

private fun startStartupOptimization() {
    if (
        startupOptimizationActive ||
        !BlueVpnAccountManager.hasSession(this)
    ) {
        return
    }

    startupOptimizationShown = true
    startupOptimizationActive = true
    // The app is immediately usable. Account/config refresh and BlueAI
    // recommendations run in the background without a blocking dialog or
    // an all-server ping storm on every launch.
    startupServerTestStarted = true

    // Account refresh is non-critical and remains invisible to the user.
    val launchDelay = BlueVpnPerformance.accountSyncDelayMs(this)
    handler.postDelayed({
        if (isFinishing || isDestroyed || !startupOptimizationActive) {
            return@postDelayed
        }
        // Startup is cache-first: never turn app launch into a provider poll.
        // Forced sync is reserved for an explicit user refresh/payment return.
        syncManagedAccount(force = false)
        // BlueAI is intentionally not refreshed during startup. The current
        // route decision is local/cache-first and a user AI tap enriches the
        // model in the background. This removes one more network/JSON workload
        // from the launch path.
        startupOptimizationActive = false
        requestDashboardRefresh(force = false)
    }, launchDelay)

    handler.postDelayed({
        startupOptimizationActive = false
    }, if (BlueVpnPerformance.isLowEnd(this)) 22_000L else 14_000L)
}

private fun startStartupServerTest() {
    if (
        !startupOptimizationActive ||
        startupServerTestStarted
    ) {
        return
    }

    startupServerTestStarted = true
    val count = BlueVpnLocationUtil
        .cachedCandidates(this)
        .size

    updateStartupProgress(
        42,
        if (count > 0) {
            "تست $count سرور با اینترنت شما"
        } else {
            "در حال آماده‌سازی فهرست سرورها"
        }
    )

    if (count <= 0) {
        handler.postDelayed({
            if (startupOptimizationActive) {
                finishStartupOptimization(
                    timedOut = true
                )
            }
        }, 1_200L)
        return
    }

    mainViewModel.testAllRealPing()
}

private fun showStartupOptimizationDialog() {
    startupDialog?.dismiss()

    val dialog = Dialog(this)
    dialog.setCancelable(false)

    val card = MaterialCardView(this).apply {
        radius = dpHome(26).toFloat()
        cardElevation = dpHome(10).toFloat()
        strokeWidth = dpHome(1)
        strokeColor = Color.parseColor("#4A8FE8")
        setCardBackgroundColor(Color.TRANSPARENT)
    }

    val box = LinearLayout(this).apply {
        orientation = LinearLayout.VERTICAL
        gravity = android.view.Gravity.CENTER
        setPadding(
            dpHome(22),
            dpHome(24),
            dpHome(22),
            dpHome(22)
        )
        background = GradientDrawable(
            GradientDrawable.Orientation.TL_BR,
            intArrayOf(
                Color.parseColor("#153E7D"),
                Color.parseColor("#0B2855"),
                Color.parseColor("#071A39")
            )
        ).apply {
            cornerRadius = dpHome(26).toFloat()
        }
    }

    box.addView(TextView(this).apply {
        text = "B"
        textSize = 25f
        gravity = android.view.Gravity.CENTER
        setTextColor(Color.WHITE)
        setTypeface(typeface, Typeface.BOLD)
        background = GradientDrawable(
            GradientDrawable.Orientation.TL_BR,
            intArrayOf(
                Color.parseColor("#5A9DFF"),
                Color.parseColor("#176DFF")
            )
        ).apply {
            shape = GradientDrawable.OVAL
            setStroke(dpHome(2), Color.parseColor("#91C2FF"))
        }
    }, LinearLayout.LayoutParams(dpHome(58), dpHome(58)))

    box.addView(TextView(this).apply {
        text = "آماده‌سازی اتصال"
        textSize = 19f
        gravity = android.view.Gravity.CENTER
        setTextColor(Color.WHITE)
        setTypeface(typeface, Typeface.BOLD)
        setPadding(0, dpHome(8), 0, 0)
    })

    startupStageText = TextView(this).apply {
        text = "شروع همگام‌سازی"
        textSize = 12.5f
        gravity = android.view.Gravity.CENTER
        setTextColor(Color.parseColor("#B9D4F8"))
        setPadding(0, dpHome(7), 0, dpHome(16))
    }
    box.addView(startupStageText)

    box.addView(TextView(this).apply {
        text = "همگام‌سازی  •  ارزیابی شبکه  •  آماده‌سازی اتصال"
        textSize = 10f
        gravity = android.view.Gravity.CENTER
        setTextColor(Color.parseColor("#6F91BE"))
        setPadding(0, 0, 0, dpHome(12))
    })

    startupProgressBar = ProgressBar(
        this,
        null,
        android.R.attr.progressBarStyleHorizontal
    ).apply {
        max = 100
        progress = startupProgress
        progressTintList = ColorStateList.valueOf(
            Color.parseColor("#2E8BFF")
        )
        progressBackgroundTintList =
            ColorStateList.valueOf(
                Color.parseColor("#17345F")
            )
    }
    box.addView(
        startupProgressBar,
        LinearLayout.LayoutParams(-1, dpHome(10))
    )

    startupProgressText = TextView(this).apply {
        text = "$startupProgress٪"
        textSize = 22f
        gravity = android.view.Gravity.CENTER
        setTextColor(Color.WHITE)
        setTypeface(typeface, Typeface.BOLD)
        setPadding(0, dpHome(12), 0, 0)
    }
    box.addView(startupProgressText)

    box.addView(TextView(this).apply {
        text = "سرورهای ناموفق فقط برای این نوبت کنار گذاشته می‌شوند و دفعه بعد دوباره تست خواهند شد."
        textSize = 10.5f
        gravity = android.view.Gravity.CENTER
        setTextColor(Color.parseColor("#7899C6"))
        setPadding(
            dpHome(5),
            dpHome(7),
            dpHome(5),
            0
        )
    })

    card.addView(box)
    dialog.setContentView(card)
    dialog.show()

    dialog.window?.apply {
        setBackgroundDrawable(
            ColorDrawable(Color.TRANSPARENT)
        )
        setLayout(
            (
                resources.displayMetrics.widthPixels *
                    0.90f
                ).toInt(),
            android.view.WindowManager
                .LayoutParams.WRAP_CONTENT
        )
    }

    startupDialog = dialog
}

private fun updateStartupProgress(
    value: Int,
    stage: String? = null,
) {
    startupProgress = value.coerceIn(0, 100)
    startupProgressBar?.progress = startupProgress
    startupProgressText?.text = "$startupProgress٪"

    if (!stage.isNullOrBlank()) {
        startupStageText?.text = stage
    } else {
        startupStageText?.text = when {
            startupProgress < 20 ->
                "همگام‌سازی حساب و اشتراک"
            startupProgress < 42 ->
                "دریافت فهرست تازه سرورها"
            startupProgress < 82 ->
                "تست سرورها با اینترنت شما"
            else ->
                "انتخاب بهترین اتصال موجود"
        }
    }
}

private fun finishStartupOptimization(
    timedOut: Boolean = false,
) {
    if (!startupOptimizationActive) return

    startupOptimizationActive = false
    handler.removeCallbacks(startupProgressTicker)
    handler.removeCallbacks(startupOptimizationTimeout)

    val all = BlueVpnLocationUtil.cachedCandidates(this)

    all.forEach { candidate ->
        when {
            candidate.delay < 0L -> {
                BlueVpnPreferences.markSessionInactive(
                    this,
                    candidate.guid
                )
            }

            candidate.delay > 0L -> {
                BlueVpnPreferences.clearSessionInactive(
                    this,
                    candidate.guid
                )
                BlueVpnPreferences.clearServerFailure(
                    this,
                    candidate.guid
                )
            }
        }
    }

    val activeCount = all.count {
        it.delay > 0L &&
            !BlueVpnPreferences.isSessionInactive(
                this,
                it.guid
            )
    }
    val inactiveCount = all.count {
        BlueVpnPreferences.isSessionInactive(
            this,
            it.guid
        )
    }
    val standbyCount = (
        all.size - activeCount - inactiveCount
    ).coerceAtLeast(0)

    val selectionMode = BlueVpnPreferences.selectionMode(this)
    val preferred = if (selectionMode == BlueVpnSelectionMode.AUTO) {
        ""
    } else {
        BlueVpnPreferences.preferredLocation(this)
    }

    // Startup optimization may refresh AUTO/MANUAL_LOCATION previews, but an
    // explicit MANUAL_SERVER choice is owned by the user and must stay untouched.
    if (
        selectionMode != BlueVpnSelectionMode.MANUAL_SERVER &&
        !BlueVpnRuntimeGate.connectionActive(this)
    ) {
        BlueVpnLocationUtil
            .instantCandidates(this, preferred)
            .firstOrNull()
            ?.let {
                MmkvManager.setSelectServer(it.guid)
            }
    }

    updateStartupProgress(
        100,
        when {
            activeCount > 0 ->
                "لوکیشن‌های آماده اتصال مشخص شدند"
            standbyCount > 0 ->
                "اتصال‌ها هنگام درخواست نهایی بررسی می‌شوند"
            timedOut ->
                "همگام‌سازی انجام شد؛ تست کامل در پس‌زمینه ادامه دارد"
            else ->
                "فهرست سرورها به‌روزرسانی شد"
        }
    )

    requestDashboardRefresh(force = true)

    handler.postDelayed({
        startupDialog?.dismiss()
        startupDialog = null
        startupProgressBar = null
        startupProgressText = null
        startupStageText = null
    }, 1_250L)
}

private fun dpHome(value: Int): Int =
    (
        value *
            resources.displayMetrics.density
    ).toInt()


    private fun applyEntitlementPresentation(
        entitlement: com.v2ray.ang.bluevpn.BlueVpnEntitlementSnapshot = BlueVpnEntitlement.resolveUi(this),
    ) {
        if (::subscriptionSummary.isInitialized) {
            subscriptionSummary.text = entitlement.accountLabel
        }
        if (::connectingNotice.isInitialized) {
            connectingNotice.text = entitlement.connectionNotice
        }
        if (::durationMetricLabel.isInitialized) {
            durationMetricLabel.text = if (entitlement.isFree) {
                "زمان باقی‌مانده"
            } else {
                "مدت اتصال"
            }
        }
        if (::freeTimerBadge.isInitialized) {
            freeTimerBadge.visibility = View.VISIBLE
        }
        if (!entitlement.isFree) {
            BlueVpnTapsellManager.onEntitlementChanged(this)
        }
    }

    private fun showConnectingOverlay(
        title: String = "در حال اتصال",
        caption: String = "در حال انتخاب بهترین اتصال",
        location: String = "انتخاب خودکار",
    ) {
        if (!::connectingOverlay.isInitialized) return
        connectingTitle.text = title
        connectingCaption.text = caption
        connectingLocation.text = location
        if (::connectingNotice.isInitialized) {
            connectingNotice.text = BlueVpnEntitlement.resolveUi(this).connectionNotice
        }
        connectingOverlay.visibility = View.VISIBLE
        connectingOverlay.alpha = 1f
        connectingGlobe.start()
    }

    private fun hideConnectingOverlay() {
        if (!::connectingOverlay.isInitialized) return
        connectingGlobe.stop()
        connectingOverlay.visibility = View.GONE
    }

    private fun updateFreeTimerBadge() {
        if (!::freeTimerBadge.isInitialized) return
        val entitlement = BlueVpnEntitlement.resolveUi(this)
        if (!entitlement.isFree) {
            if (::durationMetricLabel.isInitialized) {
                durationMetricLabel.text = "مدت اتصال"
            }
            freeTimerBadge.visibility = View.VISIBLE
            return
        }

        if (::durationMetricLabel.isInitialized) {
            durationMetricLabel.text = "زمان باقی‌مانده 🎁"
        }
        val active = connectionVerified ||
            mainViewModel.isRunning.value == true ||
            failoverActive
        val remaining = BlueVpnAccountManager.freeSessionRemainingMillis(this)
        if (!active || !entitlement.timeLimited || remaining <= 0L) {
            val configuredMinutes = entitlement.sessionMinutes.coerceAtLeast(0)
            val nextText = if (configuredMinutes > 0) {
                String.format(Locale.US, "%02d:00", configuredMinutes)
            } else {
                "—"
            }
            if (lastRenderedFreeTimerText != nextText) {
                lastRenderedFreeTimerText = nextText
                freeTimerBadge.text = nextText
            }
            if (freeTimerBadge.visibility != View.VISIBLE) {
                freeTimerBadge.visibility = View.VISIBLE
            }
            return
        }
        val totalSeconds = (remaining + 999L) / 1_000L
        val hours = totalSeconds / 3_600L
        val minutes = (totalSeconds % 3_600L) / 60L
        val seconds = totalSeconds % 60L
        val nextText = if (hours > 0L) {
            String.format(Locale.US, "%02d:%02d:%02d", hours, minutes, seconds)
        } else {
            String.format(Locale.US, "%02d:%02d", minutes, seconds)
        }
        if (lastRenderedFreeTimerText != nextText) {
            lastRenderedFreeTimerText = nextText
            freeTimerBadge.text = nextText
        }
        if (freeTimerBadge.visibility != View.VISIBLE) {
            freeTimerBadge.visibility = View.VISIBLE
        }
    }


    private fun toggleConnection() {
        val now = SystemClock.elapsedRealtime()
        if (now - lastConnectionToggleAt < 700L) return
        lastConnectionToggleAt = now
        if (
            userDisconnecting ||
            mainViewModel.isRunning.value == true ||
            failoverActive ||
            networkSweepInProgress ||
            freeStoryGateActive ||
            healthProbeInProgress
        ) {
            stopConnectionImmediately()
            return
        }

        if (
            BlueVpnUpdateManager.blockInteraction(
                this
            )
        ) {
            return
        }

        warpFallbackGeneration = -1
        beginSmartConnection()
    }

    private fun stopConnectionImmediately() {
        lifecycleScope.launch(Dispatchers.IO) {
            BlueVpnAi.finishSession(
                this@BlueVpnHomeActivity,
                "user_disconnect",
                success = connectionVerified,
                downloadBytes = sessionDownloadBytes,
                uploadBytes = sessionUploadBytes,
            )
        }
        terminalFailureStopping = false
        terminalFailureReason = ""
        freeStoryGate?.release()
        freeStoryGate = null
        freeStoryGateActive = false
        userDisconnecting = true
        pendingConnectionRequest = false
        runtimeGateWaitStartedAt = 0L
        cancelFailover()
        handler.removeCallbacks(attemptTimeout)
        handler.removeCallbacks(verificationTimeout)
        verificationDeadlineGuid = ""
        handler.removeCallbacks(requestPing)
        handler.removeCallbacks(disconnectRetry)
        disconnectRetry.reset()

        LauncherManager.stopService(this)
        BlueVpnWarpKeepAliveService.stop(this)
        lifecycleScope.launch(Dispatchers.IO) { BlueVpnWarpEngine.stop() }
        BlueVpnPreferences.clearConnected(this)
        BlueVpnAccountManager.stopFreeSession(this, expired = false)
        connectionVerified = false
        updateFreeTimerBadge()
        existingSessionCheckInProgress = false

        connectButton.isEnabled = false
        updateConnectLabel("در حال قطع")
        statusText.text = "در حال قطع اتصال"
        statusCaption.text =
            "در حال پایان‌دادن به اتصال امن"
        statusDot.backgroundTintList =
            ColorStateList.valueOf(
                Color.parseColor("#FFB44A")
            )

        handler.post(disconnectRetry)
    }

    private fun prepareFreePlanAccess(force: Boolean) {
        // When WARP is enabled it remains the primary Free transport and the
        // Smart Pool is fetched lazily as fallback. When WARP is disabled,
        // Smart Pool becomes the primary Free transport and is prepared
        // proactively so background AI can benchmark it before Connect.
        if (BlueVpnAccountManager.warpFreeEnabled(this) && BlueVpnWarpEngine.supported(this) &&
            warpFallbackGeneration != connectionPreparationGeneration) return
        // Logged-in users without an active Premium entitlement are Free users too.
        if (BlueVpnAccountManager.premiumEntitlementActive(this) || freePreparationInProgress) return
        val now = SystemClock.elapsedRealtime()
        if (!force && now - lastGuestPreparationAt < 15_000L) return
        lastGuestPreparationAt = now
        freePreparationInProgress = true
        lifecycleScope.launch(Dispatchers.IO) {
            val prepared = BlueVpnAccountManager
                .prepareFreeAccess(this@BlueVpnHomeActivity, force)
                .getOrDefault(false)
            withContext(Dispatchers.Main) {
                freePreparationInProgress = false
                if (prepared) {
                    BlueVpnLocationUtil.invalidateCache()
                    mainViewModel.reloadServerList()
                    BlueVpnBackgroundOptimizer.markPending(this@BlueVpnHomeActivity)
                    BlueVpnBackgroundOptimizer.maybeStart(
                        this@BlueVpnHomeActivity,
                        force = force,
                    )
                }
                requestDashboardRefresh(force = true)
                refreshSubscriptionInfo(force = true)
            }
        }
    }

    private fun reconcileDeferredEntitlementIfIdle(retryConnection: Boolean = false) {
        retryConnectionAfterEntitlementReconcile =
            retryConnectionAfterEntitlementReconcile || retryConnection
        if (
            entitlementReconcileInProgress ||
            !BlueVpnAccountManager.entitlementReconcilePending(this) ||
            mainViewModel.isRunning.value == true ||
            failoverActive ||
            networkSweepInProgress ||
            userDisconnecting ||
            isFinishing ||
            isDestroyed
        ) return

        entitlementReconcileInProgress = true
        lifecycleScope.launch(Dispatchers.IO) {
            val repaired = BlueVpnAccountManager
                .reconcilePendingEntitlement(this@BlueVpnHomeActivity)
                .getOrDefault(false)
            withContext(Dispatchers.Main) {
                entitlementReconcileInProgress = false
                if (isFinishing || isDestroyed) return@withContext

                if (repaired) {
                    BlueVpnLocationUtil.invalidateCache()
                    mainViewModel.reloadServerList()
                    requestDashboardRefresh(force = true)
                    refreshSubscriptionInfo(force = true)
                }

                val shouldRetryConnection = retryConnectionAfterEntitlementReconcile
                retryConnectionAfterEntitlementReconcile = false
                if (
                    repaired && shouldRetryConnection && pendingConnectionRequest &&
                    mainViewModel.isRunning.value != true && !failoverActive && !userDisconnecting
                ) {
                    pendingConnectionRequest = false
                    connectButton.isEnabled = true
                    beginSmartConnection()
                } else if (shouldRetryConnection && !repaired) {
                    pendingConnectionRequest = false
                    hideConnectingOverlay()
                    connectButton.isEnabled = true
                    updateConnectLabel("تلاش دوباره")
                    statusText.text = "همگام‌سازی پلن کامل نشد"
                    statusCaption.text = "Pool قبلی دست‌نخورده ماند؛ پس از بررسی اینترنت دوباره تلاش کنید"
                }
            }
        }
    }

    private fun beginSmartConnection() {
        if (
            recoveryCleanupRequired &&
            mainViewModel.isRunning.value == true &&
            !userDisconnecting
        ) {
            pendingConnectionRequest = true
            connectionVerified = false
            existingSessionCheckInProgress = false
            BlueVpnPreferences.clearConnected(this)
            BlueVpnRuntimeGate.endConnection(this)
            handler.removeCallbacks(verificationTimeout)
            verificationDeadlineGuid = ""
            if (premiumInstantUiEnabled()) {
                renderPremiumInstantConnectedUi("بازیابی هوشمند")
            } else {
                statusText.text = "در حال پاک‌سازی اتصال قبلی"
                statusCaption.text = "پس از توقف کامل Xray، اتصال دوباره از Pool مجاز بررسی می‌شود"
                connectButton.isEnabled = false
                updateConnectLabel("در حال پاک‌سازی")
            }
            LauncherManager.stopService(this)
            return
        }
        if (recoveryCleanupRequired && mainViewModel.isRunning.value != true) {
            recoveryCleanupRequired = false
        }

        if (terminalFailureStopping && mainViewModel.isRunning.value == true) {
            hideConnectingOverlay()
            LauncherManager.stopService(this)
            connectButton.isEnabled = false
            updateConnectLabel("در حال توقف")
            statusText.text = "در حال آزادسازی اتصال قبلی"
            statusCaption.text = "پس از توقف کامل Xray می‌توانید دوباره تلاش کنید"
            return
        }
        terminalFailureStopping = false
        terminalFailureReason = ""
        lastCandidateFailureReason = ""
        userDisconnecting = false
        disconnectRetry.reset()
        handler.removeCallbacks(disconnectRetry)

        // A new explicit connect cycle gets a fresh temporary quarantine.
        // Routes that fail during this cycle are excluded immediately and are
        // allowed back only on a later user attempt.
        BlueVpnPreferences.beginHealthSession(this)

        // Exact entitlement/MMKV selection validation is intentionally deferred
        // to the background connection-preparation worker below. Never enumerate
        // subscription/server rows on Android's main thread.

        if (BlueVpnUpdateManager.blockInteraction(this)) return

        val entitlement = BlueVpnEntitlement.resolveUi(this)
        applyEntitlementPresentation(entitlement)
        if (!entitlement.canConnect) {
            hideConnectingOverlay()
            statusText.text = "دسترسی اتصال آماده نیست"
            statusCaption.text = entitlement.connectionNotice
            Toast.makeText(this, entitlement.connectionNotice, Toast.LENGTH_LONG).show()
            return
        }

        // Free is always automatic. Premium keeps explicit ownership selected by
        // the user; opening the connect flow must never silently switch MANUAL
        // back to AUTO.
        if (entitlement.isFree && BlueVpnPreferences.selectionMode(this) != BlueVpnSelectionMode.AUTO) {
            BlueVpnPreferences.setAutomaticSelection(this)
        }
        val selectionMode = if (entitlement.isFree) {
            BlueVpnSelectionMode.AUTO
        } else {
            BlueVpnPreferences.selectionMode(this)
        }

        // Free primary engine: Aether/WARP. Premium remains on the exact stock
        // v2rayNG subscription path. If the native Aether binary cannot start,
        // fall back to the isolated Smart Free Pool for this
        // connect attempt rather than leaving the user stuck.
        if (entitlement.isFree && BlueVpnAccountManager.warpFreeEnabled(this) &&
            warpFallbackGeneration != connectionPreparationGeneration) {
            if (beginWarpFreeConnection()) return
        } else if (entitlement.isPremium && BlueVpnWarpEngine.isRunning()) {
            lifecycleScope.launch(Dispatchers.IO) { BlueVpnWarpEngine.stop() }
        }

        val overlayLocation = when (selectionMode) {
            BlueVpnSelectionMode.AUTO -> if (entitlement.isFree) "انتخاب هوشمند رایگان" else "انتخاب خودکار"
            BlueVpnSelectionMode.MANUAL_LOCATION -> "لوکیشن انتخاب‌شده"
            BlueVpnSelectionMode.MANUAL_SERVER -> "سرور انتخاب‌شده"
        }
        showConnectingOverlay(
            title = "در حال اتصال",
            caption = when (selectionMode) {
                BlueVpnSelectionMode.AUTO -> "در حال آماده‌سازی انتخاب هوشمند"
                BlueVpnSelectionMode.MANUAL_LOCATION -> "در حال آماده‌سازی لوکیشن انتخاب‌شده"
                BlueVpnSelectionMode.MANUAL_SERVER -> "در حال اتصال دقیق به سرور انتخاب‌شده"
            },
            location = overlayLocation,
        )

        if (entitlement.tier == BlueVpnPlanTier.FREE) {
            if (freePreparationInProgress) {
                handler.postDelayed({
                    if (!isFinishing && !isDestroyed && !freePreparationInProgress &&
                        !failoverActive && mainViewModel.isRunning.value != true) {
                        beginSmartConnection()
                    }
                }, 450L)
                return
            }
            if (!BlueVpnAccountManager.hasInstalledFreeServers(this)) {
                freePreparationInProgress = true
                pendingConnectionRequest = true
                connectButton.isEnabled = false
                statusText.text = "آماده‌سازی اتصال رایگان"
                statusCaption.text = "دریافت Pool اختصاصی رایگان"
                showConnectingOverlay(
                    title = "آماده‌سازی اتصال رایگان",
                    caption = "سرورهای Premium وارد این Pool نمی‌شوند",
                    location = "انتخاب هوشمند رایگان",
                )
                lifecycleScope.launch(Dispatchers.IO) {
                    val prepared = BlueVpnAccountManager
                        .prepareFreeAccess(this@BlueVpnHomeActivity, force = false)
                        .getOrDefault(false)
                    withContext(Dispatchers.Main) {
                        freePreparationInProgress = false
                        connectButton.isEnabled = true
                        BlueVpnLocationUtil.invalidateCache()
                        mainViewModel.reloadServerList()
                        if (prepared && pendingConnectionRequest) {
                            pendingConnectionRequest = false
                            beginSmartConnection()
                        } else if (!prepared) {
                            pendingConnectionRequest = false
                            hideConnectingOverlay()
                            statusText.text = "اتصال رایگان در دسترس نیست"
                            statusCaption.text = "سرور رایگان فعالی دریافت نشد"
                        }
                    }
                }
                return
            }
        }

        if (BlueVpnAccountManager.entitlementReconcilePending(this)) {
            // A background refresh must never hold a paying/free user hostage when
            // the exact current entitlement already has usable profiles. Continue
            // with that pool and defer mutation until the connection is idle.
            if (!BlueVpnAccountManager.hasUsableCurrentEntitlementPool(this)) {
                pendingConnectionRequest = true
                connectButton.isEnabled = false
                statusText.text = "در حال اعمال پلن جدید"
                statusCaption.text = "Pool فعلی هنوز کانفیگ قابل استفاده ندارد"
                showConnectingOverlay(
                    title = "در حال آماده‌سازی پلن",
                    caption = "فقط تا آماده‌شدن اولین Pool معتبر منتظر می‌مانیم",
                    location = overlayLocation,
                )
                reconcileDeferredEntitlementIfIdle(retryConnection = true)
                return
            }
            statusCaption.text = "Pool فعلی آماده است • همگام‌سازی تکمیلی در پس‌زمینه"
        }

        if (!BlueVpnRuntimeGate.beginConnection(this, timeoutMs = 0L)) {
            val now = SystemClock.elapsedRealtime()
            if (runtimeGateWaitStartedAt <= 0L) runtimeGateWaitStartedAt = now
            val waitedMs = now - runtimeGateWaitStartedAt

            // Never leave the UI in an endless CONNECTING state when an upstream
            // subscription import/provider request stalls. Subscription mutation
            // keeps ownership of MMKV until it finishes, but the user gets the UI
            // back after a bounded wait and can retry safely instead of racing it.
            if (waitedMs >= 25_000L) {
                pendingConnectionRequest = false
                runtimeGateRetryScheduled = false
                runtimeGateWaitStartedAt = 0L
                hideConnectingOverlay()
                connectButton.isEnabled = true
                updateConnectLabel("تلاش دوباره")
                statusText.text = "آماده‌سازی اشتراک کامل نشد"
                statusCaption.visibility = View.VISIBLE
                statusCaption.text = "Import ساب در زمان مجاز تمام نشد؛ چند لحظه بعد دوباره تلاش کنید"
                return
            }

            pendingConnectionRequest = true
            statusText.text = "در حال تکمیل همگام‌سازی"
            statusCaption.text = "Pool فعلی فقط تا پایان Import قفل می‌ماند"
            showConnectingOverlay(
                title = "در حال آماده‌سازی اتصال",
                caption = "منتظر پایان آخرین Import اشتراک",
                location = overlayLocation,
            )
            if (!runtimeGateRetryScheduled) {
                runtimeGateRetryScheduled = true
                handler.postDelayed({
                    runtimeGateRetryScheduled = false
                    if (
                        pendingConnectionRequest &&
                        !failoverActive &&
                        mainViewModel.isRunning.value != true &&
                        !isFinishing &&
                        !isDestroyed
                    ) {
                        beginSmartConnection()
                    }
                }, 350L)
            }
            return
        }
        runtimeGateRetryScheduled = false
        runtimeGateWaitStartedAt = 0L
        pendingConnectionRequest = false

        enforceReliableVpnSettings()
        BlueVpnPreferences.clearConnected(this)
        connectionVerified = false
        existingSessionCheckInProgress = false
        lastVerifiedLatency = 0L

        val selectedGuid = MmkvManager.getSelectServer().orEmpty()
        val selectedProfile = selectedGuid.takeIf { it.isNotBlank() }
            ?.let { MmkvManager.decodeServerConfig(it) }
        val selectedLocation = selectedProfile
            ?.takeIf { BlueVpnLocationUtil.isUsable(it) }
            ?.let { BlueVpnLocationUtil.detect(it.remarks, it.server).key }
            .orEmpty()

        val preferredLocation = when (selectionMode) {
            BlueVpnSelectionMode.AUTO -> ""
            BlueVpnSelectionMode.MANUAL_LOCATION -> BlueVpnPreferences.preferredLocation(this)
                .ifBlank { selectedLocation }
            BlueVpnSelectionMode.MANUAL_SERVER -> BlueVpnPreferences.preferredLocation(this)
                .ifBlank { selectedLocation }
        }

        fun exactManualCandidate(): BlueVpnLocationUtil.Candidate? {
            if (selectionMode != BlueVpnSelectionMode.MANUAL_SERVER) return null
            val manualGuid = BlueVpnPreferences.manualServerGuid(this)
                .ifBlank { selectedGuid }
            if (manualGuid.isBlank()) return null
            val profile = MmkvManager.decodeServerConfig(manualGuid) ?: return null
            if (!BlueVpnLocationUtil.isUsable(profile, MmkvManager.decodeServerRaw(manualGuid))) return null
            return BlueVpnLocationUtil.Candidate(
                guid = manualGuid,
                profile = profile,
                location = BlueVpnLocationUtil.detect(profile.remarks, profile.server),
                delay = MmkvManager.decodeServerAffiliationInfo(manualGuid)?.testDelayMillis ?: 0L,
            )
        }

        exactManualCandidate()?.let { exact ->
            startNetworkSweepThenConnect(listOf(exact), selectionMode)
            return
        }
        if (selectionMode == BlueVpnSelectionMode.MANUAL_SERVER) {
            BlueVpnRuntimeGate.endConnection(this)
            hideConnectingOverlay()
            statusText.text = "سرور انتخاب‌شده در دسترس نیست"
            statusCaption.text = "همان سرور حذف یا منقضی شده است؛ دوباره یک سرور انتخاب کنید"
            connectButton.isEnabled = true
            return
        }

        // AUTO is cache-first. A connect request must never force a full MMKV
        // rebuild; subscription refresh has a separate single owner. If the cache
        // is warm we connect from it immediately, while idle warm-up may rebuild
        // the complete inventory later without blocking the user.
        if (selectionMode == BlueVpnSelectionMode.AUTO) {
            val cached = BlueVpnLocationUtil.cachedCandidates(this)
            if (cached.isNotEmpty()) {
                startNetworkSweepThenConnect(cached.take(12), BlueVpnSelectionMode.AUTO)
                scheduleIdleCandidateWarmup()
                return
            }
        }

        if (!BlueVpnLocationUtil.hasCandidateCache(this)) {
            if (candidateLoadInProgress) {
                pendingConnectionRequest = true
                return
            }
            candidateLoadInProgress = true
            pendingConnectionRequest = true
            lifecycleScope.launch(Dispatchers.Default) {
                // Reliability first: load the complete entitlement-isolated pool
                // off the main thread. Ranking still keeps the first attempt fast,
                // while routes below the old top-10 cutoff remain available as
                // progressive failover candidates.
                val fast = BlueVpnLocationUtil.allCandidates(
                    this@BlueVpnHomeActivity,
                ).let { loaded ->
                    when (selectionMode) {
                        BlueVpnSelectionMode.AUTO -> loaded
                        BlueVpnSelectionMode.MANUAL_LOCATION -> loaded.filter {
                            preferredLocation.isBlank() || it.location.key == preferredLocation
                        }
                        BlueVpnSelectionMode.MANUAL_SERVER -> loaded.filter {
                            it.guid == BlueVpnPreferences.manualServerGuid(this@BlueVpnHomeActivity)
                        }
                    }
                }
                withContext(Dispatchers.Main) {
                    candidateLoadInProgress = false
                    if (isFinishing || isDestroyed || !pendingConnectionRequest) return@withContext
                    pendingConnectionRequest = false
                    startNetworkSweepThenConnect(fast, selectionMode)
                    scheduleIdleCandidateWarmup()
                }
            }
            return
        }

        val visibleCache = BlueVpnLocationUtil.cachedCandidates(this)
        val scopedCache = when (selectionMode) {
            BlueVpnSelectionMode.AUTO -> visibleCache
            BlueVpnSelectionMode.MANUAL_LOCATION -> visibleCache.filter {
                preferredLocation.isBlank() || it.location.key == preferredLocation
            }
            BlueVpnSelectionMode.MANUAL_SERVER -> visibleCache.filter {
                it.guid == BlueVpnPreferences.manualServerGuid(this)
            }
        }
        startNetworkSweepThenConnect(scopedCache, selectionMode)
    }

    private fun startNetworkSweepThenConnect(
        candidates: List<BlueVpnLocationUtil.Candidate>,
        selectionMode: BlueVpnSelectionMode,
    ) {
        if (candidates.isEmpty()) {
            startSmartConnectionWithCandidates(candidates, selectionMode)
            return
        }

        // Connect-first policy: a user's connect gesture must start the best
        // locally-known route immediately. Route tests belong to idle/background
        // intelligence and must never sit in front of the VPN handshake. This is
        // especially important for Premium, but applies to Free Pool as well.
        // Manual-server stays exact; manual-location and AUTO retain failover.
        if (selectionMode != BlueVpnSelectionMode.MANUAL_SERVER) {
            startSmartConnectionWithCandidates(candidates, selectionMode)
            BlueVpnBackgroundOptimizer.markPending(this)
            scheduleIdleCandidateWarmup()
            return
        }

        if (candidates.size <= 1 || selectionMode == BlueVpnSelectionMode.MANUAL_SERVER) {
            startSmartConnectionWithCandidates(candidates, selectionMode)
            return
        }
        networkSweepGeneration += 1
        val generation = networkSweepGeneration
        networkSweepInProgress = true
        networkSweepStartedAt = SystemClock.elapsedRealtime()
        networkSweepCandidates = candidates
        networkSweepSelectionMode = selectionMode
        // Fast lane: measure a bounded, history-aware sample first. Testing 150-200
        // routes synchronously can take minutes on mobile networks and is not
        // required before the first verified tunnel. Every remaining candidate
        // stays in networkSweepCandidates and is available for failover.
        val sweepOrder = candidates.sortedWith(
            compareBy<BlueVpnLocationUtil.Candidate> { if (it.delay > 0L) 0 else 1 }
                .thenBy { if (it.delay > 0L) it.delay else Long.MAX_VALUE }
        )
        networkSweepGuids = sweepOrder.map { it.guid }.distinct().take(8)
        networkSweepBlockingTotal = networkSweepGuids.size
        networkSweepTotal = candidates.map { it.guid }.distinct().size

        showConnectingOverlay(
            title = "تحلیل هوشمند شبکه",
            caption = "اسکن سریع ۰ از $networkSweepBlockingTotal • کل Pool $networkSweepTotal",
            location = "در حال پیدا کردن سریع‌ترین مسیر سالم",
        )
        statusText.text = "تحلیل کیفیت سرورها"
        statusCaption.visibility = View.VISIBLE
        statusCaption.text = "BlueAI همه مسیرهای مجاز این پلن را با شبکه فعلی دسته‌بندی می‌کند"
        updateConnectLabel("لغو اتصال")
        connectButton.isEnabled = true

        // Use v2rayNG's own TestService for the exact entitlement-isolated GUIDs.
        // This tests all routes without importing/recompiling them in BlueVPN.
        MmkvManager.clearAllTestDelayResults(networkSweepGuids)
        MessageHelper.sendMsg2TestService(
            this,
            TestServiceMessage(key = AppConfig.MSG_MEASURE_CONFIG_CANCEL),
        )
        MessageHelper.sendMsg2TestService(
            this,
            TestServiceMessage(
                key = AppConfig.MSG_MEASURE_CONFIG_START,
                serverGuids = networkSweepGuids,
            ),
        )
        handler.removeCallbacks(networkSweepTicker)
        handler.post(networkSweepTicker)

        // Generation is captured by ticker/finish so a logout, cancel or new
        // connection request cannot apply stale test results.
        if (generation != networkSweepGeneration) return
    }

    private fun finishNetworkSweep(generation: Int, timedOut: Boolean, earlyQuorum: Boolean = false) {
        if (!networkSweepInProgress || generation != networkSweepGeneration) return
        networkSweepInProgress = false
        networkSweepPollInFlight = false
        handler.removeCallbacks(networkSweepTicker)
        MessageHelper.sendMsg2TestService(
            this,
            TestServiceMessage(key = AppConfig.MSG_MEASURE_CONFIG_CANCEL),
        )

        val original = networkSweepCandidates
        val mode = networkSweepSelectionMode
        val refreshed = original.mapNotNull { candidate ->
            val profile = MmkvManager.decodeServerConfig(candidate.guid) ?: return@mapNotNull null
            val delay = MmkvManager.decodeServerAffiliationInfo(candidate.guid)?.testDelayMillis ?: 0L
            candidate.copy(profile = profile, delay = delay)
        }
        val testedSet = networkSweepGuids.toSet()
        val healthy = refreshed.filter { it.guid in testedSet && it.delay > 0L }
            .sortedBy { it.delay }
        val unknown = refreshed.filter { it.guid !in testedSet || it.delay == 0L }
            .map { it.copy(delay = 0L) } // stale historical ping must never outrank a route tested now
        val failed = refreshed.filter { it.guid in testedSet && it.delay < 0L }
        failed.forEach { BlueVpnPreferences.markSessionInactive(this, it.guid) }
        healthy.forEach { BlueVpnPreferences.clearSessionInactive(this, it.guid) }

        // Healthy real-ping routes are always ranked first. Unknown routes stay
        // as reserve because REALITY/WS/gRPC can be valid even when a generic
        // delay probe is inconclusive. Hard failures are excluded for this cycle.
        val ready = (healthy + unknown).distinctBy { it.guid }
        statusCaption.text = when {
            earlyQuorum && healthy.isNotEmpty() -> "${healthy.size} مسیر سالم کافی پیدا شد • اتصال فوری به بهترین گزینه"
            healthy.isNotEmpty() -> "${healthy.size} مسیر سالم • ${failed.size} ناموفق • انتخاب سریع‌ترین مسیر"
            timedOut && unknown.isNotEmpty() -> "اسکن سریع تمام شد؛ مسیرهای ذخیره با تأیید واقعی Xray بررسی می‌شوند"
            else -> "نتیجه قطعی از اسکن سریع نگرفتیم؛ بررسی واقعی Xray ادامه دارد"
        }
        startSmartConnectionWithCandidates(
            if (ready.isNotEmpty()) ready else refreshed,
            mode,
        )
    }

    private fun warpFailureTitle(code: BlueVpnWarpEngine.ErrorCode?): String = when (code) {
        BlueVpnWarpEngine.ErrorCode.EXIT_IRAN, BlueVpnWarpEngine.ErrorCode.WARP_EXIT_COUNTRY_BLOCKED -> "مسیر خروجی مجاز نبود"
        BlueVpnWarpEngine.ErrorCode.SOCKS_FAILED, BlueVpnWarpEngine.ErrorCode.WARP_SOCKS_HANDSHAKE_FAILED -> "مسیر رایگان آماده نشد"
        BlueVpnWarpEngine.ErrorCode.AETHER_CRASHED, BlueVpnWarpEngine.ErrorCode.WARP_PROCESS_EXITED -> "موتور اتصال رایگان متوقف شد"
        BlueVpnWarpEngine.ErrorCode.WARP_START_TIMEOUT, BlueVpnWarpEngine.ErrorCode.TCP_TIMEOUT, BlueVpnWarpEngine.ErrorCode.UDP_BLOCKED -> "شبکه به مسیر رایگان پاسخ نداد"
        BlueVpnWarpEngine.ErrorCode.EXIT_VALIDATION_FAILED, BlueVpnWarpEngine.ErrorCode.WARP_EXIT_TRACE_UNAVAILABLE -> "تأیید مسیر خروجی ناموفق بود"
        BlueVpnWarpEngine.ErrorCode.NO_INTERNET, BlueVpnWarpEngine.ErrorCode.WARP_DATA_PLANE_FAILED -> "اینترنت از مسیر رایگان عبور نکرد"
        else -> "اتصال رایگان در دسترس نیست"
    }

    private fun warpFailureCaption(failure: BlueVpnWarpEngine.Failure?): String {
        if (failure == null) return "روی این شبکه مسیر WARP آماده نشد؛ دوباره تلاش کنید"
        val strategy = failure.strategy?.name?.replace('_', ' ') ?: "AUTO"
        if (failure.code == BlueVpnWarpEngine.ErrorCode.EXIT_IRAN) {
            return "مسیر رایگان فعلی مناسب نبود؛ مسیر برای این شبکه جریمه شد و بازیابی خودکار انجام می‌شود"
        }
        return "${failure.code.name} • $strategy • ${failure.detail.take(90)}"
    }

    private fun beginWarpFreeConnection(): Boolean {
        if (warpPreparationInProgress) return true
        if (!BlueVpnWarpEngine.supported(this)) {
            warpFallbackGeneration = connectionPreparationGeneration
            val canFallback = BlueVpnAccountManager.warpFallbackEnabled(this)
            if (!canFallback) {
                statusText.text = "WARP روی این دستگاه آماده نیست"
                statusCaption.text = "نسخه سازگار موتور رایگان برای معماری دستگاه پیدا نشد"
            }
            return !canFallback
        }

        warpPreparationInProgress = true
        pendingConnectionRequest = false
        connectButton.isEnabled = false
        statusText.text = "در حال اتصال رایگان"
        statusCaption.text = "در حال آماده‌سازی اتصال رایگان BlueVPN"
        showConnectingOverlay(
            title = "BlueVPN Free",
            caption = "Aether مسیر قابل عبور را روی شبکه فعلی پیدا می‌کند",
            location = "اتصال رایگان",
        )

        val generation = ++connectionPreparationGeneration
        lifecycleScope.launch(Dispatchers.IO) {
            val result = BlueVpnWarpEngine.prepare(this@BlueVpnHomeActivity)
            withContext(Dispatchers.Main) {
                warpPreparationInProgress = false
                if (generation != connectionPreparationGeneration || userDisconnecting || isFinishing || isDestroyed) {
                    BlueVpnWarpEngine.stop()
                    return@withContext
                }

                val guid = result.getOrNull().orEmpty()
                if (guid.isBlank()) {
                    // Bounded fallback is policy-controlled by WordPress. WARP-only
                    // mode must never silently fall back to public subscription
                    // profiles, while fallback mode may preserve the legacy pool.
                    warpFallbackGeneration = connectionPreparationGeneration
                    connectButton.isEnabled = true
                    if (BlueVpnAccountManager.warpFallbackEnabled(this@BlueVpnHomeActivity)) {
                        statusCaption.text = "WARP آماده نشد • استفاده از Smart Free Pool پشتیبان"
                        handler.post { beginSmartConnection() }
                    } else {
                        hideConnectingOverlay()
                        val failure = BlueVpnWarpEngine.lastFailure()
                        statusText.text = warpFailureTitle(failure?.code)
                        statusCaption.text = warpFailureCaption(failure)
                    }
                    return@withContext
                }

                // Aether must outlive this Activity. A dedicated foreground service keeps
                // the application/child process alive while v2rayNG owns the VPN TUN.
                BlueVpnWarpKeepAliveService.start(this@BlueVpnHomeActivity)
                connectionEntitlementGuids = setOf(guid)
                failoverQueue = listOf(guid)
                failoverReserveQueue = emptyList()
                failoverIndex = 0
                failoverActive = true
                connectionVerified = false
                attemptedGuid = ""
                waitingForPingResult = false
                healthProbeInProgress = false
                connectButton.isEnabled = false
                statusText.text = "در حال اتصال به WARP"
                statusCaption.text = "مسیر Aether آماده شد • تأیید اینترنت واقعی"
                BlueVpnWarpEngine.markBridgeStarting()

                if (SettingsManager.isVpnMode()) {
                    val permissionIntent = VpnService.prepare(this@BlueVpnHomeActivity)
                    if (permissionIntent == null) startCurrentCandidate()
                    else requestVpnPermission.launch(permissionIntent)
                } else {
                    startCurrentCandidate()
                }
            }
        }
        return true
    }

    private fun startSmartConnectionWithCandidates(
        candidates: List<BlueVpnLocationUtil.Candidate>,
        selectionMode: BlueVpnSelectionMode,
    ) {
        // MMKV entitlement enumeration and smart scoring are intentionally
        // background-only. Previous builds repeated preferredServerGuids()/
        // candidateAllowed() on the UI thread for every route, which could block
        // Android long enough to show the system "BlueVPN is not responding" ANR.
        val generation = ++connectionPreparationGeneration
        val entitlementIdentityAtStart = BlueVpnAccountManager
            .entitlementIdentityFingerprint(this)
        statusCaption.text = "در حال آماده‌سازی سریع Pool اتصال"
        lifecycleScope.launch(Dispatchers.Default) {
            val prepared = runCatching {
                BlueVpnAccountManager.ensureEntitlementSelection(this@BlueVpnHomeActivity)
                val entitlementGuids = BlueVpnAccountManager
                    .preferredServerGuids(this@BlueVpnHomeActivity)
                    .toSet()
                val isolated = candidates.filter { candidate ->
                    !BlueVpnPreferences.isSessionInactive(this@BlueVpnHomeActivity, candidate.guid) &&
                        BlueVpnAccountManager.candidateAllowed(
                            this@BlueVpnHomeActivity,
                            candidate.guid,
                            candidate.profile.subscriptionId,
                            entitlementGuids,
                        )
                }
                val scored = when (selectionMode) {
                    BlueVpnSelectionMode.AUTO -> BlueVpnSmartSelector.connectionOrderTrusted(
                        this@BlueVpnHomeActivity, isolated,
                    )
                    BlueVpnSelectionMode.MANUAL_LOCATION -> BlueVpnSmartSelector.rankTrusted(
                        this@BlueVpnHomeActivity, isolated,
                    )
                    BlueVpnSelectionMode.MANUAL_SERVER -> {
                        val manualGuid = BlueVpnPreferences.manualServerGuid(this@BlueVpnHomeActivity)
                            .ifBlank { MmkvManager.getSelectServer().orEmpty() }
                        val exact = isolated.firstOrNull { it.guid == manualGuid }
                        if (exact == null) emptyList() else listOf(
                            BlueVpnSmartSelector.scoreTrusted(this@BlueVpnHomeActivity, exact),
                        )
                    }
                }
                Triple(entitlementGuids, isolated.size, scored)
            }.getOrElse {
                Triple(emptySet<String>(), 0, emptyList())
            }

            withContext(Dispatchers.Main) {
                val entitlementIdentityNow = BlueVpnAccountManager
                    .entitlementIdentityFingerprint(this@BlueVpnHomeActivity)
                if (
                    generation != connectionPreparationGeneration ||
                    entitlementIdentityNow != entitlementIdentityAtStart ||
                    userDisconnecting ||
                    isFinishing ||
                    isDestroyed
                ) {
                    // The account/plan changed while candidate preparation was
                    // running (for example Premium -> logout -> Free). Never
                    // apply a queue prepared for the previous entitlement.
                    connectionEntitlementGuids = emptySet()
                    BlueVpnRuntimeGate.endConnection(this@BlueVpnHomeActivity)
                    if (!userDisconnecting && !isFinishing && !isDestroyed) {
                        pendingConnectionRequest = true
                        handler.postDelayed({ beginSmartConnection() }, 120L)
                    }
                    return@withContext
                }
                connectionEntitlementGuids = prepared.first
                applyPreparedConnectionQueue(
                    scoredQueue = prepared.third,
                    selectionMode = selectionMode,
                    isolatedCount = prepared.second,
                )
            }
        }
    }

    private fun locationAwareAutoQueue(
        queue: List<BlueVpnSmartSelector.ScoredCandidate>,
    ): List<BlueVpnSmartSelector.ScoredCandidate> {
        if (queue.size <= 1) return queue
        val groups = queue.groupBy { it.candidate.location.key }
        val locationOrder = queue.map { it.candidate.location.key }.distinct()
        return buildList {
            locationOrder.forEach { key ->
                addAll(groups[key].orEmpty())
            }
        }
    }

    private fun applyPreparedConnectionQueue(
        scoredQueue: List<BlueVpnSmartSelector.ScoredCandidate>,
        selectionMode: BlueVpnSelectionMode,
        isolatedCount: Int,
    ) {
        if (scoredQueue.isEmpty()) {
            val failedLiveSwitch = liveLocationSwitch
            connectionEntitlementGuids = emptySet()
            hideConnectingOverlay()
            failoverActive = false
            connectionVerified = false
            BlueVpnPreferences.clearConnected(this)
            handoverState.failed()

            // A failed live handover never rolls back to the previous route.
            // If the old core is still alive because candidate preparation failed
            // before the first new GUID started, stop it explicitly and keep the
            // app disconnected.
            if (failedLiveSwitch && mainViewModel.isRunning.value == true) {
                terminalFailureReason = "لوکیشن انتخاب‌شده مسیر قابل اتصال نداشت"
                terminalFailureStopping = true
                LauncherManager.stopService(this)
            } else {
                BlueVpnRuntimeGate.endConnection(this)
                handoverState.disconnected()
            }

            liveLocationSwitch = false
            switchTargetTitle = ""
            connectButton.isEnabled = !terminalFailureStopping
            updateConnectLabel(if (terminalFailureStopping) "در حال توقف" else "اتصال")
            applyOrbVisual(OrbVisualState.ERROR)
            statusText.text = when (selectionMode) {
                BlueVpnSelectionMode.AUTO -> "سرور قابل اتصال نیست"
                BlueVpnSelectionMode.MANUAL_LOCATION -> "لوکیشن قابل اتصال نیست"
                BlueVpnSelectionMode.MANUAL_SERVER -> "سرور انتخاب‌شده قابل اتصال نیست"
            }
            statusCaption.text = if (failedLiveSwitch) {
                "تغییر لوکیشن ناموفق بود؛ اتصال قبلی بازگردانی نمی‌شود"
            } else {
                "فقط منابع مجاز پلن فعلی بررسی شدند"
            }
            Toast.makeText(this, "سرور سالم و سازگار پیدا نشد", Toast.LENGTH_SHORT).show()
            return
        }

        // A route already known to be failing on this physical network must not
        // win the next user gesture merely because an old ping/AI score was high.
        // Keep it as failover reserve so recovered servers are never deleted.
        val connectionReadyQueue = if (selectionMode == BlueVpnSelectionMode.MANUAL_SERVER) {
            scoredQueue
        } else {
            val ready = scoredQueue.filterNot { item ->
                BlueVpnPreferences.failedRecently(this, item.candidate.guid) ||
                    BlueVpnRouteIntelligence.isCoolingDown(this, item.candidate.guid)
            }
            if (ready.isEmpty()) scoredQueue else ready + scoredQueue.filter { it !in ready }
        }
        val effectiveQueue = if (selectionMode == BlueVpnSelectionMode.AUTO) {
            locationAwareAutoQueue(connectionReadyQueue)
        } else {
            connectionReadyQueue
        }
        val orderedGuids = effectiveQueue.map { it.candidate.guid }.distinct()
        when (selectionMode) {
            BlueVpnSelectionMode.MANUAL_SERVER -> {
                failoverQueue = orderedGuids.take(1)
                failoverReserveQueue = emptyList()
            }
            BlueVpnSelectionMode.MANUAL_LOCATION -> {
                // A location is the public selection; every hidden route behind it
                // must remain eligible before declaring that location unavailable.
                failoverQueue = orderedGuids
                failoverReserveQueue = emptyList()
            }
            BlueVpnSelectionMode.AUTO -> {
                // Start with a small ranked batch for speed, but never discard the
                // rest of the entitlement pool. More candidates are appended only
                // if the first batch cannot establish a verified tunnel.
                val initialBatchSize = 8
                failoverQueue = orderedGuids.take(initialBatchSize)
                failoverReserveQueue = orderedGuids.drop(initialBatchSize)
            }
        }
        val chosen = effectiveQueue.first()
        if (selectionMode == BlueVpnSelectionMode.AUTO) {
            BlueVpnSmartSelector.recordAutomaticConnectionChoice(
                this,
                chosen,
                isolatedCount,
            )
            aiSummaryValue.text = BlueVpnSmartSelector.lastSummary(this)
        }

        failoverIndex = 0
        failoverActive = true
        connectionVerified = false
        attemptedGuid = ""
        waitingForPingResult = false
        healthProbeInProgress = false
        connectButton.isEnabled = false
        statusText.text = if (liveLocationSwitch) "در حال تغییر لوکیشن" else when (selectionMode) {
            BlueVpnSelectionMode.AUTO -> "در حال اتصال به بهترین مسیر"
            BlueVpnSelectionMode.MANUAL_LOCATION -> "اتصال به لوکیشن انتخاب‌شده"
            BlueVpnSelectionMode.MANUAL_SERVER -> "اتصال به سرور انتخاب‌شده"
        }
        statusCaption.text = if (liveLocationSwitch) {
            "اتصال به ${switchTargetTitle.ifBlank { "لوکیشن جدید" }}"
        } else when (selectionMode) {
            BlueVpnSelectionMode.AUTO -> "بهترین مسیر شناخته‌شده فوراً شروع می‌شود؛ بهینه‌سازی بقیه مسیرها در پس‌زمینه انجام می‌شود"
            BlueVpnSelectionMode.MANUAL_LOCATION -> "Failover فقط داخل همین لوکیشن انجام می‌شود"
            BlueVpnSelectionMode.MANUAL_SERVER -> "حالت دستی قفل است؛ Auto این انتخاب را تغییر نمی‌دهد"
        }
        if (SettingsManager.isVpnMode()) {
            val permissionIntent = VpnService.prepare(this)
            if (permissionIntent == null) startCurrentCandidate()
            else requestVpnPermission.launch(permissionIntent)
        } else {
            startCurrentCandidate()
        }
    }

    private fun isExactAttemptRunning(): Boolean =
        mainViewModel.isRunning.value == true && attemptedGuid.isNotBlank()

    private fun startCurrentCandidate() {
        if (!failoverActive) return

        var guid = failoverQueue.getOrNull(failoverIndex)
        if (guid.isNullOrBlank() && failoverReserveQueue.isNotEmpty()) {
            val nextBatch = failoverReserveQueue.take(8)
            failoverReserveQueue = failoverReserveQueue.drop(nextBatch.size)
            failoverQueue = failoverQueue + nextBatch
            guid = failoverQueue.getOrNull(failoverIndex)
        }
        if (guid.isNullOrBlank()) {
            finishFailoverWithError()
            return
        }

        attemptedGuid = guid
        waitingForPingResult = false
        healthProbeInProgress = false
        waitingForCoreStop = false
        coreStopRetryCount = 0
        verificationRound = 0
        verificationDeadlineGuid = ""
        handler.removeCallbacks(requestPing)
        handler.removeCallbacks(attemptTimeout)
        handler.removeCallbacks(verificationTimeout)
        handler.removeCallbacks(coreStopTimeout)

        val profile = MmkvManager.decodeServerConfig(guid)
        val warpBridge = profile != null && BlueVpnWarpEngine.isBridgeGuid(guid, profile)
        if (
            profile == null ||
            guid !in connectionEntitlementGuids ||
            (!warpBridge && !BlueVpnAccountManager.candidateAllowed(
                this,
                guid,
                profile.subscriptionId,
                connectionEntitlementGuids,
            ))
        ) {
            BlueVpnPreferences.markSessionInactive(this, guid)
            BlueVpnPreferences.markServerFailure(this, guid)
            failoverIndex += 1
            handler.post { if (failoverActive) startCurrentCandidate() }
            return
        }
        val location = BlueVpnLocationUtil.detect(profile.remarks, profile.server)
        val locationName = if (warpBridge) {
            "BlueVPN Free"
        } else {
            location?.let { "${it.flag} ${it.title}" } ?: "انتخاب خودکار"
        }

        if (premiumInstantUiEnabled() && !warpBridge) {
            connectingLocation.text = locationName
            renderPremiumInstantConnectedUi(locationName)
        } else {
            showConnectingOverlay(
                title = "در حال اتصال",
                caption = "در حال بررسی و برقراری اتصال امن",
                location = locationName,
            )
            statusText.text = "در حال اتصال"
            statusCaption.visibility = View.VISIBLE
            statusCaption.text = "در حال برقراری اتصال امن"
            updateConnectLabel("لغو اتصال")
            connectButton.isEnabled = true
        }

        // Runtime parity rule: once a profile is imported by v2rayNG and is
        // inside the active entitlement pool, BlueVPN does not reinterpret,
        // pre-validate, recompile, or reject it. The official v2rayNG
        // CoreServiceManager/CoreVpnService/CoreConfigManager chain is the sole
        // authority for whether this exact GUID can start. This intentionally
        // removes the former BlueVPN DNS/TCP/config-hydration gate that could
        // reject profiles which work in stock v2rayNG.
        if (mainViewModel.isRunning.value == true) {
            waitingForCoreStop = true
            coreStopRetryCount = 0
            if (premiumInstantUiEnabled() && !warpBridge) {
                renderPremiumInstantConnectedUi(locationName)
            } else {
                statusText.text = "در حال تغییر اتصال"
                statusCaption.text = "در انتظار توقف کامل اتصال قبلی"
            }
            LauncherManager.stopService(this@BlueVpnHomeActivity)
            handler.removeCallbacks(coreStopTimeout)
            handler.postDelayed(coreStopTimeout, 8_000L)
        } else {
            startExactCandidateCore(guid)
        }
    }

    private fun startExactCandidateCore(guid: String) {
        if (
            !failoverActive ||
            userDisconnecting ||
            waitingForCoreStop ||
            attemptedGuid != guid
        ) return

        val profile = MmkvManager.decodeServerConfig(guid)
        val warpBridge = profile != null && BlueVpnWarpEngine.isBridgeGuid(guid, profile)
        if (
            profile == null ||
            (!warpBridge && !BlueVpnAccountManager.candidateAllowed(this, guid, profile.subscriptionId))
        ) {
            failCurrentAndTryNext("کانفیگ از Pool فعال خارج شده است")
            return
        }

        // BlueVPN owns only the visible location choice. The selected hidden
        // route is committed exactly as stock v2rayNG expects, then the official
        // runtime owns config generation, VPN permission/service startup, TUN,
        // Xray and all protocol/transport semantics end-to-end.
        // Exact stock handoff: upstream startVService(context, guid) selects
        // the GUID itself and owns config generation/VpnService/Xray startup.
        LauncherManager.startService(this, guid)
        handler.postDelayed({
            if (!isFinishing && !isDestroyed) requestDashboardRefresh()
        }, 60L)
        handler.removeCallbacks(attemptTimeout)
        handler.postDelayed(attemptTimeout, 12_000L)
    }

    private fun scheduleConnectionVerification() {
        if (attemptedGuid.isNotBlank() && BlueVpnWarpEngine.isBridgeGuid(attemptedGuid)) BlueVpnWarpEngine.markTunnelVerifying()
        if (
            !failoverActive ||
            userDisconnecting ||
            waitingForCoreStop ||
            healthProbeInProgress
        ) return

        if (verificationDeadlineGuid != attemptedGuid) {
            verificationDeadlineGuid = attemptedGuid
            handler.removeCallbacks(verificationTimeout)
            handler.postDelayed(verificationTimeout, 28_000L)
        }

        // Ask the same upstream v2rayNG core for its native delay proof in
        // parallel with BlueVPN's independent HTTP proof. Either proof may
        // succeed first; a single BlueVPN endpoint failure no longer kills Xray.
        mainViewModel.testCurrentServerRealPing()
        handler.postDelayed({
            if (failoverActive && !waitingForCoreStop) {
                verifyTunnelThroughCore(
                    "این اتصال در چند تست واقعی اینترنت پاسخ نداد"
                )
            }
        }, 180L)
    }

    private fun parseV2rayNgDelayMs(value: String?): Long? {
        val normalized = value.orEmpty().map { ch ->
            when (ch) {
                '۰' -> '0'
                '۱' -> '1'
                '۲' -> '2'
                '۳' -> '3'
                '۴' -> '4'
                '۵' -> '5'
                '۶' -> '6'
                '۷' -> '7'
                '۸' -> '8'
                '۹' -> '9'
                '٠' -> '0'
                '١' -> '1'
                '٢' -> '2'
                '٣' -> '3'
                '٤' -> '4'
                '٥' -> '5'
                '٦' -> '6'
                '٧' -> '7'
                '٨' -> '8'
                '٩' -> '9'
                else -> ch
            }
        }.joinToString("")
        return Regex("""(\d{1,7})\s*ms""", RegexOption.IGNORE_CASE)
            .find(normalized)
            ?.groupValues
            ?.getOrNull(1)
            ?.toLongOrNull()
            ?.takeIf { it >= 0L }
    }

    private fun handlePingResult() {
        // Ping updates are informational only. Connection failover no longer
        // waits for a full ping test, which keeps switching fast.
        requestDashboardRefresh()
    }

    private fun enforceReliableVpnSettings() {
        // BlueVPN must be a device VPN, but all other local proxy/TUN runtime
        // settings remain exactly under upstream v2rayNG ownership.
        MmkvManager.encodeSettings(AppConfig.PREF_MODE, "VPN")
    }

    private fun premiumInstantUiEnabled(): Boolean =
        !BlueVpnEntitlement.resolveUi(this).isFree

    private fun renderPremiumInstantConnectedUi(location: String? = null) {
        if (!premiumInstantUiEnabled() || userDisconnecting) return
        hideConnectingOverlay()
        applyOrbVisual(OrbVisualState.CONNECTED)
        updateConnectLabel("قطع اتصال")
        connectButton.isEnabled = true
        statusText.text = "متصل"
        statusCaption.visibility = View.VISIBLE
        statusCaption.text =
            location?.takeIf { it.isNotBlank() }?.let { "اتصال Premium • $it" }
                ?: "اتصال Premium فعال است"
        statusDot.backgroundTintList =
            ColorStateList.valueOf(Color.parseColor("#35D07F"))
    }

    private fun renderVerifyingState() {
        if (failoverActive) {
            showConnectingOverlay(
                title = "در حال تأیید اتصال",
                caption = "کیفیت اینترنت در پس‌زمینه بررسی می‌شود",
                location = connectingLocation.text.toString().ifBlank { "انتخاب خودکار" },
            )
        }
        updateConnectLabel("لغو اتصال")
        connectButton.isEnabled = true
        applyOrbVisual(OrbVisualState.CONNECTING)
        statusText.text = "در حال تأیید اینترنت"
        statusCaption.text =
            "کیفیت اتصال در پس‌زمینه بررسی می‌شود"
        statusDot.backgroundTintList =
            ColorStateList.valueOf(Color.parseColor("#FFB44A"))
        durationValue.text = "00:00:00"
        downloadSpeed.text = "0 B/s"
        uploadSpeed.text = "0 B/s"
    }

    private fun isThemeConnectionGraceActive(): Boolean =
        SystemClock.elapsedRealtime() < themeConnectionGraceUntil

    private fun verifyExistingRunningSession(
        preserveServiceOnFailure: Boolean = false,
        forceRecoveryOnFailure: Boolean = false,
    ) {
        // Existing-session verification is only valid for a stable, non-terminal
        // running service. A probe launched just before the final failover error
        // may complete later; without these guards it could resurrect the
        // connecting overlay or even mark a failed route CONNECTED.
        if (
            existingSessionCheckInProgress ||
            connectionVerified ||
            terminalFailureStopping ||
            userDisconnecting ||
            failoverActive
        ) return

        existingSessionCheckInProgress = true
        renderVerifyingState()
        mainViewModel.testCurrentServerRealPing()

        lifecycleScope.launch(Dispatchers.IO) {
            val latency = probeInternetThroughCore()

            withContext(Dispatchers.Main) {
                existingSessionCheckInProgress = false

                // The asynchronous probe is stale if a disconnect, a new
                // failover cycle, or a terminal failure happened while it was
                // running. It must have zero authority over UI/connection state.
                if (
                    terminalFailureStopping ||
                    userDisconnecting ||
                    failoverActive ||
                    connectionVerified
                ) {
                    return@withContext
                }

                if (mainViewModel.isRunning.value != true) {
                    existingSessionRetryCount = 0
                    connectionVerified = false
                    BlueVpnPreferences.clearConnected(this@BlueVpnHomeActivity)
                    renderConnectionState(false)
                    return@withContext
                }

                if (latency != null) {
                    existingSessionRetryCount = 0
                    lastVerifiedLatency = latency
                    connectionVerified = true
                    if (attemptedGuid.isNotBlank() && BlueVpnWarpEngine.isBridgeGuid(attemptedGuid)) BlueVpnWarpEngine.markConnected()
                    BlueVpnLiveReporter.kick(this@BlueVpnHomeActivity)
                    BlueVpnPreferences.markConnected(
                        this@BlueVpnHomeActivity,
                        resetTimer = false
                    )
                    BlueVpnRuntimeGate.markConnectionActive(this@BlueVpnHomeActivity)
                    BlueVpnAccountManager.startFreeSession(this@BlueVpnHomeActivity)
                    resetTrafficBaseline()
                    renderConnectionState(true)
                    statusCaption.text =
                        "اتصال امن با موفقیت برقرار شد"
                    recordCurrentConnection(latency)
                    refreshVerifiedExitLocation()
                    return@withContext
                }

                // RUNNING alone is never enough to claim CONNECTED. Keep the
                // service alive for a bounded number of retries, but if neither
                // v2rayNG real-ping nor BlueVPN's end-to-end probe succeeds,
                // perform a clean reconnect through the current entitlement pool.
                connectionVerified = false
                renderVerifyingState()
                statusCaption.text =
                    "هسته Xray فعال است؛ اینترنت واقعی هنوز تأیید نشده"

                val retryLimit = if (preserveServiceOnFailure || isThemeConnectionGraceActive()) 3 else 2
                if (existingSessionRetryCount < retryLimit) {
                    existingSessionRetryCount += 1
                    handler.postDelayed({
                        if (
                            mainViewModel.isRunning.value == true &&
                            !isFinishing &&
                            !isDestroyed &&
                            !connectionVerified &&
                            !terminalFailureStopping &&
                            !userDisconnecting &&
                            !failoverActive
                        ) {
                            verifyExistingRunningSession(
                                preserveServiceOnFailure = preserveServiceOnFailure,
                                forceRecoveryOnFailure = forceRecoveryOnFailure,
                            )
                        }
                    }, 1_800L)
                } else {
                    recoverUnverifiedExistingSession(
                        "اتصال قبلی Xray اجرا بود اما اینترنت واقعی تأیید نشد",
                        forceRestart = forceRecoveryOnFailure,
                    )
                }
            }
        }
    }

    private fun completeExistingSessionVerification(latency: Long) {
        if (
            userDisconnecting ||
            terminalFailureStopping ||
            failoverActive ||
            mainViewModel.isRunning.value != true
        ) return

        existingSessionCheckInProgress = false
        existingSessionRetryCount = 0
        lastVerifiedLatency = latency
        connectionVerified = true
        if (BlueVpnAccountManager.isFreeMode(this) && BlueVpnAccountManager.warpFreeEnabled(this)) {
            BlueVpnWarpKeepAliveService.start(this)
        }
        BlueVpnLiveReporter.kick(this)
        BlueVpnPreferences.markConnected(this, resetTimer = false)
        BlueVpnRuntimeGate.markConnectionActive(this)
        resetTrafficBaseline()
        renderConnectionState(true)
        statusCaption.text = "اتصال امن با موفقیت تأیید شد"
        recordCurrentConnection(latency)
        refreshVerifiedExitLocation()
    }

    private fun recoverUnverifiedExistingSession(
        reason: String,
        forceRestart: Boolean = false,
    ) {
        if (
            userDisconnecting ||
            terminalFailureStopping ||
            failoverActive ||
            isFinishing ||
            isDestroyed
        ) return

        existingSessionCheckInProgress = false
        existingSessionRetryCount = 0

        val freeWarp =
            BlueVpnAccountManager.isFreeMode(this) &&
                BlueVpnAccountManager.warpFreeEnabled(this)
        val transportAlive =
            CoreServiceManager.isRunning() &&
                (!freeWarp || BlueVpnWarpEngine.isRunning())

        if (transportAlive) {
            if (!forceRestart) {
            // End-to-end HTTP/RTT probes are noisy on Iranian mobile networks.
            // They may lower route confidence, but they are not permission to
            // destroy a live TUN/Aether session. Keep traffic uninterrupted.
            connectionVerified = BlueVpnPreferences.connectedAt(this) > 0L
            recoveryCleanupRequired = false
            pendingConnectionRequest = false
            renderConnectionState(true)
            statusCaption.text =
                "$reason؛ اتصال فعال حفظ شد و بررسی بدون قطع اتصال تکرار می‌شود"

            handler.postDelayed({
                val stillFreeWarp =
                    BlueVpnAccountManager.isFreeMode(this) &&
                        BlueVpnAccountManager.warpFreeEnabled(this)
                val stillAlive =
                    CoreServiceManager.isRunning() &&
                        (!stillFreeWarp || BlueVpnWarpEngine.isRunning())
                if (
                    stillAlive &&
                    !isFinishing &&
                    !isDestroyed &&
                    !userDisconnecting &&
                    !terminalFailureStopping &&
                    !failoverActive
                ) {
                    connectionVerified = false
                    verifyExistingRunningSession(preserveServiceOnFailure = true)
                }
            }, 15_000L)
                return
            }
        }

        // Hard recovery is only allowed after actual transport death.
        connectionVerified = false
        BlueVpnPreferences.clearConnected(this)
        BlueVpnRuntimeGate.endConnection(this)
        recoveryCleanupRequired = true
        pendingConnectionRequest = true
        statusText.text = "در حال بازیابی اتصال"
        statusCaption.text = "$reason؛ سرویس اتصال متوقف شده و مسیر سالم دیگری بررسی می‌شود"
        updateConnectLabel("در حال بازیابی")
        connectButton.isEnabled = false
        LauncherManager.stopService(this)
    }

    private fun verifyTunnelThroughCore(reason: String) {
        if (!failoverActive || healthProbeInProgress || waitingForCoreStop) return
        if (!isExactAttemptRunning()) return

        healthProbeInProgress = true
        verificationRound += 1
        val round = verificationRound
        val guid = attemptedGuid

        lifecycleScope.launch(Dispatchers.IO) {
            val latency = probeInternetThroughCore()

            withContext(Dispatchers.Main) {
                if (!failoverActive || attemptedGuid != guid) {
                    healthProbeInProgress = false
                    return@withContext
                }

                healthProbeInProgress = false

                if (latency != null) {
                    lastVerifiedLatency = latency
                    completeFailover(latency)
                    return@withContext
                }

                if (
                    isExactAttemptRunning() &&
                    round < 2
                ) {
                    // Do not classify a v2rayNG-compatible config as dead after a
                    // single BlueVPN probe. Reality/WS/gRPC/TLS can need a longer
                    // warm-up on Iranian mobile networks, and public probe URLs can
                    // be filtered independently of the tunnel itself.
                    statusText.text = "در حال تأیید اینترنت"
                    statusCaption.text = "تست واقعی ${round + 1} از ۲"
                    mainViewModel.testCurrentServerRealPing()
                    handler.postDelayed({
                        if (
                            failoverActive &&
                            attemptedGuid == guid &&
                            mainViewModel.isRunning.value == true
                        ) {
                            verifyTunnelThroughCore(reason)
                        }
                    }, 650L)
                    return@withContext
                }

                // Only after repeated end-to-end failures (plus the upstream
                // v2rayNG delay probe running in parallel) may the route be
                // quarantined for this connection cycle and fail over.
                failCurrentAndTryNext(reason)
            }
        }
    }

    private fun waitForLocalProxyReady(
        httpPort: Int,
        maxWaitMs: Long = 3_000L,
    ): Boolean {
        val deadline = SystemClock.elapsedRealtime() + maxWaitMs
        do {
            val ready = runCatching {
                Socket().use { socket ->
                    socket.tcpNoDelay = true
                    socket.connect(
                        InetSocketAddress("127.0.0.1", httpPort),
                        220,
                    )
                }
                true
            }.getOrDefault(false)
            if (ready) return true
            if (SystemClock.elapsedRealtime() >= deadline) break
            runCatching { Thread.sleep(120L) }
        } while (!Thread.currentThread().isInterrupted)
        return false
    }

    private suspend fun probeInternetThroughCore(): Long? =
        withContext(Dispatchers.IO) {
            // Use the upstream canonical HTTP port for HTTP probes. Keep the
            // SOCKS port only as a short compatibility fallback; never spend a
            // second full probe window on the same local inbound.
            val httpPort = SettingsManager.getHttpPort()
            val socksPort = SettingsManager.getSocksPort()
            val localPort = httpPort

            if (localPort !in 1..65535) {
                return@withContext null
            }

            // CoreService RUNNING can be emitted slightly before the local inbound
            // accepts sockets. Give REALITY/TLS/WS/gRPC cold starts a realistic
            // bounded window instead of rejecting an otherwise valid profile.
            if (!waitForLocalProxyReady(localPort)) {
                return@withContext null
            }

            val warpBridge = attemptedGuid.isNotBlank() && BlueVpnWarpEngine.isBridgeGuid(attemptedGuid)
            if (warpBridge) {
                val policy = BlueVpnAccountManager.freeAccessSnapshot(this@BlueVpnHomeActivity)
                val trace = fetchExitTraceThroughLocalXray(localPort)
                if (policy.warpRequireExitTrace && trace == null) return@withContext null
                val country = trace?.lineSequence()
                    ?.firstOrNull { it.startsWith("loc=") }
                    ?.substringAfter("loc=")
                    ?.trim()
                    ?.uppercase(Locale.US)
                    ?.takeIf { it.matches(Regex("[A-Z]{2}")) }
                if (country != null && country in policy.warpBlockedExitCountries) {
                    return@withContext null
                }
            }

            val endpoints = buildList {
                // IRCF-style adaptive test targets are supplemental only. Every
                // success is still a real HTTP request through the local Xray
                // tunnel; bundled probes remain as deterministic fallback.
                BlueVpnIrcfIntelligence.adaptiveProbeUrls(this@BlueVpnHomeActivity)
                    .take(3)
                    .forEach { url -> add(url) }
                add("https://www.google.com/generate_204")
                add("https://cp.cloudflare.com/generate_204")
                add("http://connectivitycheck.gstatic.com/generate_204")
                add("https://1.1.1.1/cdn-cgi/trace")
                BlueVpnAccountManager.apiBaseUrls().forEach { base -> add("$base/health") }
                if (!BlueVpnPerformance.isLowEnd(this@BlueVpnHomeActivity)) {
                    add("https://check-host.net/cdn-cgi/trace")
                }
            }.distinct()

            fun race(proxyType: Proxy.Type): Long? {
                    val executor = Executors.newFixedThreadPool(
                        BlueVpnPerformance.maxProbeWorkers(this@BlueVpnHomeActivity)
                            .coerceAtMost(endpoints.size)
                            .coerceAtLeast(1)
                    )
                    val completion = ExecutorCompletionService<Long?>(executor)
                    val futures = endpoints.map { endpoint ->
                        completion.submit {
                            requestThroughLocalXrayProxy(
                                endpoint = endpoint,
                                localPort = localPort,
                                proxyType = proxyType,
                            )
                        }
                    }

                    try {
                        val deadline = SystemClock.elapsedRealtime() + if (proxyType == Proxy.Type.HTTP) 3_200L else 2_200L
                        repeat(futures.size) {
                            val remaining = (
                                deadline - SystemClock.elapsedRealtime()
                            ).coerceAtLeast(1L)
                            val completed = completion.poll(
                                remaining,
                                TimeUnit.MILLISECONDS,
                            ) ?: return null

                            val latency = runCatching { completed.get() }.getOrNull()
                            if (latency != null) return latency
                        }
                        return null
                    } finally {
                        futures.forEach { it.cancel(true) }
                        executor.shutdownNow()
                    }
            }

            // HTTP semantics are what upstream v2rayNG uses for its own local
            // requests. Xray's SOCKS inbound is HTTP-compatible. SOCKS is only a
            // compatibility fallback for devices/cores where HTTP proxy handling
            // behaves differently; it is not treated as a separate route.
            val firstSuccess = race(Proxy.Type.HTTP) ?: run {
                val username = SettingsManager.getSocksUsername()
                val password = SettingsManager.getSocksPassword()
                if (username.isNullOrBlank() && password.isNullOrBlank()) {
                    if (socksPort in 1..65535) race(Proxy.Type.SOCKS) else null
                } else {
                    null
                }
            }
            if (firstSuccess == null) return@withContext null

            // A handover can briefly leave a local Xray socket alive while the
            // physical route is still converging. During that short window only,
            // require a second independent HTTP success before CONNECTED. This
            // suppresses false-connected states without slowing normal connects.
            if (BlueVpnNetworkRecoveryManager.recoveryWindowActive(this@BlueVpnHomeActivity)) {
                runCatching { Thread.sleep(180L) }
                val confirmation = requestThroughLocalXrayProxy(
                    endpoint = "https://cp.cloudflare.com/generate_204",
                    localPort = localPort,
                    proxyType = Proxy.Type.HTTP,
                ) ?: run {
                    val username = SettingsManager.getSocksUsername()
                    val password = SettingsManager.getSocksPassword()
                    if (username.isNullOrBlank() && password.isNullOrBlank()) {
                        requestThroughLocalXrayProxy(
                            endpoint = "https://cp.cloudflare.com/generate_204",
                            localPort = localPort,
                            proxyType = Proxy.Type.SOCKS,
                        )
                    } else null
                } ?: return@withContext null
                return@withContext maxOf(firstSuccess, confirmation)
            }
            firstSuccess
        }

    private fun fetchExitTraceThroughLocalXray(localPort: Int): String? {
        val proxyTypes = buildList {
            add(Proxy.Type.HTTP)
            val username = SettingsManager.getSocksUsername()
            val password = SettingsManager.getSocksPassword()
            if (username.isNullOrBlank() && password.isNullOrBlank()) add(Proxy.Type.SOCKS)
        }
        for (proxyType in proxyTypes) {
            val body = runCatching {
                val proxy = Proxy(proxyType, InetSocketAddress("127.0.0.1", localPort))
                val connection = URL("https://1.1.1.1/cdn-cgi/trace").openConnection(proxy) as HttpURLConnection
                try {
                    connection.instanceFollowRedirects = false
                    connection.connectTimeout = 2_100
                    connection.readTimeout = 2_100
                    connection.requestMethod = "GET"
                    connection.useCaches = false
                    connection.setRequestProperty("Connection", "close")
                    connection.setRequestProperty("User-Agent", "BlueVPN/${BuildConfig.VERSION_NAME}")
                    if (proxyType == Proxy.Type.HTTP) {
                        val username = SettingsManager.getSocksUsername()
                        val password = SettingsManager.getSocksPassword()
                        if (!username.isNullOrBlank() && !password.isNullOrBlank()) {
                            val token = Base64.encodeToString("$username:$password".toByteArray(Charsets.UTF_8), Base64.NO_WRAP)
                            connection.setRequestProperty("Proxy-Authorization", "Basic $token")
                        }
                    }
                    if (connection.responseCode !in 200..299) null
                    else connection.inputStream.bufferedReader().use { it.readText().take(4096) }
                } finally {
                    connection.disconnect()
                }
            }.getOrNull()
            if (!body.isNullOrBlank()) {
                BlueVpnRouteIntelligence.recordExitTrace(this, attemptedGuid, body)
                return body
            }
        }
        return null
    }

    private fun requestThroughLocalXrayProxy(
        endpoint: String,
        localPort: Int,
        proxyType: Proxy.Type = Proxy.Type.HTTP,
        countryGuid: String = attemptedGuid,
    ): Long? =
        runCatching {
            val proxy = Proxy(
                proxyType,
                InetSocketAddress("127.0.0.1", localPort)
            )
            val connection = URL(endpoint).openConnection(proxy) as HttpURLConnection
            val startedAt = SystemClock.elapsedRealtime()

            try {
                connection.instanceFollowRedirects = false
                connection.connectTimeout = 2_100
                connection.readTimeout = 2_100
                connection.requestMethod = "GET"
                connection.useCaches = false
                connection.setRequestProperty("Connection", "close")
                connection.setRequestProperty(
                    "User-Agent",
                    "BlueVPN/${BuildConfig.VERSION_NAME}"
                )

                // Xray applies the SOCKS username/password to HTTP requests sent
                // to the same local inbound. Preserve that upstream-compatible
                // behavior instead of falsely classifying authenticated local
                // proxies as dead.
                if (proxyType == Proxy.Type.HTTP) {
                    val username = SettingsManager.getSocksUsername()
                    val password = SettingsManager.getSocksPassword()
                    if (!username.isNullOrBlank() && !password.isNullOrBlank()) {
                        val token = Base64.encodeToString(
                            "$username:$password".toByteArray(Charsets.UTF_8),
                            Base64.NO_WRAP,
                        )
                        connection.setRequestProperty(
                            "Proxy-Authorization",
                            "Basic $token",
                        )
                    }
                }

                val code = connection.responseCode
                val body = if (code in 200..499) {
                    runCatching {
                        val stream = if (code >= 400) connection.errorStream else connection.inputStream
                        stream?.bufferedReader()?.use { it.readText().take(4096) }.orEmpty()
                    }.getOrDefault("")
                } else {
                    ""
                }

                // A real remote 2xx/3xx/4xx response proves that the selected
                // Xray route can reach the Internet. Do not reject a working VPN
                // merely because a public generate_204/trace endpoint changed its
                // body or returned a policy status. 407 is local proxy auth failure;
                // 5xx is kept inconclusive because some local proxy errors use it.
                val valid = code in 200..499 && code != 407

                if (valid) {
                    if (endpoint.contains("/cdn-cgi/trace") && body.isNotBlank()) {
                        BlueVpnRouteIntelligence.recordExitTrace(
                            this@BlueVpnHomeActivity,
                            countryGuid,
                            body,
                        )
                        body.lineSequence()
                            .firstOrNull { it.startsWith("loc=") }
                            ?.substringAfter("loc=")
                            ?.trim()
                            ?.takeIf { it.length == 2 }
                            ?.let { countryCode ->
                                BlueVpnPreferences.markVerifiedCountry(
                                    this@BlueVpnHomeActivity,
                                    countryGuid,
                                    countryCode,
                                )
                                BlueVpnLocationUtil.invalidateCache()
                            }
                    }
                    SystemClock.elapsedRealtime() - startedAt
                } else {
                    null
                }
            } finally {
                connection.disconnect()
            }
        }.getOrNull()

    private fun completeFailover(delay: Long?) {
        if (!failoverActive || userDisconnecting) return

        val completedLiveSwitch = liveLocationSwitch
        val completedTargetTitle = switchTargetTitle
        if (completedLiveSwitch) handoverState.connected()

        handler.removeCallbacks(requestPing)
        handler.removeCallbacks(attemptTimeout)
        handler.removeCallbacks(verificationTimeout)
        handler.removeCallbacks(coreStopTimeout)
        verificationDeadlineGuid = ""
        BlueVpnPreferences.markServerSuccess(
            this,
            attemptedGuid,
            delay ?: lastVerifiedLatency,
        )
        BlueVpnRouteIntelligence.recordSuccess(
            this,
            attemptedGuid,
            (delay ?: lastVerifiedLatency).coerceAtLeast(1L),
        )
        BlueVpnIntelligenceCore.resolveDecision(
            context = this,
            guid = attemptedGuid,
            success = true,
            latencyMs = (delay ?: lastVerifiedLatency).coerceAtLeast(1L),
        )

        failoverActive = false
        failoverReserveQueue = emptyList()
        runtimeGateWaitStartedAt = 0L
        waitingForPingResult = false
        healthProbeInProgress = false
        waitingForCoreStop = false
        coreStopRetryCount = 0
        verificationRound = 0
        liveLocationSwitch = false
        switchTargetTitle = ""
        connectButton.isEnabled = false

        val verifiedDelay = delay ?: lastVerifiedLatency
        val isFreeConnection = !completedLiveSwitch && BlueVpnEntitlement.resolveUi(this).isFree
        if (isFreeConnection) {
            beginFreeStoryGate(
                verifiedDelay = verifiedDelay,
                completedLiveSwitch = completedLiveSwitch,
                completedTargetTitle = completedTargetTitle,
            )
            return
        }

        finalizeSuccessfulConnection(
            verifiedDelay = verifiedDelay,
            completedLiveSwitch = completedLiveSwitch,
            completedTargetTitle = completedTargetTitle,
            storyAdShown = false,
        )
    }

    private fun beginFreeStoryGate(
        verifiedDelay: Long,
        completedLiveSwitch: Boolean,
        completedTargetTitle: String,
    ) {
        if (userDisconnecting || mainViewModel.isRunning.value != true) {
            finalizeSuccessfulConnection(
                verifiedDelay,
                completedLiveSwitch,
                completedTargetTitle,
                storyAdShown = false,
            )
            return
        }

        // Commit VPN state first. Advertising is always post-connect UI.
        finalizeSuccessfulConnection(
            verifiedDelay,
            completedLiveSwitch,
            completedTargetTitle,
            storyAdShown = false,
        )

        if (
            userDisconnecting ||
            mainViewModel.isRunning.value != true ||
            !connectionVerified
        ) {
            return
        }

        val sessionId = BlueVpnPreferences.connectedAt(this)

        // Tapsell Mediation is primary when configured. The first-party story
        // is used only when Tapsell is disabled, misconfigured, no-fill, init
        // fails/times out, or an ad cannot be shown.
        BlueVpnTapsellManager.onVerifiedConnection(
            activity = this,
            sessionId = sessionId,
            onUnavailable = {
                if (
                    !userDisconnecting &&
                    mainViewModel.isRunning.value == true &&
                    connectionVerified &&
                    BlueVpnPreferences.connectedAt(this) == sessionId
                ) {
                    showFirstPartyFreeStory()
                }
            },
        )
    }

    private fun showFirstPartyFreeStory() {
        if (
            userDisconnecting ||
            mainViewModel.isRunning.value != true ||
            !connectionVerified ||
            freeStoryGateActive
        ) return

        freeStoryGate?.release()
        freeStoryGateActive = true

        val gate = BlueVpnFreeStoryAdGate(this)
        freeStoryGate = gate
        gate.start { outcome ->
            if (freeStoryGate !== gate) return@start
            freeStoryGate = null
            freeStoryGateActive = false

            when (outcome) {
                BlueVpnFreeStoryAdGate.Outcome.COMPLETED -> {
                    if (mainViewModel.isRunning.value == true && connectionVerified) {
                        statusCaption.visibility = View.VISIBLE
                        statusCaption.text = "تبلیغ کامل شد؛ اتصال امن برقرار است"
                    }
                }

                BlueVpnFreeStoryAdGate.Outcome.UNAVAILABLE -> {
                    // Both providers unavailable: fail-open, VPN stays connected.
                }

                BlueVpnFreeStoryAdGate.Outcome.ABORTED -> {
                    // Dismiss/background never affects VPN.
                }

                BlueVpnFreeStoryAdGate.Outcome.ACTION_OPENED -> {
                    // CTA navigation never affects VPN.
                }
            }
        }
    }

    private fun maybePromptBackgroundReliability() {
        if (!BlueVpnBackgroundReliability.shouldPrompt(this) || isFinishing || isDestroyed) return
        BlueVpnBackgroundReliability.markPromptShown(this)
        val state = BlueVpnBackgroundReliability.state(this)

        AlertDialog.Builder(this)
            .setTitle("جلوگیری از قطع اتصال در پس‌زمینه")
            .setMessage(
                buildString {
                    append("برای اینکه BlueVPN بعد از خروج از برنامه قطع نشود، محدودیت‌های پس‌زمینه را بررسی کنید.\n\n")
                    append("باتری: ")
                    append(if (state.batteryUnrestricted) "مناسب" else "محدود")
                    append("\nداده پس‌زمینه: ")
                    append(if (state.backgroundDataUnrestricted) "مناسب" else "محدود")
                }
            )
            .setPositiveButton("تنظیم داده") { _, _ ->
                BlueVpnBackgroundReliability.openBackgroundDataSettings(this)
            }
            .setNeutralButton("تنظیم باتری") { _, _ ->
                BlueVpnBackgroundReliability.openBatterySettings(this)
            }
            .setNegativeButton("بعداً", null)
            .show()
    }

    private fun finalizeSuccessfulConnection(
        verifiedDelay: Long,
        completedLiveSwitch: Boolean,
        completedTargetTitle: String,
        storyAdShown: Boolean,
    ) {
        if (userDisconnecting || mainViewModel.isRunning.value != true) return

        BlueVpnPreferences.markConnected(this, resetTimer = true)
        BlueVpnRuntimeGate.markConnectionActive(this)
        if (BlueVpnAccountManager.isFreeMode(this) && BlueVpnAccountManager.warpFreeEnabled(this)) {
            BlueVpnWarpKeepAliveService.start(this)
        }
        BlueVpnAccountManager.startFreeSession(this)
        connectionVerified = true
        if (completedLiveSwitch) handoverState.connected()
        if (attemptedGuid.isNotBlank() && BlueVpnWarpEngine.isBridgeGuid(attemptedGuid)) BlueVpnWarpEngine.markConnected()
        BlueVpnLiveReporter.kick(this)
        connectButton.isEnabled = true
        resetTrafficBaseline()
        requestDashboardRefresh()
        hideConnectingOverlay()
        renderConnectionState(true)
        updateFreeTimerBadge()
        mainViewModel.testCurrentServerRealPing()

        recordCurrentConnection(verifiedDelay)
        refreshVerifiedExitLocation()
        maybePromptBackgroundReliability()
        statusCaption.text =
            if (completedLiveSwitch) {
                "مکان اتصال با موفقیت تغییر کرد"
            } else if (storyAdShown) {
                "تبلیغ کامل شد؛ اتصال رایگان فعال است"
            } else {
                "اتصال امن برقرار شد و پایش خودکار فعال است"
            }

    }

    private fun refreshVerifiedExitLocation() {
        val port = SettingsManager.getSocksPort()
        val guid = attemptedGuid
        if (port !in 1..65535 || guid.isBlank()) return
        lifecycleScope.launch(Dispatchers.IO) {
            val resolved = requestThroughLocalXrayProxy(
                endpoint = "https://check-host.net/cdn-cgi/trace",
                localPort = port,
                countryGuid = guid,
            ) ?: requestThroughLocalXrayProxy(
                endpoint = "http://1.1.1.1/cdn-cgi/trace",
                localPort = port,
                countryGuid = guid,
            )
            if (resolved != null) {
                BlueVpnLocationUtil.syncCloudLocations(
                    this@BlueVpnHomeActivity,
                    force = true,
                )
            }
            withContext(Dispatchers.Main) {
                if (!isFinishing && !isDestroyed) {
                    requestDashboardRefresh(force = true)
                }
            }
        }
    }

    private fun failCurrentAndTryNext(reason: String) {
        if (!failoverActive || userDisconnecting) return

        lastCandidateFailureReason = reason.trim().ifBlank { "اتصال فعلی پاسخ نداد" }
        val failedGuid = attemptedGuid
        lifecycleScope.launch(Dispatchers.IO) {
            BlueVpnAi.recordFailure(
                this@BlueVpnHomeActivity,
                failedGuid,
                reason,
            )
        }

        handler.removeCallbacks(requestPing)
        handler.removeCallbacks(attemptTimeout)
        handler.removeCallbacks(verificationTimeout)
        handler.removeCallbacks(coreStopTimeout)
        verificationDeadlineGuid = ""

        if (failedGuid.isNotBlank()) {
            // Hard quarantine for this connect cycle. The next explicit connect
            // attempt clears only this temporary flag, while failedRecently()
            // keeps a short-lived score penalty so the same route is not picked
            // first again immediately.
            BlueVpnPreferences.markSessionInactive(this, failedGuid)
            BlueVpnPreferences.markServerFailure(this, failedGuid)
            BlueVpnRouteIntelligence.recordFailure(this, failedGuid, reason)
            BlueVpnIntelligenceCore.resolveDecision(
                context = this,
                guid = failedGuid,
                success = false,
                failureReason = reason,
            )
            MmkvManager.encodeServerTestDelayMillis(failedGuid, -1L)
        }

        LauncherManager.stopService(this)
        val failedWasWarpBridge = failedGuid.isNotBlank() && BlueVpnWarpEngine.isBridgeGuid(failedGuid)
        if (failedWasWarpBridge && BlueVpnAccountManager.warpFallbackEnabled(this)) {
            BlueVpnWarpEngine.markFallback()
            warpFallbackGeneration = connectionPreparationGeneration
            failoverActive = false
            failoverQueue = emptyList()
            failoverReserveQueue = emptyList()
            connectionEntitlementGuids = emptySet()
            attemptedGuid = ""
            connectButton.isEnabled = true
            statusText.text = "در حال انتقال به مسیر پشتیبان"
            statusCaption.text = "WARP در تأیید نهایی ناموفق بود • Pool رایگان بررسی می‌شود"
            lifecycleScope.launch(Dispatchers.IO) {
                BlueVpnWarpEngine.stopAsync()
                withContext(Dispatchers.Main) {
                    if (!isFinishing && warpFallbackGeneration == connectionPreparationGeneration) {
                        beginSmartConnection()
                    }
                }
            }
            return
        }
        failoverIndex += 1
        waitingForPingResult = false
        healthProbeInProgress = false
        waitingForCoreStop = false
        coreStopRetryCount = 0
        verificationRound = 0

        if (failoverIndex >= failoverQueue.size && failoverReserveQueue.isNotEmpty()) {
            // Progressive AUTO failover: keep first connection fast while still
            // guaranteeing that lower-ranked but valid configs are eventually
            // tried before the location/pool is declared unavailable.
            val nextBatch = failoverReserveQueue.take(8)
            failoverReserveQueue = failoverReserveQueue.drop(nextBatch.size)
            failoverQueue = failoverQueue + nextBatch
        }

        if (failoverIndex >= failoverQueue.size) {
            finishFailoverWithError(lastCandidateFailureReason)
            return
        }

        if (premiumInstantUiEnabled()) {
            renderPremiumInstantConnectedUi("انتخاب هوشمند")
        } else {
            statusText.text = "تغییر اتصال خودکار"
            statusCaption.text = "گزینه بهتر به‌صورت خودکار در حال بررسی است"
            showConnectingOverlay(
                title = "در حال تغییر اتصال",
                caption = "اتصال قبلی پاسخ نداد؛ گزینه بعدی بررسی می‌شود",
                location = "انتخاب خودکار",
            )
        }

        // START_FAILURE can be broadcast a little before v2rayNG/Xray finishes
        // releasing its process and TUN resources. Starting the next GUID after
        // only 250 ms made one malformed JSON profile poison the entire queue:
        // every following route hit the still-closing service and was marked
        // failed as well. Config-parse failures therefore get a bounded drain
        // window; the bad GUID remains quarantined and the next entitled route
        // is still tried automatically.
        val retryDelayMs = if (
            reason.contains("کانفیگ این مسیر نامعتبر بود") ||
            reason.contains("failed to parse json", ignoreCase = true)
        ) 900L else 650L
        handler.postDelayed({
            if (failoverActive) startCurrentCandidate()
        }, retryDelayMs)
    }

    private fun finishFailoverWithError(reason: String? = null) {
        val failedLiveSwitch = liveLocationSwitch || handoverState.isSwitching()
        hideConnectingOverlay()
        handler.removeCallbacks(requestPing)
        handler.removeCallbacks(attemptTimeout)
        handler.removeCallbacks(verificationTimeout)
        handler.removeCallbacks(coreStopTimeout)
        verificationDeadlineGuid = ""

        val finalReason = reason?.trim().takeUnless { it.isNullOrBlank() }
            ?: lastCandidateFailureReason.takeIf { it.isNotBlank() }
            ?: "این لوکیشن فعلاً پاسخ نداد؛ بعداً دوباره امتحان کنید"
        terminalFailureReason = finalReason

        failoverActive = false
        failoverReserveQueue = emptyList()
        pendingConnectionRequest = false
        runtimeGateWaitStartedAt = 0L
        waitingForPingResult = false
        healthProbeInProgress = false
        waitingForCoreStop = false
        coreStopRetryCount = 0
        verificationRound = 0
        connectionVerified = false
        if (failedLiveSwitch) handoverState.failed()
        liveLocationSwitch = false
        switchTargetTitle = ""
        connectionEntitlementGuids = emptySet()
        BlueVpnPreferences.clearConnected(this)

        // Keep RuntimeGate ownership until the daemon confirms that Xray/TUN is
        // actually stopped. Releasing it here used to allow a subscription
        // mutation to race the still-running final candidate.
        terminalFailureStopping = mainViewModel.isRunning.value == true
        if (terminalFailureStopping) {
            LauncherManager.stopService(this)
        }
        if (BlueVpnWarpEngine.isRunning()) {
            lifecycleScope.launch(Dispatchers.IO) { BlueVpnWarpEngine.stop() }
        }
        if (!terminalFailureStopping) {
            BlueVpnRuntimeGate.endConnection(this)
            if (failedLiveSwitch) handoverState.disconnected()
        }

        connectButton.isEnabled = !terminalFailureStopping
        updateConnectLabel(if (terminalFailureStopping) "در حال توقف" else "تلاش دوباره")
        applyOrbVisual(OrbVisualState.ERROR)
        statusText.text = "لوکیشن در دسترس نیست"
        statusCaption.visibility = View.VISIBLE
        statusCaption.text = if (failedLiveSwitch) {
            "$finalReason • اتصال قبلی بازگردانی نشد"
        } else {
            finalReason
        }
        statusDot.backgroundTintList =
            ColorStateList.valueOf(Color.parseColor("#FFB44A"))

        Toast.makeText(
            this,
            finalReason,
            Toast.LENGTH_LONG
        ).show()
    }

    private fun cancelFailover() {
        // Connection ownership is released only after CoreVpnService reports
        networkSweepGeneration += 1
        networkSweepInProgress = false
        networkSweepPollInFlight = false
        handler.removeCallbacks(networkSweepTicker)
        MessageHelper.sendMsg2TestService(
            this,
            TestServiceMessage(key = AppConfig.MSG_MEASURE_CONFIG_CANCEL),
        )
        // NOT_RUNNING/STOP_SUCCESS. If no core is active, releasing immediately
        // is safe (for example VPN permission denial before the first start).
        if (mainViewModel.isRunning.value != true) {
            BlueVpnRuntimeGate.endConnection(this)
        }
        hideConnectingOverlay()
        handler.removeCallbacks(requestPing)
        handler.removeCallbacks(attemptTimeout)
        handler.removeCallbacks(coreStopTimeout)
        failoverActive = false
        pendingConnectionRequest = false
        runtimeGateWaitStartedAt = 0L
        failoverQueue = emptyList()
        failoverReserveQueue = emptyList()
        connectionEntitlementGuids = emptySet()
        failoverIndex = -1
        attemptedGuid = ""
        waitingForPingResult = false
        healthProbeInProgress = false
        waitingForCoreStop = false
        coreStopRetryCount = 0
        verificationRound = 0
        connectionVerified = false
        liveLocationSwitch = false
        switchTargetTitle = ""
        handoverState.disconnected()
        BlueVpnPreferences.clearConnected(this)
        connectButton.isEnabled = true
    }

    private fun renderConnectionState(connected: Boolean) {
        if (failoverActive) {
            showConnectingOverlay(
                title = "در حال اتصال",
                caption = "بهترین اتصال به‌صورت خودکار در حال بررسی است",
                location = "انتخاب خودکار",
            )
            updateConnectLabel("لغو اتصال")
            connectButton.isEnabled = true
            applyOrbVisual(OrbVisualState.CONNECTING)
            statusText.text = "بررسی بهترین اتصال"
            statusDot.backgroundTintList =
                ColorStateList.valueOf(Color.parseColor("#FFB44A"))
            updateLiveStats()
            return
        }

        connectButton.isEnabled = true

        if (connected) {
            if (!connectionVerified) {
                renderVerifyingState()
                return
            }

            hideConnectingOverlay()
            updateConnectLabel("قطع اتصال")
            applyOrbVisual(OrbVisualState.CONNECTED)
            statusText.text = "متصل هستید"
            statusCaption.text = ""
            statusCaption.visibility = View.GONE
            statusDot.backgroundTintList =
                ColorStateList.valueOf(Color.parseColor("#36E6A7"))
            updateFreeTimerBadge()
        } else {
            hideConnectingOverlay()
            BlueVpnPreferences.clearConnected(this)
            connectionVerified = false
            lastHistoryGuid = ""
            resetTrafficBaseline()

            updateConnectLabel("اتصال")
            applyOrbVisual(OrbVisualState.IDLE)
            statusText.text = "آماده اتصال"
            statusCaption.visibility = View.VISIBLE
            statusCaption.text = "بهترین اتصال به‌صورت خودکار انتخاب می‌شود"
            statusDot.backgroundTintList =
                ColorStateList.valueOf(Color.parseColor("#8FA7CA"))
        }

        updateLiveStats()
    }

    private fun warmCandidatesThenRefresh(force: Boolean) {
        candidateWarmupForcePending = candidateWarmupForcePending || force
        if (candidateWarmupInProgress) return
        candidateWarmupInProgress = true
        val requestedForce = candidateWarmupForcePending
        candidateWarmupForcePending = false
        lifecycleScope.launch(Dispatchers.Default) {
            BlueVpnLocationUtil.allCandidates(
                this@BlueVpnHomeActivity,
                forceRefresh = requestedForce,
            )
            withContext(Dispatchers.Main) {
                candidateWarmupInProgress = false
                if (!isFinishing && !isDestroyed) {
                    requestDashboardRefresh(force = true)
                    if (candidateWarmupForcePending) {
                        warmCandidatesThenRefresh(force = candidateWarmupForcePending)
                    }
                }
            }
        }
    }

    private fun refreshDashboard(
        force: Boolean = false,
    ) {
        val now = SystemClock.elapsedRealtime()
        if (!force && now - lastDashboardRefreshAt < 220L) {
            return
        }
        lastDashboardRefreshAt = now

        val candidates = BlueVpnLocationUtil.cachedCandidates(this)
        val entitlement = BlueVpnEntitlement.resolveUi(this)
        val selectedGuid = MmkvManager.getSelectServer().orEmpty().trim()
        val selected = candidates.firstOrNull {
            it.guid == selectedGuid
        }
        val selectedProfile = selected?.profile ?: selectedGuid
            .takeIf { it.isNotBlank() }
            ?.let { MmkvManager.decodeServerConfig(it) }
        val selectedAllowed = selected != null || selectedProfile?.let { profile ->
            BlueVpnAccountManager.selectedServerAllowedUi(
                this,
                selectedGuid,
                profile.subscriptionId,
                entitlement.serverGuids,
            )
        } == true
        val profile = selected?.profile ?: selectedProfile?.takeIf { selectedAllowed }
        val automaticSelection =
            BlueVpnPreferences.smartBalance(this)
        val freeWarpFailed = entitlement.isFree && BlueVpnWarpEngine.state == BlueVpnWarpEngine.State.FAILED

        if (freeWarpFailed) {
            // Do not present a stale cached location as "ready" when the WARP
            // runtime itself failed. The cached route may be reused only after
            // a subsequent successful WARP preparation.
            serverName.text = "انتخاب خودکار سرور"
            serverMeta.text = "اتصال رایگان آماده نیست • مسیر قبلی موقتاً کنار گذاشته شد"
            serverStatusValue.text = "برای تلاش مجدد، دوباره اتصال را بزنید"
            locationValue.text = "—"
            pingValue.text = "—"
        } else if (profile == null || !BlueVpnLocationUtil.isUsable(profile)) {
            serverName.text =
                if (automaticSelection) {
                    "انتخاب خودکار سرور"
                } else {
                    "انتخاب لوکیشن"
                }
            serverMeta.text =
                if (automaticSelection) {
                    "بهترین سرور از همه لوکیشن‌ها انتخاب می‌شود"
                } else {
                    "برای مشاهده لوکیشن‌ها لمس کنید"
                }
            locationValue.text = "—"
            pingValue.text = "—"
            serverStatusValue.text = "یک لوکیشن انتخاب کنید؛ مسیرها پشت همان لوکیشن مدیریت می‌شوند"
        } else {
            val location = selected?.location
                ?: BlueVpnLocationUtil.detect(profile.remarks, profile.server)
            val delay = selected?.delay ?: 0L
            serverName.text =
                if (automaticSelection) {
                    "انتخاب خودکار سرور"
                } else {
                    "${location.flag} ${location.title}"
                }

            serverMeta.text =
                if (automaticSelection) {
                    "${location.flag} ${location.title} • انتخاب هوشمند"
                } else {
                    when {
                        failoverActive -> "در حال بررسی بهترین اتصال"
                        delay > 0L -> "آماده اتصال"
                        else -> "در انتظار بررسی"
                    }
                }

            locationValue.text = "${location.flag} ${location.title}"
            pingValue.text = when {
                delay > 0L -> "${delay} ms"
                delay < 0L -> "ناموفق"
                else -> "تست نشده"
            }
            serverStatusValue.text = when {
                mainViewModel.isRunning.value == true && connectionVerified ->
                    "متصل • مسیر فعال در پس‌زمینه مدیریت می‌شود"
                failoverActive ->
                    "در حال بررسی مسیرهای مخفی ${location.title}"
                delay > 0L ->
                    "آماده اتصال • پاسخ ${delay} ms"
                automaticSelection ->
                    "آماده اتصال • انتخاب مسیر کاملاً خودکار است"
                else ->
                    "آماده اتصال • مسیرهای این لوکیشن مخفی هستند"
            }
        }

        val locationCount = candidates
            .map { it.location.key }
            .distinct()
            .size
        val subscriptionCount = candidates
            .mapNotNull { it.profile.subscriptionId }
            .distinct()
            .size

        applyEntitlementPresentation(entitlement)
        subscriptionSummary.text = when (entitlement.tier) {
            BlueVpnPlanTier.PREMIUM ->
                "${entitlement.accountLabel} • $locationCount لوکیشن"
            BlueVpnPlanTier.FREE ->
                "${entitlement.accountLabel} • هر اتصال ${entitlement.sessionMinutes} دقیقه"
            BlueVpnPlanTier.UNAVAILABLE -> entitlement.accountLabel
        }

        refreshExperienceDashboard(candidates)
    }

    private fun readTunnelTrafficBytes(): Pair<Long, Long> {
        val uid = applicationInfo.uid
        val rx = TrafficStats.getUidRxBytes(uid)
        val tx = TrafficStats.getUidTxBytes(uid)
        return Pair(
            rx.takeIf { it >= 0L } ?: 0L,
            tx.takeIf { it >= 0L } ?: 0L,
        )
    }

    private fun updateLiveStats() {
        val connected = mainViewModel.isRunning.value == true &&
            !failoverActive &&
            connectionVerified

        if (!connected) {
            downloadSpeed.text = "0 B/s"
            uploadSpeed.text = "0 B/s"
            if (BlueVpnEntitlement.resolveUi(this).isFree) {
                updateFreeTimerBadge()
            } else {
                if (::durationMetricLabel.isInitialized) {
                    durationMetricLabel.text = "مدت اتصال"
                }
                durationValue.text = "00:00:00"
            }
            return
        }

        val startedAt =
            BlueVpnPreferences.connectedAt(this).takeIf { it > 0L }
                ?: System.currentTimeMillis().also {
                    BlueVpnPreferences.markConnected(
                        this,
                        resetTimer = true
                    )
                }
        val elapsedSeconds = max(
            0L,
            (System.currentTimeMillis() - startedAt) / 1000L
        )
        val entitlement = BlueVpnEntitlement.resolveUi(this)
        if (entitlement.isFree) {
            updateFreeTimerBadge()
        } else {
            if (::durationMetricLabel.isInitialized) {
                durationMetricLabel.text = "مدت اتصال"
            }
            durationValue.text = formatDuration(elapsedSeconds)
        }

        val now = SystemClock.elapsedRealtime()
        val (rx, tx) = readTunnelTrafficBytes()

        if (lastTrafficSampleElapsed > 0L && rx >= 0L && tx >= 0L) {
            val seconds = max(0.05, (now - lastTrafficSampleElapsed) / 1000.0)
            val down = max(0L, rx - lastRx)
            val up = max(0L, tx - lastTx)
            sessionDownloadBytes += down
            sessionUploadBytes += up
            val rawDown = down / seconds
            val rawUp = up / seconds

            // EWMA + short zero-hold: packet traffic is bursty, so a 250–400 ms
            // sample can legitimately contain zero bytes. Showing literal zero
            // between bursts made the UI flash 0 → value → 0 every second.
            val alpha = 0.38
            if (rawDown > 0.0) {
                smoothedDownloadBps = if (smoothedDownloadBps <= 0.0) rawDown else smoothedDownloadBps * (1.0 - alpha) + rawDown * alpha
                lastNonZeroDownloadElapsed = now
            } else if (now - lastNonZeroDownloadElapsed > 2_200L) {
                smoothedDownloadBps *= 0.72
                if (smoothedDownloadBps < 24.0) smoothedDownloadBps = 0.0
            }
            if (rawUp > 0.0) {
                smoothedUploadBps = if (smoothedUploadBps <= 0.0) rawUp else smoothedUploadBps * (1.0 - alpha) + rawUp * alpha
                lastNonZeroUploadElapsed = now
            } else if (now - lastNonZeroUploadElapsed > 2_200L) {
                smoothedUploadBps *= 0.72
                if (smoothedUploadBps < 24.0) smoothedUploadBps = 0.0
            }
            downloadSpeed.text = "${formatBytes(smoothedDownloadBps.toLong())}/s"
            uploadSpeed.text = "${formatBytes(smoothedUploadBps.toLong())}/s"

            if (
                connectionVerified &&
                attemptedGuid.isNotBlank() &&
                smoothedDownloadBps >= 64.0 * 1024.0 &&
                now - lastThroughputLearningAt >= 10_000L
            ) {
                lastThroughputLearningAt = now
                BlueVpnRouteIntelligence.recordThroughput(
                    this,
                    attemptedGuid,
                    smoothedDownloadBps.toLong(),
                )
            }
        }

        lastRx = rx
        lastTx = tx
        lastTrafficAt = System.currentTimeMillis()
        lastTrafficSampleElapsed = now

        // Background live reporting owns periodic tunnel verification.
        // Do not duplicate heartbeats and real-ping tests from the Activity;
        // the old overlap created several network probes per minute.
    }

    private fun resetTrafficBaseline() {
        val (rx, tx) = readTunnelTrafficBytes()
        lastRx = rx
        lastTx = tx
        lastTrafficAt = System.currentTimeMillis()
        lastTrafficSampleElapsed = SystemClock.elapsedRealtime()
        smoothedDownloadBps = 0.0
        smoothedUploadBps = 0.0
        lastThroughputLearningAt = 0L
        lastNonZeroDownloadElapsed = lastTrafficSampleElapsed
        lastNonZeroUploadElapsed = lastTrafficSampleElapsed
        sessionDownloadBytes = 0L
        sessionUploadBytes = 0L
    }

    private fun refreshSubscriptionInfo(force: Boolean) {
        val entitlement = BlueVpnEntitlement.resolveUi(this)
        val managed = BlueVpnAccountManager.snapshot(this)
        applyEntitlementPresentation(entitlement)
        when (entitlement.tier) {
            BlueVpnPlanTier.PREMIUM -> {
                remainingVolume.text = if (managed.dataLimitBytes <= 0L) {
                    "نامحدود"
                } else {
                    formatBytes((managed.dataLimitBytes - managed.usedTrafficBytes).coerceAtLeast(0L))
                }
                remainingTime.text = when {
                    managed.remainingSeconds != null -> formatCanonicalRemainingTime(
                        managed.remainingSeconds,
                        managed.remainingSecondsSavedElapsed,
                    )
                    managed.expire.isNullOrBlank() -> "نامحدود"
                    else -> formatAccountRemainingTime(managed.expire)
                }
            }
            BlueVpnPlanTier.FREE -> {
                remainingVolume.text = "رایگان"
                remainingTime.text = "${entitlement.sessionMinutes} دقیقه در هر اتصال"
            }
            BlueVpnPlanTier.UNAVAILABLE -> {
                remainingVolume.text = "بدون دسترسی"
                remainingTime.text = "نیاز به فعال‌سازی"
            }
        }
        // No persistence here: account transitions already invalidate the legacy
        // subscription-info cache in BlueVpnAccountManager.applyAccount/logout.
        // Clearing SharedPreferences on every UI render added avoidable disk work.
    }

    private fun formatCanonicalRemainingTime(
        serverRemainingSeconds: Long,
        savedElapsed: Long,
    ): String {
        val elapsedSeconds = if (savedElapsed > 0L) {
            ((SystemClock.elapsedRealtime() - savedElapsed).coerceAtLeast(0L) / 1_000L)
        } else 0L
        val remaining = (serverRemainingSeconds - elapsedSeconds).coerceAtLeast(0L)
        if (remaining <= 0L) return "پایان یافته"
        val days = ceil(remaining / 86_400.0).toLong()
        return "$days روز"
    }

    private fun formatAccountRemainingTime(
        rawExpire: String,
    ): String {
        val normalized = rawExpire.replace(
            Regex("\\.(\\d{3})\\d+([+-]\\d{2}:\\d{2}|Z)$"),
            ".$1$2"
        )

        val patterns = listOf(
            "yyyy-MM-dd'T'HH:mm:ss.SSSXXX",
            "yyyy-MM-dd'T'HH:mm:ssXXX",
            "yyyy-MM-dd'T'HH:mm:ss.SSS'Z'",
            "yyyy-MM-dd'T'HH:mm:ss'Z'",
        )

        var expireMillis: Long? = null
        for (pattern in patterns) {
            val parsed = runCatching {
                SimpleDateFormat(
                    pattern,
                    Locale.US
                ).apply {
                    timeZone = TimeZone.getTimeZone("UTC")
                    isLenient = false
                }.parse(normalized)?.time
            }.getOrNull()

            if (parsed != null) {
                expireMillis = parsed
                break
            }
        }

        val value = expireMillis
            ?: return rawExpire.substringBefore("T")

        val remainingMillis =
            value - System.currentTimeMillis()

        if (remainingMillis <= 0L) return "پایان یافته"

        val days = ceil(
            remainingMillis / 86_400_000.0
        ).toLong()

        return "$days روز"
    }

    private fun cleanRemainingTime(value: String): String =
        value
            .replace("زمان باقی‌مانده:", "")
            .trim()
            .ifBlank { "نامشخص" }

    private fun fetchSubscriptionUserInfo(url: String): Pair<String, String>? =
        runCatching {
            val connection = URL(url).openConnection() as HttpURLConnection
            connection.instanceFollowRedirects = true
            connection.connectTimeout = 10_000
            connection.readTimeout = 10_000
            connection.requestMethod = "GET"
            connection.setRequestProperty("Accept", "*/*")
            connection.setRequestProperty("Range", "bytes=0-0")
            connection.setRequestProperty(
                "User-Agent",
                "BlueVPN/${BuildConfig.VERSION_NAME}"
            )
            connection.responseCode

            val header = connection.getHeaderField("subscription-userinfo")
                ?: connection.headerFields.entries.firstOrNull {
                    it.key?.equals(
                        "subscription-userinfo",
                        ignoreCase = true
                    ) == true
                }?.value?.firstOrNull()

            connection.disconnect()
            parseSubscriptionUserInfo(header)
        }.getOrNull()

    private fun parseSubscriptionUserInfo(
        header: String?
    ): Pair<String, String>? {
        if (header.isNullOrBlank()) return null

        val values = mutableMapOf<String, Long>()
        header.split(";").forEach { part ->
            val pieces = part.trim().split("=", limit = 2)
            if (pieces.size == 2) {
                values[pieces[0].trim().lowercase(Locale.ROOT)] =
                    pieces[1].trim().toLongOrNull() ?: 0L
            }
        }

        val used = (values["upload"] ?: 0L) +
            (values["download"] ?: 0L)
        val total = values["total"] ?: 0L
        val expire = values["expire"] ?: 0L

        val volume = if (total <= 0L) {
            "نامحدود"
        } else {
            formatBytes(max(0L, total - used))
        }

        val time = if (expire <= 0L) {
            "نامحدود"
        } else {
            val remainMillis =
                expire * 1000L - System.currentTimeMillis()
            val days = max(
                0L,
                ceil(remainMillis / 86_400_000.0).toLong()
            )
            "$days روز"
        }

        return volume to time
    }

    private fun formatDuration(seconds: Long): String {
        val hours = seconds / 3600L
        val minutes = (seconds % 3600L) / 60L
        val secs = seconds % 60L

        return String.format(
            Locale.ROOT,
            "%02d:%02d:%02d",
            hours,
            minutes,
            secs
        )
    }

    private fun formatBytes(bytes: Long): String {
        if (bytes < 1024L) return "$bytes B"

        val units = arrayOf("KB", "MB", "GB", "TB")
        var value = bytes.toDouble()
        var unit = -1

        while (value >= 1024.0 && unit < units.lastIndex) {
            value /= 1024.0
            unit++
        }

        return String.format(
            Locale.ROOT,
            "%.1f %s",
            value,
            units[unit]
        )
    }

    private fun startLiveLocationSwitch(
        selectedTitle: String,
    ) {
        liveLocationSwitch = true
        switchTargetTitle = selectedTitle
        handoverState.beginSwitch()

        handler.removeCallbacks(requestPing)
        handler.removeCallbacks(attemptTimeout)

        statusText.text = "در حال تغییر لوکیشن"
        statusCaption.text =
            "تغییر مکان اتصال در پس‌زمینه آغاز شد"
        statusDot.backgroundTintList =
            ColorStateList.valueOf(Color.parseColor("#FFB44A"))
        updateConnectLabel("در حال جابه‌جایی")
        connectButton.isEnabled = false
        applyOrbVisual(OrbVisualState.CONNECTING)

        // beginSmartConnection creates a new candidate queue for the newly
        // selected location. startCurrentCandidate safely stops the old core
        // and starts the new route, while keeping the VPN permission.
        beginSmartConnection()
    }

    private fun syncManagedAccount(force: Boolean) {
        if (!BlueVpnAccountManager.hasSession(this)) return
        if (!force && (
                failoverActive || userDisconnecting ||
                    mainViewModel.isRunning.value == true
            )
        ) return
        if (accountSyncInProgress) {
            accountSyncForcePending = accountSyncForcePending || force
            return
        }

        val before = BlueVpnAccountManager.snapshot(this)
        accountSyncInProgress = true
        lifecycleScope.launch(Dispatchers.IO) {
            val result = BlueVpnAccountManager.sync(
                this@BlueVpnHomeActivity,
                force
            )
            withContext(Dispatchers.Main) {
                accountSyncInProgress = false

                result.onSuccess { after ->
                    val entitlementChanged =
                        before.subscriptionActive != after.subscriptionActive ||
                            before.subscriptionUrl != after.subscriptionUrl ||
                            before.poolIdentity != after.poolIdentity
                    val accountMetaChanged =
                        before.status != after.status || before.expire != after.expire
                    if (entitlementChanged) {
                        BlueVpnLocationUtil.invalidateCache()
                        mainViewModel.reloadServerList()
                        warmCandidatesThenRefresh(force = true)
                    }
                    requestDashboardRefresh(force = entitlementChanged)
                    refreshSubscriptionInfo(force = entitlementChanged || accountMetaChanged)
                }.onFailure {
                    refreshSubscriptionInfo(force = true)

                    if (!BlueVpnAccountManager.hasSession(
                            this@BlueVpnHomeActivity
                        )
                    ) {
                        openAccount()
                    }
                }

                if (accountSyncForcePending &&
                    BlueVpnAccountManager.hasSession(this@BlueVpnHomeActivity)) {
                    accountSyncForcePending = false
                    handler.post {
                        if (!isFinishing && !isDestroyed) {
                            syncManagedAccount(force = true)
                        }
                    }
                }
            }
        }
    }

    private fun acquireNavigationLock(account: Boolean = false): Boolean {
        if (isFinishing || isDestroyed) return false
        if (navigationLocked) return false
        navigationLocked = true
        accountLaunchInProgress = account
        handler.removeCallbacks(navigationUnlock)
        handler.postDelayed(navigationUnlock, 520L)
        return true
    }

    private fun openAccount() {
        if (!acquireNavigationLock(account = true)) return
        val intent = Intent(this, BlueVpnSubscriptionsActivity::class.java).apply {
            addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP)
        }
        val launched = BlueVpnUiGuard.run(this, "open-account") {
            accountLauncher.launch(intent)
            overridePendingTransition(0, 0)
        }
        if (!launched) navigationUnlock.run()
    }

    private fun openSettings() {
        if (!acquireNavigationLock()) return
        val intent = Intent(this, BlueVpnSettingsActivity::class.java).apply {
            addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP)
        }
        val launched = BlueVpnUiGuard.start(this, intent, intervalMs = 220L)
        if (launched) {
            overridePendingTransition(0, 0)
        } else {
            navigationUnlock.run()
        }
    }

    private fun openServers() {
        if (!acquireNavigationLock()) return
        serversOpenedWhileActive =
            mainViewModel.isRunning.value == true || connectionVerified || failoverActive
        val intent = Intent(this, BlueVpnServersActivity::class.java).apply {
            addFlags(Intent.FLAG_ACTIVITY_REORDER_TO_FRONT or Intent.FLAG_ACTIVITY_SINGLE_TOP)
        }
        val launched = BlueVpnUiGuard.run(this, "open-servers") {
            selectLocationLauncher.launch(intent)
            overridePendingTransition(0, 0)
        }
        if (!launched) {
            navigationUnlock.run()
            serversOpenedWhileActive = false
        }
    }
}
