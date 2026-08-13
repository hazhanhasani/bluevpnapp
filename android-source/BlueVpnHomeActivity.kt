package com.v2ray.ang.ui

import android.animation.ValueAnimator
import android.app.Dialog
import android.content.ComponentCallbacks2
import android.content.Context
import android.content.Intent
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
import androidx.lifecycle.lifecycleScope
import com.google.android.material.button.MaterialButton
import com.google.android.material.card.MaterialCardView
import com.v2ray.ang.AppConfig
import com.v2ray.ang.BuildConfig
import com.v2ray.ang.R
import com.v2ray.ang.bluevpn.BlueVpnAccountManager
import com.v2ray.ang.bluevpn.BlueVpnAdsCarouselView
import com.v2ray.ang.bluevpn.BlueVpnAi
import com.v2ray.ang.bluevpn.BlueVpnDynamicBackgroundView
import com.v2ray.ang.bluevpn.BlueVpnPalette
import com.v2ray.ang.bluevpn.BlueVpnPerformance
import com.v2ray.ang.bluevpn.BlueVpnTheme
import com.v2ray.ang.bluevpn.BlueVpnConnectionMode
import com.v2ray.ang.bluevpn.BlueVpnExperience
import com.v2ray.ang.bluevpn.BlueVpnLocationUtil
import com.v2ray.ang.bluevpn.BlueVpnUpdateManager
import com.v2ray.ang.bluevpn.BlueVpnUiGuard
import com.v2ray.ang.bluevpn.BlueVpnPreferences
import com.v2ray.ang.bluevpn.BlueVpnRouteIntelligence
import com.v2ray.ang.bluevpn.BlueVpnRuntimeGate
import com.v2ray.ang.bluevpn.BlueVpnEngineManager
import com.v2ray.ang.bluevpn.BlueVpnEntitlement
import com.v2ray.ang.bluevpn.BlueVpnPlanTier
import com.v2ray.ang.bluevpn.BlueVpnSelectionMode
import com.v2ray.ang.bluevpn.BlueVpnSmartSelector
import com.v2ray.ang.bluevpn.BlueVpnTapsellManager
import com.v2ray.ang.handler.MmkvManager
import com.v2ray.ang.handler.SettingsManager
import com.v2ray.ang.handler.SubscriptionUpdater
import com.v2ray.ang.viewmodel.MainViewModel
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
    private lateinit var subscriptionSummary: TextView
    private lateinit var pingValue: TextView
    private lateinit var durationValue: TextView
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
    private var lastAiHeartbeatAt = 0L
    private var sessionDownloadBytes = 0L
    private var sessionUploadBytes = 0L

    private var failoverActive = false
    private var failoverQueue: List<String> = emptyList()
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
    private var lastVerifiedLatency = 0L
    private var serversOpenedWhileActive = false
    private var liveLocationSwitch = false
    private var switchTargetTitle = ""
    private var accountSyncInProgress = false
    private var accountSyncForcePending = false
    private var lastForegroundAccountSyncAt = 0L
    private var userDisconnecting = false
    private var navigationLocked = false
    private var lastHistoryGuid = ""
    private var aiHealthCheckAt = 0L
    private var aiConsecutiveFailures = 0
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
    private var entitlementReconcileInProgress = false
    private var retryConnectionAfterEntitlementReconcile = false
    private var startupPipelineStarted = false
    private var dashboardRefreshPosted = false
    private var dashboardForcePending = false
    private var lastGuestPreparationAt = 0L
    private var startupWarmupPosted = false
    private var candidateLoadInProgress = false
    private var pendingConnectionRequest = false
    private var runtimeGateRetryScheduled = false
    private var runtimeGateWaitStartedAt = 0L
    private var userInteractedAt = 0L

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

    private val coreStopTimeout = object : Runnable {
        override fun run() {
            if (!failoverActive || userDisconnecting || !waitingForCoreStop) return

            if (coreStopRetryCount < 1) {
                coreStopRetryCount += 1
                BlueVpnEngineManager.stop(this@BlueVpnHomeActivity)
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
                BlueVpnEngineManager.stop(
                    this@BlueVpnHomeActivity
                )
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
            monitorBlueAiHealth()
            handler.postDelayed(
                this,
                BlueVpnPerformance.statsIntervalMs(this@BlueVpnHomeActivity),
            )
        }
    }

    private val freeSessionTicker = object : Runnable {
        override fun run() {
            if (BlueVpnAccountManager.enforceFreeSession(this@BlueVpnHomeActivity)) {
                connectionVerified = false
                cancelFailover()
                renderConnectionState(false)
                statusText.text = "زمان اتصال رایگان پایان یافت"
                statusCaption.text = "برای اتصال یک‌ساعته بعدی دوباره دکمه اتصال را بزنید"
                Toast.makeText(
                    this@BlueVpnHomeActivity,
                    "اتصال رایگان پس از یک ساعت قطع شد",
                    Toast.LENGTH_LONG,
                ).show()
            }
            updateFreeTimerBadge()
            handler.postDelayed(this, 1_000L)
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
            if (
                BlueVpnAccountManager.hasSession(this) &&
                !startupOptimizationShown
            ) {
                startStartupOptimization()
            } else if (BlueVpnAccountManager.hasSession(this)) {
                // A normal Back from Account is routine/cache-first. RESULT_OK is
                // reserved for an auth/account mutation that can justify an
                // authoritative refresh; payment activation already syncs in the
                // account Activity itself.
                syncManagedAccount(force = result.resultCode == RESULT_OK)
            } else {
                // Logout returns directly to guest/free mode. Clear any stale
                // Premium-connected presentation before free sources refresh.
                cancelFailover()
                connectionVerified = false
                BlueVpnPreferences.clearConnected(this)
                renderConnectionState(false)
                prepareGuestFreeAccess(force = false)
                requestDashboardRefresh(force = true)
                refreshSubscriptionInfo(force = false)
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
        private var animator: ValueAnimator? = null

        fun setAccent(color: Int) {
            accent = color
            invalidate()
        }

        fun start() {
            if (lowEnd || animator?.isRunning == true) {
                invalidate()
                return
            }
            animator = ValueAnimator.ofFloat(0f, 360f).apply {
                duration = 2_400L
                repeatCount = ValueAnimator.INFINITE
                interpolator = LinearInterpolator()
                addUpdateListener {
                    phase = it.animatedValue as Float
                    invalidate()
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
            val radius = side * 0.39f

            fill.color = Color.argb(22, Color.red(accent), Color.green(accent), Color.blue(accent))
            canvas.drawCircle(cx, cy, radius * 1.22f, fill)

            stroke.color = Color.argb(205, Color.red(accent), Color.green(accent), Color.blue(accent))
            stroke.strokeWidth = side * 0.015f
            canvas.drawCircle(cx, cy, radius, stroke)
            canvas.drawOval(cx - radius * 0.46f, cy - radius, cx + radius * 0.46f, cy + radius, stroke)
            canvas.drawOval(cx - radius, cy - radius * 0.42f, cx + radius, cy + radius * 0.42f, stroke)

            stroke.color = Color.argb(120, 255, 255, 255)
            stroke.strokeWidth = side * 0.008f
            canvas.drawArc(
                cx - radius * 1.08f, cy - radius * 1.08f,
                cx + radius * 1.08f, cy + radius * 1.08f,
                phase, 82f, false, stroke,
            )
            val angle = Math.toRadians(phase.toDouble())
            val dotX = cx + kotlin.math.cos(angle).toFloat() * radius * 1.08f
            val dotY = cy + kotlin.math.sin(angle).toFloat() * radius * 1.08f
            fill.color = Color.WHITE
            canvas.drawCircle(dotX, dotY, side * 0.022f, fill)

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
        // Compact campaign banner: directly below the connection control and
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
        content.addView(
            createServerCard(),
            LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                dpHome(88),
            ),
        )
        content.addView(
            createAiCard(),
            LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                dpHome(58),
            ).apply { topMargin = dpHome(8) },
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
        freeTimerBadge = uiText(
            "رایگان 60:00",
            9.5f,
            palette.textPrimary,
            bold = true,
            gravity = Gravity.CENTER,
        ).apply {
            visibility = View.GONE
            maxLines = 1
            background = roundedGradient(
                intArrayOf(palette.surfaceStrong, palette.surface),
                15,
                palette.accent,
            )
            setPadding(dpHome(8), 0, dpHome(8), 0)
        }
        row.addView(
            freeTimerBadge,
            LinearLayout.LayoutParams(dpHome(86), dpHome(38)).apply {
                marginStart = dpHome(6)
            },
        )
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
            "در حال انتخاب سریع بهترین مسیر",
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
        ).apply { id = R.id.bluevpn_status_text }
        stage.addView(
            statusText,
            FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                dpHome(34),
                Gravity.TOP or Gravity.CENTER_HORIZONTAL,
            ).apply { topMargin = dpHome(20) },
        )

        statusCaption = uiText(
            "بهترین مسیر به‌صورت خودکار انتخاب می‌شود",
            10.5f,
            palette.textMuted,
            gravity = Gravity.CENTER,
        ).apply {
            id = R.id.bluevpn_status_caption
            maxLines = 1
        }
        stage.addView(
            statusCaption,
            FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                dpHome(30),
                Gravity.TOP or Gravity.CENTER_HORIZONTAL,
            ).apply {
                topMargin = dpHome(56)
                marginStart = dpHome(18)
                marginEnd = dpHome(18)
            },
        )

        orbHaloOuter = View(this).apply {
            background = radialHaloDrawable(palette.accent, if (palette.dark) 34 else 20)
            alpha = if (palette.dark) 0.30f else 0.20f
        }
        stage.addView(
            orbHaloOuter,
            FrameLayout.LayoutParams(dpHome(330), dpHome(170), Gravity.CENTER).apply {
                topMargin = dpHome(16)
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
            FrameLayout.LayoutParams(dpHome(292), dpHome(130), Gravity.CENTER).apply {
                topMargin = dpHome(16)
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
            FrameLayout.LayoutParams(dpHome(286), dpHome(104), Gravity.CENTER).apply {
                topMargin = dpHome(16)
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

    private fun createAiCard(): View {
        val card = glassCard(18, Color.parseColor("#292B34"), Color.argb(220, 18, 18, 24)).apply {
            id = R.id.bluevpn_ai_card
            isClickable = true
            isFocusable = true
        }
        aiSummaryValue = uiText(
            "پایش هوشمند در پس‌زمینه فعال است",
            10f,
            Color.parseColor("#8C8F9A"),
            gravity = Gravity.CENTER,
        ).apply {
            id = R.id.bluevpn_ai_summary
            maxLines = 2
            setPadding(dpHome(12), dpHome(6), dpHome(12), dpHome(6))
        }
        card.addView(aiSummaryValue)
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
        serverMeta = uiText("بهترین سرور در لحظه", 10f, palette.textMuted).apply {
            id = R.id.bluevpn_server_meta
            maxLines = 1
            ellipsize = android.text.TextUtils.TruncateAt.END
        }
        details.addView(serverName)
        details.addView(serverMeta)
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
        gamingModeButton = modeButton("مسیر دوم", R.id.bluevpn_mode_gaming)
        streamingModeButton = modeButton("مسیر سوم", R.id.bluevpn_mode_streaming)
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
        pingValue = hiddenText(R.id.bluevpn_ping_value)
        durationValue = hiddenText(R.id.bluevpn_duration_value)
        downloadSpeed = hiddenText(R.id.bluevpn_download_speed)
        uploadSpeed = hiddenText(R.id.bluevpn_upload_speed)
        balancedModeButton = MaterialButton(this).apply { id = R.id.bluevpn_mode_balanced }
        gamingModeButton = MaterialButton(this).apply { id = R.id.bluevpn_mode_gaming }
        streamingModeButton = MaterialButton(this).apply { id = R.id.bluevpn_mode_streaming }

        listOf(
            locationValue,
            modeValue,
            activeRoutesValue,
            historyValue,
            pingValue,
            durationValue,
            downloadSpeed,
            uploadSpeed,
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

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        BlueVpnTapsellManager.warmUp(this)
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
        if (BlueVpnUiGuard.consumeRecoveryNotice(this)) {
            Toast.makeText(
                this,
                "برنامه پس از خطای قبلی در حالت سبک اجرا شد",
                Toast.LENGTH_LONG,
            ).show()
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
        BlueVpnUiGuard.bind(findViewById<View>(R.id.bluevpn_ai_card), intervalMs = 900L) {
            runSmartSelection()
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
            val runningGuid = mainViewModel.runningServerGuid.value.orEmpty().trim()
            if (active) {
                BlueVpnRuntimeGate.markConnectionActive(this)
            }

            if (userDisconnecting) {
                if (active) {
                    BlueVpnEngineManager.stop(this)
                } else {
                    userDisconnecting = false
                    disconnectRetry.reset()
                    handler.removeCallbacks(
                        disconnectRetry
                    )
                    BlueVpnRuntimeGate.endConnection(this)
                    BlueVpnEngineManager.markIdle()
                    renderConnectionState(false)
                    reconcileDeferredEntitlementIfIdle()
                }
                return@observe
            }

            when {
                active && failoverActive && waitingForCoreStop -> {
                    // We are still observing the old CoreVpnService. Never verify
                    // the next GUID against traffic from the previous route.
                    updateConnectLabel("لغو اتصال")
                    connectButton.isEnabled = true
                    statusText.text = "در حال تغییر مسیر"
                    statusCaption.text = "در انتظار توقف کامل اتصال قبلی"
                }

                !active && failoverActive && waitingForCoreStop -> {
                    BlueVpnEngineManager.markSwitching()
                    // v2rayNG confirmed that its service/core is fully stopped.
                    // Only now select/start the next exact GUID. This replaces the
                    // old fixed 90 ms restart race.
                    waitingForCoreStop = false
                    coreStopRetryCount = 0
                    handler.removeCallbacks(coreStopTimeout)
                    val guid = attemptedGuid
                    if (guid.isNotBlank()) {
                        handler.post { startExactCandidateCore(guid) }
                    }
                }

                active && failoverActive && runningGuid == attemptedGuid -> {
                    // The service reported RUNNING for the exact candidate.  MMKV's
                    // selected GUID is not trusted here because subscription import
                    // can rewrite it; service-process identity is authoritative.
                    handler.removeCallbacks(attemptTimeout)
                    renderVerifyingState()
                    scheduleConnectionVerification()
                }

                active && failoverActive -> {
                    // A stale RUNNING broadcast/core from the previous candidate
                    // must never be accepted as success for the new GUID.
                    waitingForCoreStop = true
                    coreStopRetryCount = 0
                    BlueVpnEngineManager.markSwitching()
                    statusText.text = "در حال تغییر مسیر"
                    statusCaption.text = "در انتظار آزادشدن هسته قبلی"
                    BlueVpnEngineManager.stop(this)
                    handler.removeCallbacks(coreStopTimeout)
                    handler.postDelayed(coreStopTimeout, 3_000L)
                }

                active && isThemeConnectionGraceActive() -> {
                    // Theme changes recreate only the screen. Keep the core
                    // untouched and restore the verified UI immediately.
                    if (connectionVerified || BlueVpnPreferences.connectedAt(this) > 0L) {
                        connectionVerified = true
                        renderConnectionState(true)
                    } else {
                        renderVerifyingState()
                        verifyExistingRunningSession(preserveServiceOnFailure = true)
                    }
                }

                active && connectionVerified -> {
                    renderConnectionState(true)
                }

                active -> {
                    verifyExistingRunningSession()
                }

                !failoverActive -> {
                    BlueVpnEngineManager.markIdle()
                    connectionVerified = false
                    existingSessionCheckInProgress = false
                    BlueVpnPreferences.clearConnected(this)
                    BlueVpnRuntimeGate.endConnection(this)
                    renderConnectionState(false)
                    reconcileDeferredEntitlementIfIdle()
                }
            }
        }

        mainViewModel.coreStartError.observe(this) { rawError ->
            val error = rawError.orEmpty().trim()
            if (
                error.isBlank() ||
                !failoverActive ||
                userDisconnecting ||
                waitingForCoreStop
            ) {
                return@observe
            }
            // A daemon START_FAILURE is authoritative. Do not burn the whole
            // per-candidate timeout waiting for a core/TUN setup that already
            // failed; quarantine this route and move immediately.
            mainViewModel.coreStartError.value = null
            handler.post {
                if (failoverActive && !userDisconnecting && !waitingForCoreStop) {
                    failCurrentAndTryNext(error)
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
                if (
                    failoverActive &&
                    !waitingForCoreStop &&
                    isExactAttemptRunning()
                ) {
                    // v2rayNG's own coreController.measureDelay() is an end-to-end
                    // proof through the running Xray core. Accept it as a valid
                    // compatibility proof instead of requiring BlueVPN-only URLs.
                    completeFailover(upstreamDelay)
                } else if (!failoverActive && !connectionVerified) {
                    existingSessionRetryCount = 0
                    connectionVerified = true
                    BlueVpnPreferences.markConnected(this, resetTimer = false)
                    BlueVpnAccountManager.startFreeSession(this)
                    renderConnectionState(true)
                    recordCurrentConnection(upstreamDelay)
                }
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
            BlueVpnPerformance.adsDelayMs(this),
        )
    }

    override fun onStop() {
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
        handler.removeCallbacks(delayedAdsStart)
        handler.removeCallbacks(navigationUnlock)
        if (isFinishing && mainViewModel.isRunning.value != true) {
            BlueVpnRuntimeGate.endConnection(this)
        }
        startupDialog?.dismiss()
        startupDialog = null
        setOrbPulseEnabled(false)
        if (::connectingGlobe.isInitialized) connectingGlobe.stop()
        if (::adsCarousel.isInitialized) adsCarousel.release()
        super.onDestroy()
    }

    override fun onResume() {
        super.onResume()
        BlueVpnTheme.applySystemBars(this)
        if (BlueVpnTheme.isDark(this) != themeDarkAtCreate) {
            window.setWindowAnimations(0)
            recreate()
            overridePendingTransition(0, 0)
            return
        }
        navigationLocked = false
        BlueVpnUpdateManager.resumePendingInstall(this)
        applyEntitlementPresentation(BlueVpnEntitlement.resolveUi(this))
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

private fun scheduleStartupPipeline() {
    if (startupPipelineStarted || isFinishing || isDestroyed) return
    startupPipelineStarted = true
    window.decorView.post {
        if (isFinishing || isDestroyed) return@post
        mainViewModel.startListenBroadcast()

        // The core assets are needed only before a connection starts. Let the
        // first frame, taps and navigation finish before touching them.
        lifecycleScope.launch(Dispatchers.IO) {
            // Asset extraction is disk I/O and must never block first paint or taps.
            runCatching { mainViewModel.initAssets(assets) }
        }

        lifecycleScope.launch(Dispatchers.IO) {
            val hadSession = BlueVpnAccountManager.hasSession(this@BlueVpnHomeActivity)
            val hasFreeServer = BlueVpnAccountManager
                .hasInstalledFreeServers(this@BlueVpnHomeActivity)
            val needsGuestBootstrap = !hadSession &&
                (!BlueVpnAccountManager.freeAccessEnabled(this@BlueVpnHomeActivity) || !hasFreeServer)
            val preparedGuest = if (needsGuestBootstrap) {
                BlueVpnAccountManager.prepareFreeAccess(
                    this@BlueVpnHomeActivity,
                    force = false,
                ).getOrDefault(false)
            } else false

            withContext(Dispatchers.Main) {
                if (isFinishing || isDestroyed) return@withContext
                if (preparedGuest) {
                    BlueVpnLocationUtil.invalidateCache()
                    mainViewModel.reloadServerList()
                }
                requestDashboardRefresh(force = true)
                refreshSubscriptionInfo(force = true)
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
        text = "همگام‌سازی  •  ارزیابی شبکه  •  انتخاب مسیر"
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
                "انتخاب سریع‌ترین مسیرهای فعال"
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
                "$activeCount مسیر آماده • $inactiveCount مسیر برای این نوبت کنار گذاشته شد"
            standbyCount > 0 ->
                "$standbyCount مسیر آماده بررسی هنگام اتصال"
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


    private fun monitorBlueAiHealth() {
        if (
            !BlueVpnAi.enabled(this) ||
            mainViewModel.isRunning.value != true ||
            !connectionVerified ||
            failoverActive ||
            healthProbeInProgress ||
            userDisconnecting
        ) return

        val now = SystemClock.elapsedRealtime()
        if (now - aiHealthCheckAt < 180_000L) return
        aiHealthCheckAt = now

        lifecycleScope.launch(Dispatchers.IO) {
            val latency = (
                BlueVpnAi.recentTunnelVerification(
                    this@BlueVpnHomeActivity,
                    maxAgeMs = 125_000L,
                ) ?: BlueVpnAi.verifyTunnel(this@BlueVpnHomeActivity)
            )?.latencyMs
            withContext(Dispatchers.Main) {
                if (latency != null) {
                    aiConsecutiveFailures = 0
                    lastVerifiedLatency = latency
                    aiSummaryValue.text =
                        "BlueAI • مسیر سالم • ${latency} ms"
                } else {
                    aiConsecutiveFailures += 1
                    aiSummaryValue.text =
                        "BlueAI • افت کیفیت ${aiConsecutiveFailures}/2"
                    if (aiConsecutiveFailures >= 2) {
                        aiConsecutiveFailures = 0
                        lifecycleScope.launch(Dispatchers.IO) {
                            BlueVpnAi.finishSession(
                                this@BlueVpnHomeActivity,
                                "auto_heal_quality_drop",
                                success = false,
                                downloadBytes = sessionDownloadBytes,
                                uploadBytes = sessionUploadBytes,
                            )
                        }
                        statusText.text = "ترمیم هوشمند مسیر"
                        statusCaption.text =
                            "BlueAI افت کیفیت را تشخیص داد؛ مسیر جایگزین انتخاب می‌شود"
                        beginSmartConnection()
                    }
                }
            }
        }
    }

    private fun applyEntitlementPresentation(
        entitlement: com.v2ray.ang.bluevpn.BlueVpnEntitlementSnapshot = BlueVpnEntitlement.resolveUi(this),
    ) {
        if (::subscriptionSummary.isInitialized) {
            subscriptionSummary.text = entitlement.accountLabel
        }
        if (::connectingNotice.isInitialized) {
            connectingNotice.text = entitlement.connectionNotice
        }
        if (::freeTimerBadge.isInitialized && !entitlement.isFree) {
            freeTimerBadge.visibility = View.GONE
        }
    }

    private fun runSmartSelection() {
        if (!BlueVpnAi.enabled(this)) BlueVpnAi.setEnabled(this, true)
        val initialEntitlement = BlueVpnEntitlement.resolveUi(this)
        if (!initialEntitlement.canConnect) {
            Toast.makeText(this, initialEntitlement.connectionNotice, Toast.LENGTH_LONG).show()
            return
        }
        aiSummaryValue.text = "در حال تحلیل سرورهای ${initialEntitlement.poolLabel}…"
        lifecycleScope.launch(Dispatchers.IO) {
            var entitlement = BlueVpnEntitlement.resolve(this@BlueVpnHomeActivity)
            var candidates = BlueVpnLocationUtil.allCandidates(
                this@BlueVpnHomeActivity,
                forceRefresh = false,
            )

            // AI is read-only with respect to subscription ownership. It may
            // rebuild the local candidate cache, but it must never trigger an
            // account/provider sync or a v2rayNG subscription import. Coupling
            // those operations made the AI card slow and could rewrite MMKV while
            // the user only asked for a recommendation. Pool repair belongs to
            // the explicit locations/account refresh path.
            if (candidates.none {
                    BlueVpnEntitlement.candidateAllowed(this@BlueVpnHomeActivity, it)
                }
            ) {
                BlueVpnLocationUtil.invalidateCache()
                entitlement = BlueVpnEntitlement.resolve(this@BlueVpnHomeActivity)
                candidates = BlueVpnLocationUtil.allCandidates(
                    this@BlueVpnHomeActivity,
                    forceRefresh = true,
                )
            }

            // Local scoring is authoritative and must return immediately. Cloud
            // enrichment is refreshed as a sibling background task and may improve
            // the next decision, but a slow WordPress/API response cannot freeze AI.
            val decision = BlueVpnSmartSelector.decide(
                this@BlueVpnHomeActivity,
                candidates,
            )
            lifecycleScope.launch(Dispatchers.IO) {
                BlueVpnAi.refreshRecommendations(
                    this@BlueVpnHomeActivity,
                    force = false,
                )
            }
            val finalIdentity = BlueVpnEntitlement.resolve(
                this@BlueVpnHomeActivity,
            ).identity

            withContext(Dispatchers.Main) {
                if (initialEntitlement.identity != finalIdentity) {
                    aiSummaryValue.text = "پلن تغییر کرد؛ تحلیل جدید را اجرا کنید"
                    Toast.makeText(
                        this@BlueVpnHomeActivity,
                        "وضعیت حساب تازه شد؛ انتخاب هوشمند را دوباره بزنید",
                        Toast.LENGTH_LONG,
                    ).show()
                    return@withContext
                }
                if (decision == null) {
                    aiSummaryValue.text = "سرور مجاز و سالمی در ${entitlement.poolLabel} دریافت نشد"
                    Toast.makeText(
                        this@BlueVpnHomeActivity,
                        "Pool ${entitlement.poolLabel} خالی است؛ همگام‌سازی سرور انجام نشد",
                        Toast.LENGTH_LONG,
                    ).show()
                    return@withContext
                }
                if (!BlueVpnEntitlement.candidateAllowed(
                        this@BlueVpnHomeActivity,
                        decision.candidate,
                    )
                ) {
                    aiSummaryValue.text = "نتیجه منقضی شد؛ تحلیل دوباره لازم است"
                    return@withContext
                }
                BlueVpnPreferences.setAutomaticSelection(this@BlueVpnHomeActivity)
                BlueVpnPreferences.setPreferredLocation(this@BlueVpnHomeActivity, "")
                if (!BlueVpnRuntimeGate.connectionActive(this@BlueVpnHomeActivity)) {
                    MmkvManager.setSelectServer(decision.candidate.guid)
                }
                aiSummaryValue.text = BlueVpnSmartSelector.lastSummary(this@BlueVpnHomeActivity)
                requestDashboardRefresh(force = true)
                AlertDialog.Builder(this@BlueVpnHomeActivity)
                    .setTitle("بهترین سرور انتخاب شد")
                    .setMessage(
                        "${decision.candidate.location.flag} ${decision.candidate.location.title}\n" +
                            "امتیاز: ${decision.score} از ۱۰۰\n" +
                            "اطمینان: ${decision.confidence}٪\n" +
                            decision.reason +
                            "\n\n${decision.evaluated} سرور از ${entitlement.poolLabel} بررسی شد."
                    )
                    .setPositiveButton("اتصال") { _, _ ->
                        if (mainViewModel.isRunning.value != true && !failoverActive) {
                            beginSmartConnection()
                        }
                    }
                    .setNegativeButton("بعداً", null)
                    .show()
            }
        }
    }

    private fun showConnectingOverlay(
        title: String = "در حال اتصال",
        caption: String = "در حال انتخاب سریع بهترین مسیر",
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
        val remaining = BlueVpnAccountManager.freeSessionRemainingMillis(this)
        val show =
            (connectionVerified || mainViewModel.isRunning.value == true || failoverActive) &&
                BlueVpnEntitlement.resolveUi(this).timeLimited &&
                remaining in 1 until Long.MAX_VALUE
        if (!show) {
            freeTimerBadge.visibility = View.GONE
            return
        }
        val totalSeconds = (remaining + 999L) / 1_000L
        val minutes = totalSeconds / 60L
        val seconds = totalSeconds % 60L
        freeTimerBadge.text = String.format(
            Locale.US,
            "رایگان %02d:%02d",
            minutes,
            seconds,
        )
        freeTimerBadge.visibility = View.VISIBLE
    }


    private fun toggleConnection() {
        val now = SystemClock.elapsedRealtime()
        if (now - lastConnectionToggleAt < 700L) return
        lastConnectionToggleAt = now
        if (
            userDisconnecting ||
            mainViewModel.isRunning.value == true ||
            failoverActive ||
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
        userDisconnecting = true
        pendingConnectionRequest = false
        runtimeGateWaitStartedAt = 0L
        cancelFailover()
        handler.removeCallbacks(attemptTimeout)
        handler.removeCallbacks(requestPing)
        handler.removeCallbacks(disconnectRetry)
        disconnectRetry.reset()

        BlueVpnEngineManager.stop(this)
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

    private fun prepareGuestFreeAccess(force: Boolean) {
        if (BlueVpnAccountManager.hasSession(this) || freePreparationInProgress) return
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

        val overlayLocation = when (selectionMode) {
            BlueVpnSelectionMode.AUTO -> if (entitlement.isFree) "انتخاب هوشمند رایگان" else "انتخاب خودکار"
            BlueVpnSelectionMode.MANUAL_LOCATION -> "لوکیشن انتخاب‌شده"
            BlueVpnSelectionMode.MANUAL_SERVER -> "سرور انتخاب‌شده"
        }
        showConnectingOverlay(
            title = "در حال اتصال",
            caption = when (selectionMode) {
                BlueVpnSelectionMode.AUTO -> "در حال آماده‌سازی انتخاب هوشمند"
                BlueVpnSelectionMode.MANUAL_LOCATION -> "در حال آماده‌سازی مسیرهای همان لوکیشن"
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
            pendingConnectionRequest = true
            connectButton.isEnabled = false
            statusText.text = "در حال اعمال پلن جدید"
            statusCaption.text = "Pool اشتراک پس از پایان اتصال قبلی یک‌بار همگام می‌شود"
            showConnectingOverlay(
                title = "در حال آماده‌سازی پلن",
                caption = "جداسازی Free/Premium در حال نهایی‌شدن است",
                location = overlayLocation,
            )
            reconcileDeferredEntitlementIfIdle(retryConnection = true)
            return
        }

        if (!BlueVpnRuntimeGate.beginConnection(this, timeoutMs = 0L)) {
            val now = SystemClock.elapsedRealtime()
            if (runtimeGateWaitStartedAt <= 0L) runtimeGateWaitStartedAt = now
            val waitedMs = now - runtimeGateWaitStartedAt

            // Never leave the UI in an endless CONNECTING state when an upstream
            // subscription import/provider request stalls. Subscription mutation
            // keeps ownership of MMKV until it finishes, but the user gets the UI
            // back after a bounded wait and can retry safely instead of racing it.
            if (waitedMs >= 6_000L) {
                pendingConnectionRequest = false
                runtimeGateRetryScheduled = false
                runtimeGateWaitStartedAt = 0L
                hideConnectingOverlay()
                connectButton.isEnabled = true
                updateConnectLabel("تلاش دوباره")
                statusText.text = "همگام‌سازی طولانی شد"
                statusCaption.text = "اتصال متوقف شد تا Import اشتراک به پایان برسد؛ چند لحظه بعد دوباره تلاش کنید"
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
            startSmartConnectionWithCandidates(listOf(exact), selectionMode)
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

        if (!BlueVpnLocationUtil.hasCandidateCache(this)) {
            if (candidateLoadInProgress) {
                pendingConnectionRequest = true
                return
            }
            candidateLoadInProgress = true
            pendingConnectionRequest = true
            lifecycleScope.launch(Dispatchers.Default) {
                val fast = BlueVpnLocationUtil.fastCandidates(
                    this@BlueVpnHomeActivity,
                    preferredLocation,
                    maxCandidates = 10,
                )
                withContext(Dispatchers.Main) {
                    candidateLoadInProgress = false
                    if (isFinishing || isDestroyed || !pendingConnectionRequest) return@withContext
                    pendingConnectionRequest = false
                    startSmartConnectionWithCandidates(fast, selectionMode)
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
        }.take(18)
        startSmartConnectionWithCandidates(scopedCache, selectionMode)
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
                if (
                    generation != connectionPreparationGeneration ||
                    userDisconnecting ||
                    isFinishing ||
                    isDestroyed
                ) {
                    BlueVpnRuntimeGate.endConnection(this@BlueVpnHomeActivity)
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

    private fun applyPreparedConnectionQueue(
        scoredQueue: List<BlueVpnSmartSelector.ScoredCandidate>,
        selectionMode: BlueVpnSelectionMode,
        isolatedCount: Int,
    ) {
        if (scoredQueue.isEmpty()) {
            connectionEntitlementGuids = emptySet()
            BlueVpnRuntimeGate.endConnection(this)
            hideConnectingOverlay()
            liveLocationSwitch = false
            switchTargetTitle = ""
            failoverActive = false
            connectButton.isEnabled = true
            statusText.text = when (selectionMode) {
                BlueVpnSelectionMode.AUTO -> "سرور قابل اتصال نیست"
                BlueVpnSelectionMode.MANUAL_LOCATION -> "لوکیشن قابل اتصال نیست"
                BlueVpnSelectionMode.MANUAL_SERVER -> "سرور انتخاب‌شده قابل اتصال نیست"
            }
            statusCaption.text = "فقط مسیرهای مجاز پلن فعلی بررسی شدند"
            Toast.makeText(this, "سرور سالم و سازگار پیدا نشد", Toast.LENGTH_SHORT).show()
            return
        }

        failoverQueue = when (selectionMode) {
            BlueVpnSelectionMode.MANUAL_SERVER -> scoredQueue.take(1)
            else -> scoredQueue.take(5)
        }.map { it.candidate.guid }
        val chosen = scoredQueue.first()
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
            BlueVpnSelectionMode.AUTO -> "یافتن مسیر سالم"
            BlueVpnSelectionMode.MANUAL_LOCATION -> "اتصال به لوکیشن انتخاب‌شده"
            BlueVpnSelectionMode.MANUAL_SERVER -> "اتصال به سرور انتخاب‌شده"
        }
        statusCaption.text = if (liveLocationSwitch) {
            "اتصال به ${switchTargetTitle.ifBlank { "لوکیشن جدید" }}"
        } else when (selectionMode) {
            BlueVpnSelectionMode.AUTO -> "مسیر سالم قبلی حفظ می‌شود و فقط در صورت افت کیفیت جابه‌جا می‌شویم"
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
        mainViewModel.isRunning.value == true &&
            attemptedGuid.isNotBlank() &&
            mainViewModel.runningServerGuid.value.orEmpty().trim() == attemptedGuid

    private fun startCurrentCandidate() {
        if (!failoverActive) return

        val guid = failoverQueue.getOrNull(failoverIndex)
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
        handler.removeCallbacks(requestPing)
        handler.removeCallbacks(attemptTimeout)
        handler.removeCallbacks(coreStopTimeout)

        val profile = MmkvManager.decodeServerConfig(guid)
        if (
            profile == null ||
            guid !in connectionEntitlementGuids ||
            !BlueVpnAccountManager.candidateAllowed(
                this,
                guid,
                profile.subscriptionId,
                connectionEntitlementGuids,
            )
        ) {
            BlueVpnPreferences.markSessionInactive(this, guid)
            BlueVpnPreferences.markServerFailure(this, guid)
            failoverIndex += 1
            handler.post { if (failoverActive) startCurrentCandidate() }
            return
        }
        val location = BlueVpnLocationUtil.detect(profile.remarks, profile.server)
        val locationName = location?.let { "${it.flag} ${it.title}" } ?: "انتخاب خودکار"

        showConnectingOverlay(
            title = "در حال اتصال",
            caption = "در حال بررسی و برقراری مسیر امن",
            location = locationName,
        )
        statusText.text = "در حال اتصال"
        statusCaption.visibility = View.VISIBLE
        statusCaption.text =
            "در حال برقراری اتصال امن"
        updateConnectLabel("لغو اتصال")
        connectButton.isEnabled = true

        val candidate = BlueVpnLocationUtil.Candidate(
            guid = guid,
            profile = profile,
            location = location,
            delay = MmkvManager.decodeServerAffiliationInfo(guid)?.testDelayMillis ?: 0L,
        )
        statusCaption.text = "پیش‌تست DNS و دسترسی endpoint"

        // Reject only deterministic malformed profiles here. DNS/TCP reachability is
        // advisory; the real Xray tunnel proof below is authoritative. This runs
        // off the main thread so a slow resolver/socket can never freeze the UI.
        lifecycleScope.launch(Dispatchers.IO) {
            val preflight = BlueVpnLocationUtil.preflightCandidate(
                candidate,
                timeoutMs = 450,
            )
            withContext(Dispatchers.Main) {
                if (
                    !failoverActive ||
                    userDisconnecting ||
                    attemptedGuid != guid ||
                    isFinishing ||
                    isDestroyed
                ) {
                    return@withContext
                }

                if (!preflight.ok) {
                    failCurrentAndTryNext(preflight.reason)
                    return@withContext
                }

                // Keep advisory endpoint observations separate from the final
                // core verdict; only the end-to-end tunnel proof marks success.
                // The route intelligence layer intentionally stores history by
                // semantic endpoint + physical network, not by volatile GUID.

                // A failed raw DNS/TCP preflight is only a hint. v2rayNG/Xray
                // is the source of truth, so continue into a real core start and
                // end-to-end tunnel proof before quarantining this route.
                statusCaption.text = preflight.reason

                if (mainViewModel.isRunning.value == true) {
                    // A fixed restart delay is unsafe because CoreVpnService lives
                    // in another process. Wait for v2rayNG's NOT_RUNNING broadcast
                    // before committing/starting the next GUID.
                    waitingForCoreStop = true
                    coreStopRetryCount = 0
                    BlueVpnEngineManager.markSwitching()
                    statusText.text = "در حال تغییر مسیر"
                    statusCaption.text = "در انتظار توقف کامل اتصال قبلی"
                    BlueVpnEngineManager.stop(this@BlueVpnHomeActivity)
                    handler.removeCallbacks(coreStopTimeout)
                    handler.postDelayed(coreStopTimeout, 6_000L)
                } else {
                    startExactCandidateCore(guid)
                }
            }
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
        if (
            profile == null ||
            !BlueVpnAccountManager.candidateAllowed(this, guid, profile.subscriptionId)
        ) {
            failCurrentAndTryNext("کانفیگ از Pool فعال خارج شده است")
            return
        }

        // BlueVpnEngineManager passes this exact GUID to
        // the official v2rayNG start entry point with an exact GUID. v2rayNG remains the
        // parser/config-builder/VpnService authority for Xray-compatible routes.
        BlueVpnEngineManager.start(this, guid)
        handler.postDelayed({
            if (!isFinishing && !isDestroyed) requestDashboardRefresh()
        }, 60L)
        handler.removeCallbacks(attemptTimeout)
        handler.postDelayed(attemptTimeout, 12_000L)
    }

    private fun scheduleConnectionVerification() {
        if (
            !failoverActive ||
            userDisconnecting ||
            waitingForCoreStop ||
            healthProbeInProgress
        ) return

        BlueVpnEngineManager.markVerifying()
        // Ask the same upstream v2rayNG core for its native delay proof in
        // parallel with BlueVPN's independent HTTP proof. Either proof may
        // succeed first; a single BlueVPN endpoint failure no longer kills Xray.
        mainViewModel.testCurrentServerRealPing()
        handler.postDelayed({
            if (failoverActive && !waitingForCoreStop) {
                verifyTunnelThroughCore(
                    "این مسیر در چند تست واقعی اینترنت پاسخ نداد"
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
        // BlueVPN is a consumer VPN app. Proxy-only mode can start the core
        // without routing Android applications, which looks connected but
        // does not provide Internet to the device.
        MmkvManager.encodeSettings(
            AppConfig.PREF_MODE,
            "VPN"
        )

        // Health checks and the TUN bridge depend on the local Xray inbound.
        MmkvManager.encodeSettings(
            AppConfig.PREF_ENABLE_LOCAL_PROXY,
            true
        )
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
        durationValue.text = "۰۰:۰۰:۰۰"
        downloadSpeed.text = "۰ B/s"
        uploadSpeed.text = "۰ B/s"
    }

    private fun isThemeConnectionGraceActive(): Boolean =
        SystemClock.elapsedRealtime() < themeConnectionGraceUntil

    private fun verifyExistingRunningSession(
        preserveServiceOnFailure: Boolean = false,
    ) {
        if (existingSessionCheckInProgress || connectionVerified) return

        existingSessionCheckInProgress = true
        renderVerifyingState()
        mainViewModel.testCurrentServerRealPing()

        lifecycleScope.launch(Dispatchers.IO) {
            val latency = probeInternetThroughCore()

            withContext(Dispatchers.Main) {
                existingSessionCheckInProgress = false

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
                    BlueVpnPreferences.markConnected(
                        this@BlueVpnHomeActivity,
                        resetTimer = false
                    )
                    BlueVpnRuntimeGate.markConnectionActive(this@BlueVpnHomeActivity)
                    BlueVpnEngineManager.markConnected()
                    BlueVpnAccountManager.startFreeSession(this@BlueVpnHomeActivity)
                    resetTrafficBaseline()
                    renderConnectionState(true)
                    statusCaption.text =
                        "اتصال امن با موفقیت برقرار شد"
                    recordCurrentConnection(latency)
                    refreshVerifiedExitLocation()
                    return@withContext
                }

                // A running v2rayNG CoreVpnService is preserved while verification
                // is inconclusive. BlueVPN must not tear down a working tunnel just
                // because one HTTP endpoint, DNS path or Activity recreation probe
                // failed. The UI stays in VERIFYING until an upstream or independent
                // end-to-end proof succeeds.
                if (
                    (preserveServiceOnFailure || isThemeConnectionGraceActive()) &&
                    BlueVpnPreferences.connectedAt(this@BlueVpnHomeActivity) > 0L
                ) {
                    connectionVerified = true
                    renderConnectionState(true)
                } else {
                    connectionVerified = false
                    renderVerifyingState()
                }
                statusCaption.text =
                    "هسته Xray فعال است؛ تأیید اینترنت در پس‌زمینه ادامه دارد"

                val retryLimit = if (preserveServiceOnFailure || isThemeConnectionGraceActive()) 3 else 2
                if (existingSessionRetryCount < retryLimit) {
                    existingSessionRetryCount += 1
                    handler.postDelayed({
                        if (
                            mainViewModel.isRunning.value == true &&
                            !isFinishing &&
                            !isDestroyed &&
                            !connectionVerified
                        ) {
                            verifyExistingRunningSession(
                                preserveServiceOnFailure = preserveServiceOnFailure,
                            )
                        }
                    }, 1_800L)
                }
            }
        }
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
                    round < 3
                ) {
                    // Do not classify a v2rayNG-compatible config as dead after a
                    // single BlueVPN probe. Reality/WS/gRPC/TLS can need a longer
                    // warm-up on Iranian mobile networks, and public probe URLs can
                    // be filtered independently of the tunnel itself.
                    statusText.text = "در حال تأیید اینترنت"
                    statusCaption.text = "تست واقعی ${round + 1} از ۳"
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
        maxWaitMs: Long = 2_400L,
    ): Boolean {
        val deadline = SystemClock.elapsedRealtime() + maxWaitMs
        do {
            val ready = runCatching {
                Socket().use { socket ->
                    socket.tcpNoDelay = true
                    socket.connect(
                        InetSocketAddress("127.0.0.1", httpPort),
                        140,
                    )
                }
                true
            }.getOrDefault(false)
            if (ready) return true
            if (SystemClock.elapsedRealtime() >= deadline) break
            runCatching { Thread.sleep(90L) }
        } while (!Thread.currentThread().isInterrupted)
        return false
    }

    private suspend fun probeInternetThroughCore(): Long? =
        withContext(Dispatchers.IO) {
            val httpPort = SettingsManager.getHttpPort()

            if (httpPort !in 1..65535) {
                return@withContext null
            }

            // CoreService RUNNING can arrive a little before the local HTTP
            // inbound is actually accepting sockets. Waiting for localhost
            // readiness prevents a perfectly valid v2rayNG profile from being
            // discarded during that startup race.
            if (!waitForLocalProxyReady(httpPort)) {
                return@withContext null
            }

            // Race several independent Internet proofs through Xray and return
            // on first success. Initial TLS/REALITY/WS/gRPC handshakes on mobile
            // networks can legitimately exceed one second, so the verifier has
            // a bounded but realistic window instead of the former 1050 ms.
            val endpoints = buildList {
                add("http://cp.cloudflare.com/generate_204")
                add("http://connectivitycheck.gstatic.com/generate_204")
                add("http://1.1.1.1/cdn-cgi/trace")
                add("${BuildConfig.BLUEVPN_API_BASE_URL.trimEnd('/')}/health")
                if (!BlueVpnPerformance.isLowEnd(this@BlueVpnHomeActivity)) {
                    add("https://check-host.net/cdn-cgi/trace")
                }
            }

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
                        httpPort = httpPort,
                    )
                }
            }

            try {
                val deadline =
                    SystemClock.elapsedRealtime() + 3_600L

                repeat(futures.size) {
                    val remaining = (
                        deadline - SystemClock.elapsedRealtime()
                    ).coerceAtLeast(1L)
                    val completed = completion.poll(
                        remaining,
                        TimeUnit.MILLISECONDS,
                    ) ?: return@withContext null

                    val latency = runCatching {
                        completed.get()
                    }.getOrNull()

                    if (latency != null) {
                        return@withContext latency
                    }
                }

                null
            } finally {
                futures.forEach { it.cancel(true) }
                executor.shutdownNow()
            }
        }

    private fun requestThroughLocalXrayProxy(
        endpoint: String,
        httpPort: Int,
        countryGuid: String = attemptedGuid,
    ): Long? =
        runCatching {
            val proxy = Proxy(
                Proxy.Type.HTTP,
                InetSocketAddress("127.0.0.1", httpPort)
            )
            val connection =
                URL(endpoint).openConnection(proxy) as HttpURLConnection
            val startedAt = SystemClock.elapsedRealtime()

            try {
                connection.instanceFollowRedirects = false
                connection.connectTimeout = 1_800
                connection.readTimeout = 1_800
                connection.requestMethod = "GET"
                connection.useCaches = false
                connection.setRequestProperty("Connection", "close")
                connection.setRequestProperty(
                    "User-Agent",
                    "BlueVPN/${BuildConfig.VERSION_NAME}"
                )

                val code = connection.responseCode
                val body = if (code == 200) {
                    runCatching {
                        connection.inputStream.bufferedReader().use {
                            it.readText().take(4096)
                        }
                    }.getOrDefault("")
                } else {
                    ""
                }
                val valid = when {
                    endpoint.endsWith("/health") ->
                        code == 200 &&
                            body.contains(
                                "bluevpn-platform",
                                ignoreCase = true,
                            )
                    endpoint.contains("generate_204") ->
                        code == 204
                    endpoint.contains("cdn-cgi/trace") ->
                        code == 200 &&
                            body.lineSequence().any {
                                it.startsWith("ip=")
                            }
                    else -> false
                }

                if (valid) {
                    if (endpoint.contains("/cdn-cgi/trace")) {
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

        handler.removeCallbacks(requestPing)
        handler.removeCallbacks(attemptTimeout)
        handler.removeCallbacks(coreStopTimeout)
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
        BlueVpnPreferences.markConnected(this, resetTimer = true)
        BlueVpnRuntimeGate.markConnectionActive(this)
        BlueVpnEngineManager.markConnected()
        BlueVpnAccountManager.startFreeSession(this)

        failoverActive = false
        runtimeGateWaitStartedAt = 0L
        waitingForPingResult = false
        healthProbeInProgress = false
        waitingForCoreStop = false
        coreStopRetryCount = 0
        verificationRound = 0
        connectionVerified = true
        liveLocationSwitch = false
        switchTargetTitle = ""
        connectButton.isEnabled = true
        resetTrafficBaseline()
        requestDashboardRefresh()
        hideConnectingOverlay()
        renderConnectionState(true)
        updateFreeTimerBadge()
        mainViewModel.testCurrentServerRealPing()

        val verifiedDelay = delay ?: lastVerifiedLatency
        recordCurrentConnection(verifiedDelay)
        refreshVerifiedExitLocation()
        statusCaption.text =
            if (completedLiveSwitch) {
                "مکان اتصال با موفقیت تغییر کرد"
            } else {
                "اتصال امن برقرار شد و پایش خودکار فعال است"
            }

        // Interstitial ads are a Free-plan benefit exchange, never a gate for
        // VPN connectivity. Trigger only after a real verified connection;
        // live location switches and Premium sessions do not show an ad.
        if (!completedLiveSwitch && BlueVpnEntitlement.resolveUi(this).isFree) {
            BlueVpnTapsellManager.onVerifiedConnection(
                this,
                BlueVpnPreferences.connectedAt(this),
            )
        }
    }

    private fun refreshVerifiedExitLocation() {
        val port = SettingsManager.getHttpPort()
        val guid = attemptedGuid
        if (port !in 1..65535 || guid.isBlank()) return
        lifecycleScope.launch(Dispatchers.IO) {
            val resolved = requestThroughLocalXrayProxy(
                endpoint = "https://check-host.net/cdn-cgi/trace",
                httpPort = port,
                countryGuid = guid,
            ) ?: requestThroughLocalXrayProxy(
                endpoint = "http://1.1.1.1/cdn-cgi/trace",
                httpPort = port,
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
        handler.removeCallbacks(coreStopTimeout)

        if (failedGuid.isNotBlank()) {
            // Hard quarantine for this connect cycle. The next explicit connect
            // attempt clears only this temporary flag, while failedRecently()
            // keeps a short-lived score penalty so the same route is not picked
            // first again immediately.
            BlueVpnPreferences.markSessionInactive(this, failedGuid)
            BlueVpnPreferences.markServerFailure(this, failedGuid)
            BlueVpnRouteIntelligence.recordFailure(this, failedGuid, reason)
            MmkvManager.encodeServerTestDelayMillis(failedGuid, -1L)
        }

        BlueVpnEngineManager.stop(this)
        failoverIndex += 1
        waitingForPingResult = false
        healthProbeInProgress = false
        waitingForCoreStop = false
        coreStopRetryCount = 0
        verificationRound = 0

        if (failoverIndex >= failoverQueue.size) {
            finishFailoverWithError()
            return
        }

        statusText.text = "تغییر مسیر خودکار"
        statusCaption.text =
            "مسیر بهتر به‌صورت خودکار در حال انتخاب است"
        showConnectingOverlay(
            title = "در حال تغییر مسیر",
            caption = "مسیر قبلی پاسخ نداد؛ بهترین مسیر بعدی بررسی می‌شود",
            location = "انتخاب خودکار",
        )

        handler.postDelayed({
            if (failoverActive) startCurrentCandidate()
        }, 250L)
    }

    private fun finishFailoverWithError(reason: String? = null) {
        hideConnectingOverlay()
        handler.removeCallbacks(requestPing)
        handler.removeCallbacks(attemptTimeout)
        handler.removeCallbacks(coreStopTimeout)
        BlueVpnEngineManager.stop(this)
        BlueVpnRuntimeGate.endConnection(this)

        failoverActive = false
        waitingForPingResult = false
        healthProbeInProgress = false
        waitingForCoreStop = false
        coreStopRetryCount = 0
        verificationRound = 0
        connectionVerified = false
        liveLocationSwitch = false
        switchTargetTitle = ""
        BlueVpnPreferences.clearConnected(this)
        connectButton.isEnabled = true
        updateConnectLabel("تلاش دوباره")
        applyOrbVisual(OrbVisualState.ERROR)
        statusText.text = "لوکیشن در دسترس نیست"
        statusCaption.visibility = View.VISIBLE
        statusCaption.text = reason
            ?: "همه مسیرهای این لوکیشن بررسی شدند؛ بعداً دوباره امتحان کنید"
        statusDot.backgroundTintList =
            ColorStateList.valueOf(Color.parseColor("#FFB44A"))

        Toast.makeText(
            this,
            reason ?: "هیچ‌کدام از مسیرهای این لوکیشن پاسخ ندادند",
            Toast.LENGTH_LONG
        ).show()
    }

    private fun cancelFailover() {
        BlueVpnRuntimeGate.endConnection(this)
        hideConnectingOverlay()
        handler.removeCallbacks(requestPing)
        handler.removeCallbacks(attemptTimeout)
        handler.removeCallbacks(coreStopTimeout)
        failoverActive = false
        pendingConnectionRequest = false
        runtimeGateWaitStartedAt = 0L
        failoverQueue = emptyList()
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
        BlueVpnPreferences.clearConnected(this)
        connectButton.isEnabled = true
    }

    private fun renderConnectionState(connected: Boolean) {
        if (failoverActive) {
            showConnectingOverlay(
                title = "در حال اتصال",
                caption = "بهترین مسیر به‌صورت خودکار در حال بررسی است",
                location = "انتخاب خودکار",
            )
            updateConnectLabel("لغو اتصال")
            connectButton.isEnabled = true
            applyOrbVisual(OrbVisualState.CONNECTING)
            statusText.text = "یافتن مسیر سالم"
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
            statusCaption.text = "بهترین مسیر به‌صورت خودکار انتخاب می‌شود"
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
        val selectedGuid = MmkvManager.getSelectServer()
        val selected = candidates.firstOrNull {
            it.guid == selectedGuid
        }
        val selectedAllowed = BlueVpnAccountManager.selectedServerAllowed(this)
        val profile = selected?.profile ?: selectedGuid
            ?.takeIf { it.isNotBlank() && selectedAllowed }
            ?.let { MmkvManager.decodeServerConfig(it) }
        val automaticSelection =
            BlueVpnPreferences.smartBalance(this)

        if (profile == null || !BlueVpnLocationUtil.isUsable(profile)) {
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
        } else {
            val location = selected?.location
                ?: BlueVpnLocationUtil.detect(profile.remarks, profile.server)
            val delay = selected?.delay ?: 0L
            val routeCount = if (automaticSelection) {
                candidates.size
            } else {
                candidates.count {
                    it.location.key == location.key
                }
            }

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
                        failoverActive ->
                            "در حال انتخاب مسیر بهتر"
                        delay > 0L ->
                            "$routeCount مسیر آماده"
                        else ->
                            "$routeCount سرور آماده"
                    }
                }

            locationValue.text = "${location.flag} ${location.title}"
            pingValue.text = when {
                delay > 0L -> "${delay} ms"
                delay < 0L -> "ناموفق"
                else -> "تست نشده"
            }
        }

        val usableCount = candidates.size
        val locationCount = candidates
            .map { it.location.key }
            .distinct()
            .size
        val subscriptionCount = candidates
            .mapNotNull { it.profile.subscriptionId }
            .distinct()
            .size

        val entitlement = BlueVpnEntitlement.resolveUi(this)
        applyEntitlementPresentation(entitlement)
        subscriptionSummary.text = when (entitlement.tier) {
            BlueVpnPlanTier.PREMIUM ->
                "${entitlement.accountLabel} • $locationCount مکان • $usableCount مسیر"
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
            durationValue.text = "۰۰:۰۰:۰۰"
            downloadSpeed.text = "۰ B/s"
            uploadSpeed.text = "۰ B/s"
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
        durationValue.text = formatDuration(elapsedSeconds)

        val now = System.currentTimeMillis()
        val (rx, tx) = readTunnelTrafficBytes()

        if (lastTrafficAt > 0L && rx >= 0L && tx >= 0L) {
            val seconds = max(0.001, (now - lastTrafficAt) / 1000.0)
            val down = max(0L, rx - lastRx)
            val up = max(0L, tx - lastTx)
            sessionDownloadBytes += down
            sessionUploadBytes += up
            downloadSpeed.text = "${formatBytes((down / seconds).toLong())}/s"
            uploadSpeed.text = "${formatBytes((up / seconds).toLong())}/s"
        }

        lastRx = rx
        lastTx = tx
        lastTrafficAt = now

        // Background live reporting owns periodic tunnel verification.
        // Do not duplicate heartbeats and real-ping tests from the Activity;
        // the old overlap created several network probes per minute.
    }

    private fun resetTrafficBaseline() {
        val (rx, tx) = readTunnelTrafficBytes()
        lastRx = rx
        lastTx = tx
        lastTrafficAt = System.currentTimeMillis()
        sessionDownloadBytes = 0L
        sessionUploadBytes = 0L
        lastAiHeartbeatAt = 0L
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
                remainingTime.text = if (managed.expire.isNullOrBlank()) {
                    "نامحدود"
                } else {
                    formatAccountRemainingTime(managed.expire)
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
        getSharedPreferences("bluevpn_subscription_info", MODE_PRIVATE).edit().clear().apply()
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
