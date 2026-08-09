package com.v2ray.ang.ui

import android.animation.ValueAnimator
import android.app.Dialog
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
import com.v2ray.ang.bluevpn.BlueVpnTheme
import com.v2ray.ang.bluevpn.BlueVpnConnectionMode
import com.v2ray.ang.bluevpn.BlueVpnExperience
import com.v2ray.ang.bluevpn.BlueVpnLocationUtil
import com.v2ray.ang.bluevpn.BlueVpnUpdateManager
import com.v2ray.ang.bluevpn.BlueVpnPreferences
import com.v2ray.ang.core.CoreServiceManager
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
    private var dragStartRawX = 0f
    private var dragStartTranslation = 0f
    private var dragMoved = false

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
    private var failoverIndex = -1
    private var attemptedGuid = ""
    private var waitingForPingResult = false
    private var healthProbeInProgress = false
    private var existingSessionCheckInProgress = false
    private var lastVerifiedLatency = 0L
    private var serversOpenedWhileActive = false
    private var liveLocationSwitch = false
    private var switchTargetTitle = ""
    private var accountSyncInProgress = false
    private var userDisconnecting = false
    private var navigationLocked = false
    private var lastHistoryGuid = ""
    private var aiHealthCheckAt = 0L
    private var aiConsecutiveFailures = 0
    private var lastDashboardRefreshAt = 0L
    private var updateCheckScheduled = false
    private var accountLaunchInProgress = false

    private var startupOptimizationShown = false
    private var startupOptimizationActive = false
    private var startupServerTestStarted = false
    private var startupProgress = 0
    private var startupDialog: Dialog? = null
    private var startupProgressBar: ProgressBar? = null
    private var startupProgressText: TextView? = null
    private var startupStageText: TextView? = null

    private val attemptTimeout = Runnable {
        if (!failoverActive || userDisconnecting) {
            return@Runnable
        }
        failCurrentAndTryNext("این مسیر در زمان مجاز پاسخ نداد")
    }

    private val disconnectRetry = object : Runnable {
        private var attempt = 0

        fun reset() {
            attempt = 0
        }

        override fun run() {
            if (!userDisconnecting) return

            if (mainViewModel.isRunning.value == true) {
                CoreServiceManager.stopVService(
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
                renderConnectionState(false)
            }
        }
    }

    private val requestPing = Runnable {
        if (!failoverActive) return@Runnable
        if (mainViewModel.isRunning.value != true) return@Runnable
        if (MmkvManager.getSelectServer() != attemptedGuid) return@Runnable

        waitingForPingResult = true
        mainViewModel.testCurrentServerRealPing()
    }

    private val statsTicker = object : Runnable {
        override fun run() {
            updateLiveStats()
            monitorBlueAiHealth()
            handler.postDelayed(this, 2_000L)
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

            refreshDashboard()

            if (changed && serversOpenedWhileActive) {
                startLiveLocationSwitch(selectedTitle)
            }

            serversOpenedWhileActive = false
            navigationLocked = false
        }

    private val accountLauncher =
        registerForActivityResult(
            ActivityResultContracts.StartActivityForResult()
        ) {
            navigationLocked = false
            accountLaunchInProgress = false
            if (
                BlueVpnAccountManager.hasSession(this) &&
                !startupOptimizationShown
            ) {
                startStartupOptimization()
            } else if (BlueVpnAccountManager.hasSession(this)) {
                // Returning from the account/payment screen must immediately
                // refresh entitlement state; the old five-minute cache could
                // keep showing «نیاز به تمدید» after a successful activation.
                syncManagedAccount(force = true)
            } else {
                refreshDashboard()
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
                ViewGroup.LayoutParams.WRAP_CONTENT,
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
            createActionRow(),
            LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                dpHome(54),
            ).apply { topMargin = dpHome(10) },
        )
        content.addView(createCompatibilityFields())
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
            LinearLayout.LayoutParams(dpHome(48), dpHome(48)),
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
        row.addView(View(this), LinearLayout.LayoutParams(0, 1, 1f))
        row.addView(
            headerIcon("account", R.id.bluevpn_action_subscription, "حساب و اشتراک"),
            LinearLayout.LayoutParams(dpHome(48), dpHome(48)),
        )
        return row
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
        ).apply { id = R.id.bluevpn_ai_summary }
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
        aiSummaryValue = hiddenText(R.id.bluevpn_ai_summary)
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
            aiSummaryValue,
            pingValue,
            durationValue,
            downloadSpeed,
            uploadSpeed,
            balancedModeButton,
            gamingModeButton,
            streamingModeButton,
            MaterialCardView(this).apply { id = R.id.bluevpn_ai_card },
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
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
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
        if (!enabled) {
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
        palette = BlueVpnTheme.palette(this)
        themeDarkAtCreate = palette.dark
        window.setBackgroundDrawable(ColorDrawable(palette.background))
        BlueVpnTheme.applySystemBars(this)
        setContentView(createScreen())

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
        connectButton.setOnClickListener { toggleConnection() }

        findViewById<View>(
            R.id.bluevpn_server_card
        ).setOnClickListener {
            openServers()
        }
        findViewById<View>(
            R.id.bluevpn_action_servers
        ).setOnClickListener {
            openServers()
        }
        findViewById<View>(
            R.id.bluevpn_action_subscription
        ).setOnClickListener {
            openAccount()
        }
        findViewById<View>(
            R.id.bluevpn_action_settings
        ).setOnClickListener {
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

            if (userDisconnecting) {
                if (active) {
                    CoreServiceManager.stopVService(this)
                } else {
                    userDisconnecting = false
                    disconnectRetry.reset()
                    handler.removeCallbacks(
                        disconnectRetry
                    )
                    renderConnectionState(false)
                }
                return@observe
            }

            when {
                active && failoverActive -> {
                    renderVerifyingState()
                    scheduleConnectionVerification()
                }

                active && connectionVerified -> {
                    renderConnectionState(true)
                }

                active -> {
                    verifyExistingRunningSession()
                }

                !failoverActive -> {
                    connectionVerified = false
                    existingSessionCheckInProgress = false
                    BlueVpnPreferences.clearConnected(this)
                    renderConnectionState(false)
                }
            }
        }

        mainViewModel.updateTestResultAction.observe(this) {
            BlueVpnLocationUtil.invalidateCache()
            refreshDashboard(force = true)

            if (startupOptimizationActive) {
                finishStartupOptimization()
            } else {
                handlePingResult()
            }
        }

        mainViewModel.updateListAction.observe(this) {
            BlueVpnLocationUtil.invalidateCache()
            refreshDashboard(force = true)

            if (
                startupOptimizationActive &&
                !startupServerTestStarted
            ) {
                startStartupServerTest()
            }
        }

        enforceReliableVpnSettings()

        // Let Android draw the home screen before MMKV scans, asset setup,
        // account sync and remote checks begin. This removes the black cold
        // start seen on slower phones.
        window.decorView.post {
            mainViewModel.startListenBroadcast()
            mainViewModel.initAssets(assets)
            mainViewModel.reloadServerList()
            refreshDashboard(force = true)
            refreshSubscriptionInfo(force = false)

            if (!BlueVpnAccountManager.hasSession(this)) {
                openAccount()
            }

            handler.postDelayed({
                if (!isFinishing && !isDestroyed) {
                    showWelcomeIfNeeded()
                }
            }, 350L)

            handler.postDelayed({
                lifecycleScope.launch(Dispatchers.IO) {
                    BlueVpnAi.refreshRecommendations(
                        this@BlueVpnHomeActivity,
                        force = false,
                    )
                    withContext(Dispatchers.Main) {
                        if (::aiSummaryValue.isInitialized) {
                            aiSummaryValue.text = BlueVpnAi.localSummary(
                                this@BlueVpnHomeActivity
                            )
                        }
                        refreshExperienceDashboard()
                    }
                }
            }, 1_200L)
        }
    }

    override fun onStart() {
        super.onStart()
        navigationLocked = false
        handler.removeCallbacks(statsTicker)
        handler.post(statsTicker)
        if (::connectButton.isInitialized) {
            applyOrbVisual(orbVisualState)
        }
        if (::adsCarousel.isInitialized) adsCarousel.start()
    }

    override fun onStop() {
        if (::adsCarousel.isInitialized) adsCarousel.stop()
        handler.removeCallbacks(statsTicker)
        setOrbPulseEnabled(false)
        super.onStop()
    }

    override fun onDestroy() {
        handler.removeCallbacks(attemptTimeout)
        handler.removeCallbacks(requestPing)
        handler.removeCallbacks(startupProgressTicker)
        handler.removeCallbacks(startupOptimizationTimeout)
        handler.removeCallbacks(disconnectRetry)
        startupDialog?.dismiss()
        startupDialog = null
        setOrbPulseEnabled(false)
        if (::adsCarousel.isInitialized) adsCarousel.release()
        super.onDestroy()
    }

    override fun onResume() {
        super.onResume()
        BlueVpnTheme.applySystemBars(this)
        if (BlueVpnTheme.isDark(this) != themeDarkAtCreate) {
            recreate()
            return
        }
        navigationLocked = false
        BlueVpnUpdateManager.resumePendingInstall(this)

        if (!updateCheckScheduled) {
            updateCheckScheduled = true
            handler.postDelayed({
                updateCheckScheduled = false
                if (!isFinishing && !isDestroyed) {
                    BlueVpnUpdateManager.check(this)
                }
            }, 1_800L)
        }

        if (!BlueVpnAccountManager.hasSession(this)) {
            openAccount()
        } else if (!startupOptimizationShown) {
            startStartupOptimization()
        } else if (!BlueVpnAccountManager.snapshot(this).subscriptionActive) {
            // An inactive local badge is never allowed to stay cached. This is
            // especially important immediately after an admin/manual renewal.
            syncManagedAccount(force = true)
        }

        handler.post {
            refreshDashboard()
            refreshSubscriptionInfo(force = false)
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

    val all = cachedCandidates ?: BlueVpnLocationUtil.allCandidates(this)
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
        .allCandidates(this)
        .firstOrNull { it.guid == guid }
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
    refreshExperienceDashboard()
}

private fun showWelcomeIfNeeded() {
    if (!BlueVpnExperience.shouldShowWelcome(this)) {
        return
    }

    AlertDialog.Builder(this)
        .setTitle("به BlueVPN ${BuildConfig.VERSION_NAME} خوش آمدید")
        .setMessage(
            "طراحی جدید، انتخاب خودکار مسیر، اتصال سریع‌تر و پایش هوشمند در پس‌زمینه آماده است."
        )
        .setPositiveButton("شروع") { _, _ ->
            BlueVpnExperience.markWelcomeShown(this)
        }
        .setCancelable(false)
        .show()
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

    if (mainViewModel.isRunning.value != true) {
        statusCaption.text = "در حال آماده‌سازی هوشمند"
    }

    syncManagedAccount(
        force = !BlueVpnAccountManager.snapshot(this).subscriptionActive
    )
    lifecycleScope.launch(Dispatchers.IO) {
        BlueVpnAi.refreshRecommendations(
            this@BlueVpnHomeActivity,
            force = false,
        )
        withContext(Dispatchers.Main) {
            startupOptimizationActive = false
            refreshDashboard()
            if (
                mainViewModel.isRunning.value != true &&
                !failoverActive
            ) {
                statusCaption.text =
                    "بهترین مسیر هنگام اتصال انتخاب می‌شود"
            }
        }
    }

    handler.postDelayed({
        startupOptimizationActive = false
    }, 4_000L)
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
        .allCandidates(this)
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

    val all = BlueVpnLocationUtil.allCandidates(this)

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

    val preferred = if (
        BlueVpnPreferences.smartBalance(this)
    ) {
        ""
    } else {
        BlueVpnPreferences.preferredLocation(this)
    }

    BlueVpnLocationUtil
        .instantCandidates(this, preferred)
        .firstOrNull()
        ?.let {
            MmkvManager.setSelectServer(it.guid)
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

    refreshDashboard()

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

    private fun toggleConnection() {
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
        cancelFailover()
        handler.removeCallbacks(attemptTimeout)
        handler.removeCallbacks(requestPing)
        handler.removeCallbacks(disconnectRetry)
        disconnectRetry.reset()

        CoreServiceManager.stopVService(this)
        BlueVpnPreferences.clearConnected(this)
        connectionVerified = false
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

    private fun beginSmartConnection() {
        userDisconnecting = false
        disconnectRetry.reset()
        handler.removeCallbacks(disconnectRetry)

        if (
            BlueVpnUpdateManager.blockInteraction(
                this
            )
        ) {
            return
        }

        if (!BlueVpnAccountManager.hasSession(this)) {
            openAccount()
            return
        }
        if (!BlueVpnAccountManager.active(this)) {
            Toast.makeText(this, "ابتدا اشتراک تهیه یا تمدید کنید", Toast.LENGTH_SHORT).show()
            openAccount()
            return
        }
        enforceReliableVpnSettings()
        BlueVpnPreferences.clearConnected(this)
        connectionVerified = false
        existingSessionCheckInProgress = false
        lastVerifiedLatency = 0L

        val selectedProfile = MmkvManager.getSelectServer()
            ?.let { MmkvManager.decodeServerConfig(it) }

        val selectedLocation = selectedProfile
            ?.takeIf { BlueVpnLocationUtil.isUsable(it) }
            ?.let { BlueVpnLocationUtil.detect(it.remarks, it.server).key }
            .orEmpty()

        val automaticSelection =
            BlueVpnPreferences.smartBalance(this)

        val preferredLocation =
            if (automaticSelection) {
                ""
            } else {
                BlueVpnPreferences.preferredLocation(this)
                    .ifBlank { selectedLocation }
            }

        val candidates = BlueVpnLocationUtil.instantCandidates(
            this,
            preferredLocation
        )

        if (candidates.isEmpty()) {
            liveLocationSwitch = false
            switchTargetTitle = ""

            Toast.makeText(
                this,
                if (automaticSelection) {
                    "در هیچ‌یک از لوکیشن‌ها سرور قابل استفاده‌ای وجود ندارد"
                } else {
                    "برای این لوکیشن هیچ مسیر قابل استفاده‌ای وجود ندارد"
                },
                Toast.LENGTH_SHORT
            ).show()

            statusText.text =
                if (automaticSelection) "سرور قابل اتصال نیست"
                else "لوکیشن قابل اتصال نیست"
            statusCaption.text =
                if (automaticSelection) {
                    "همه لوکیشن‌ها و سرورها بررسی شدند"
                } else {
                    "برای این لوکیشن مسیر سالمی پیدا نشد"
                }
            connectButton.isEnabled = true
            return
        }

        failoverQueue = candidates.map { it.guid }
        failoverIndex = 0
        failoverActive = true
        connectionVerified = false
        attemptedGuid = ""
        waitingForPingResult = false
        healthProbeInProgress = false

        connectButton.isEnabled = false
        statusText.text =
            if (liveLocationSwitch) {
                "در حال تغییر لوکیشن"
            } else {
                "یافتن مسیر سالم"
            }
        statusCaption.text =
            if (liveLocationSwitch) {
                "اتصال خودکار به ${switchTargetTitle.ifBlank { "لوکیشن جدید" }}"
            } else if (automaticSelection) {
                "در حال انتخاب سریع بهترین مسیر"
            } else {
                "در حال آماده‌سازی اتصال"
            }

        if (SettingsManager.isVpnMode()) {
            val permissionIntent = VpnService.prepare(this)
            if (permissionIntent == null) {
                startCurrentCandidate()
            } else {
                requestVpnPermission.launch(permissionIntent)
            }
        } else {
            startCurrentCandidate()
        }
    }

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
        handler.removeCallbacks(requestPing)
        handler.removeCallbacks(attemptTimeout)

        MmkvManager.setSelectServer(guid)
        refreshDashboard()

        val profile = MmkvManager.decodeServerConfig(guid)
        val location = profile?.let {
            BlueVpnLocationUtil.detect(it.remarks, it.server)
        }
        val locationName = location?.let { "${it.flag} ${it.title}" } ?: "لوکیشن انتخاب‌شده"

        statusText.text = "در حال اتصال"
        statusCaption.visibility = View.VISIBLE
        statusCaption.text =
            "در حال برقراری اتصال امن"
        updateConnectLabel("لغو اتصال")
        connectButton.isEnabled = true

        val startCore = Runnable {
            if (
                !failoverActive ||
                userDisconnecting ||
                attemptedGuid != guid
            ) return@Runnable
            CoreServiceManager.startVService(this)
            handler.postDelayed(attemptTimeout, 5_000L)
        }

        if (mainViewModel.isRunning.value == true) {
            CoreServiceManager.stopVService(this)
            handler.postDelayed(startCore, 320L)
        } else {
            startCore.run()
        }
    }

    private fun scheduleConnectionVerification() {
        if (
            !failoverActive ||
            userDisconnecting ||
            healthProbeInProgress
        ) return

        handler.postDelayed({
            if (failoverActive) {
                verifyTunnelThroughCore(
                    "این مسیر پاسخ مناسب نداد"
                )
            }
        }, 450L)
    }

    private fun handlePingResult() {
        // Ping updates are informational only. Connection failover no longer
        // waits for a full ping test, which keeps switching fast.
        refreshDashboard()
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

    private fun verifyExistingRunningSession() {
        if (existingSessionCheckInProgress || connectionVerified) return

        existingSessionCheckInProgress = true
        renderVerifyingState()

        lifecycleScope.launch(Dispatchers.IO) {
            val latency = probeInternetThroughCore()

            withContext(Dispatchers.Main) {
                existingSessionCheckInProgress = false

                if (mainViewModel.isRunning.value != true) {
                    connectionVerified = false
                    BlueVpnPreferences.clearConnected(this@BlueVpnHomeActivity)
                    renderConnectionState(false)
                    return@withContext
                }

                if (latency != null) {
                    lastVerifiedLatency = latency
                    connectionVerified = true
                    BlueVpnPreferences.markConnected(
                        this@BlueVpnHomeActivity,
                        resetTimer = false
                    )
                    resetTrafficBaseline()
                    renderConnectionState(true)
                    statusCaption.text =
                        "اتصال امن با موفقیت برقرار شد"
                    recordCurrentConnection(latency)
                    refreshVerifiedExitLocation()
                } else {
                    // A running core is not proof of a working VPN. Do not
                    // expose a false connected state or send a live heartbeat.
                    connectionVerified = false
                    BlueVpnPreferences.clearConnected(
                        this@BlueVpnHomeActivity
                    )
                    CoreServiceManager.stopVService(
                        this@BlueVpnHomeActivity
                    )
                    renderConnectionState(false)
                    statusText.text = "اتصال واقعی تأیید نشد"
                    statusCaption.text =
                        "این مسیر قابل استفاده نبود؛ دوباره تلاش کنید"
                }
            }
        }
    }

    private fun verifyTunnelThroughCore(reason: String) {
        if (!failoverActive || healthProbeInProgress) return
        if (MmkvManager.getSelectServer() != attemptedGuid) return

        healthProbeInProgress = true
        val guid = attemptedGuid

        lifecycleScope.launch(Dispatchers.IO) {
            val latency = probeInternetThroughCore()

            withContext(Dispatchers.Main) {
                if (!failoverActive || attemptedGuid != guid) {
                    return@withContext
                }

                healthProbeInProgress = false

                if (latency != null) {
                    lastVerifiedLatency = latency
                    completeFailover(latency)
                } else {
                    // Never count "core started" as a live connection. A route
                    // is accepted only after a remote proof succeeds through
                    // the local Xray proxy and the Android VPN transport.
                    failCurrentAndTryNext(reason)
                }
            }
        }
    }

    private suspend fun probeInternetThroughCore(): Long? =
        withContext(Dispatchers.IO) {
            val httpPort = SettingsManager.getHttpPort()

            if (httpPort !in 1..65535) {
                return@withContext null
            }

            // V2Box/Npv-style fast connect: race a small mixed endpoint set
            // and finish as soon as the first real response arrives. The old
            // implementation waited for every slow/blocked endpoint.
            val endpoints = listOf(
                "${BuildConfig.BLUEVPN_API_BASE_URL.trimEnd('/')}/health",
                "http://cp.cloudflare.com/generate_204",
                "http://connectivitycheck.gstatic.com/generate_204",
                "https://check-host.net/cdn-cgi/trace",
                "http://1.1.1.1/cdn-cgi/trace",
            )

            val executor = Executors.newFixedThreadPool(endpoints.size)
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
                    SystemClock.elapsedRealtime() + 2_400L

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
                connection.connectTimeout = 1_600
                connection.readTimeout = 1_600
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
                    if (endpoint.contains("check-host.net/cdn-cgi/trace")) {
                        body.lineSequence()
                            .firstOrNull { it.startsWith("loc=") }
                            ?.substringAfter("loc=")
                            ?.trim()
                            ?.takeIf { it.length == 2 }
                            ?.let { countryCode ->
                                BlueVpnPreferences.markVerifiedCountry(
                                    this@BlueVpnHomeActivity,
                                    attemptedGuid,
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
        BlueVpnPreferences.markServerSuccess(
            this,
            attemptedGuid,
            delay ?: lastVerifiedLatency,
        )
        BlueVpnPreferences.markConnected(this, resetTimer = true)

        failoverActive = false
        waitingForPingResult = false
        healthProbeInProgress = false
        connectionVerified = true
        liveLocationSwitch = false
        switchTargetTitle = ""
        connectButton.isEnabled = true
        resetTrafficBaseline()
        refreshDashboard()
        renderConnectionState(true)
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
    }

    private fun refreshVerifiedExitLocation() {
        val port = SettingsManager.getHttpPort()
        if (port !in 1..65535 || attemptedGuid.isBlank()) return
        lifecycleScope.launch(Dispatchers.IO) {
            requestThroughLocalXrayProxy(
                endpoint = "https://check-host.net/cdn-cgi/trace",
                httpPort = port,
            )
            withContext(Dispatchers.Main) {
                if (!isFinishing && !isDestroyed) {
                    refreshDashboard(force = true)
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

        if (failedGuid.isNotBlank()) {
            BlueVpnPreferences.markServerFailure(this, failedGuid)
            MmkvManager.encodeServerTestDelayMillis(failedGuid, -1L)
        }

        CoreServiceManager.stopVService(this)
        failoverIndex += 1
        waitingForPingResult = false
        healthProbeInProgress = false

        if (failoverIndex >= failoverQueue.size) {
            finishFailoverWithError()
            return
        }

        statusText.text = "تغییر مسیر خودکار"
        statusCaption.text =
            "مسیر بهتر به‌صورت خودکار در حال انتخاب است"

        handler.postDelayed({
            if (failoverActive) startCurrentCandidate()
        }, 250L)
    }

    private fun finishFailoverWithError() {
        handler.removeCallbacks(requestPing)
        handler.removeCallbacks(attemptTimeout)
        CoreServiceManager.stopVService(this)

        failoverActive = false
        waitingForPingResult = false
        healthProbeInProgress = false
        connectionVerified = false
        liveLocationSwitch = false
        switchTargetTitle = ""
        BlueVpnPreferences.clearConnected(this)
        connectButton.isEnabled = true
        updateConnectLabel("تلاش دوباره")
        applyOrbVisual(OrbVisualState.ERROR)
        statusText.text = "لوکیشن در دسترس نیست"
        statusCaption.visibility = View.VISIBLE
        statusCaption.text =
            "همه مسیرهای این لوکیشن بررسی شدند؛ بعداً دوباره امتحان کنید"
        statusDot.backgroundTintList =
            ColorStateList.valueOf(Color.parseColor("#FFB44A"))

        Toast.makeText(
            this,
            "هیچ‌کدام از مسیرهای این لوکیشن پاسخ ندادند",
            Toast.LENGTH_LONG
        ).show()
    }

    private fun cancelFailover() {
        handler.removeCallbacks(requestPing)
        handler.removeCallbacks(attemptTimeout)
        failoverActive = false
        failoverQueue = emptyList()
        failoverIndex = -1
        attemptedGuid = ""
        waitingForPingResult = false
        healthProbeInProgress = false
        connectionVerified = false
        liveLocationSwitch = false
        switchTargetTitle = ""
        BlueVpnPreferences.clearConnected(this)
        connectButton.isEnabled = true
    }

    private fun renderConnectionState(connected: Boolean) {
        if (failoverActive) {
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

            updateConnectLabel("قطع اتصال")
            applyOrbVisual(OrbVisualState.CONNECTED)
            statusText.text = "متصل هستید"
            statusCaption.text = ""
            statusCaption.visibility = View.GONE
            statusDot.backgroundTintList =
                ColorStateList.valueOf(Color.parseColor("#36E6A7"))
        } else {
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

    private fun refreshDashboard(
        force: Boolean = false,
    ) {
        val now = SystemClock.elapsedRealtime()
        if (!force && now - lastDashboardRefreshAt < 220L) {
            return
        }
        lastDashboardRefreshAt = now

        val candidates = BlueVpnLocationUtil.allCandidates(this)
        val selectedGuid = MmkvManager.getSelectServer()
        val selected = candidates.firstOrNull {
            it.guid == selectedGuid
        }
        val profile = selected?.profile
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
            val location = selected.location
            val delay = selected.delay
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
        val subscriptionCount =
            MmkvManager.decodeSubscriptions().size

        val managed = BlueVpnAccountManager.snapshot(this)
        subscriptionSummary.text = when {
            managed.email.isNotBlank() && managed.subscriptionActive ->
                "اشتراک فعال • ${managed.email}"
            managed.email.isNotBlank() ->
                "${managed.email} • نیاز به تمدید"
            subscriptionCount > 0 ->
                "اشتراک فعال • $locationCount مکان"
            usableCount > 0 ->
                "$locationCount مکان آماده اتصال"
            else ->
                "هنوز اشتراکی اضافه نشده است"
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
        if (BlueVpnAccountManager.hasSession(this)) {
            val managed = BlueVpnAccountManager.snapshot(this)

            remainingVolume.text = when {
                !managed.subscriptionActive -> "بدون اشتراک"
                managed.dataLimitBytes <= 0L -> "نامحدود"
                else -> formatBytes(
                    (
                        managed.dataLimitBytes -
                            managed.usedTrafficBytes
                    ).coerceAtLeast(0L)
                )
            }

            remainingTime.text = when {
                !managed.subscriptionActive -> "پایان یافته"
                managed.expire.isNullOrBlank() -> "نامحدود"
                else -> formatAccountRemainingTime(managed.expire)
            }

            getSharedPreferences(
                "bluevpn_subscription_info",
                MODE_PRIVATE
            ).edit().clear().apply()
            return
        }

        val prefs = getSharedPreferences(
            "bluevpn_subscription_info",
            MODE_PRIVATE
        )
        val cacheAt = prefs.getLong("updated_at", 0L)

        if (!force &&
            System.currentTimeMillis() - cacheAt < 60_000L
        ) {
            remainingVolume.text =
                prefs.getString("volume", "نامشخص")
            remainingTime.text = cleanRemainingTime(
                prefs.getString("time", "نامشخص").orEmpty()
            )
            return
        }

        val subscription =
            MmkvManager.decodeSubscriptions()
                .firstOrNull {
                    it.subscription.remarks == "BlueVPN Account"
                }
                ?.subscription
                ?: MmkvManager.getSelectServer()
                    ?.let { MmkvManager.decodeServerConfig(it) }
                    ?.subscriptionId
                    ?.takeIf { it.isNotBlank() }
                    ?.let { MmkvManager.decodeSubscription(it) }

        val url = subscription?.url.orEmpty()
        if (!url.startsWith("http://") &&
            !url.startsWith("https://")
        ) {
            remainingVolume.text = "نامشخص"
            remainingTime.text = "نامشخص"
            prefs.edit().clear().apply()
            return
        }

        lifecycleScope.launch(Dispatchers.IO) {
            val info = fetchSubscriptionUserInfo(url)
            withContext(Dispatchers.Main) {
                val volume = info?.first ?: "نامشخص"
                val time = info?.second ?: "نامشخص"

                remainingVolume.text = volume
                remainingTime.text = time

                prefs.edit()
                    .putLong(
                        "updated_at",
                        System.currentTimeMillis()
                    )
                    .putString("volume", volume)
                    .putString("time", time)
                    .apply()
            }
        }
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
        if (accountSyncInProgress) return

        accountSyncInProgress = true
        lifecycleScope.launch(Dispatchers.IO) {
            val result = BlueVpnAccountManager.sync(
                this@BlueVpnHomeActivity,
                force
            )
            withContext(Dispatchers.Main) {
                accountSyncInProgress = false

                result.onSuccess {
                    mainViewModel.reloadServerList()
                    refreshDashboard()
                    refreshSubscriptionInfo(force = true)
                }.onFailure {
                    refreshSubscriptionInfo(force = true)

                    if (!BlueVpnAccountManager.hasSession(
                            this@BlueVpnHomeActivity
                        )
                    ) {
                        openAccount()
                    }
                }
            }
        }
    }

    private fun openAccount() {
        if (navigationLocked || accountLaunchInProgress) return
        navigationLocked = true
        accountLaunchInProgress = true
        accountLauncher.launch(
            Intent(
                this,
                BlueVpnSubscriptionsActivity::class.java,
            )
        )
        overridePendingTransition(
            R.anim.bluevpn_fade_in,
            R.anim.bluevpn_fade_out,
        )
    }

    private fun openSettings() {
        if (navigationLocked) return
        navigationLocked = true
        startActivity(
            Intent(
                this,
                BlueVpnSettingsActivity::class.java,
            )
        )
        overridePendingTransition(
            R.anim.bluevpn_fade_in,
            R.anim.bluevpn_fade_out,
        )
    }

    private fun openServers() {
        if (navigationLocked) return
        navigationLocked = true

        serversOpenedWhileActive =
            mainViewModel.isRunning.value == true ||
                connectionVerified ||
                failoverActive

        selectLocationLauncher.launch(
            Intent(this, BlueVpnServersActivity::class.java)
        )
        overridePendingTransition(
            R.anim.bluevpn_fade_in,
            R.anim.bluevpn_fade_out,
        )
    }
}
