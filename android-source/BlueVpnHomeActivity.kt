package com.v2ray.ang.ui

import android.animation.ValueAnimator
import android.app.Dialog
import android.content.Intent
import android.content.res.ColorStateList
import android.graphics.Color
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
import com.v2ray.ang.bluevpn.BlueVpnAi
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

    private lateinit var connectButton: AppCompatTextView
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

    /**
     * Builds the complete BlueVPN home screen without inflating XML.
     * The layout intentionally uses only lightweight platform Views and
     * MaterialCardView so cold start stays fast on older Android devices.
     */
    private fun createScreen(): View {
        val root = FrameLayout(this).apply {
            layoutDirection = View.LAYOUT_DIRECTION_RTL
            setBackgroundResource(R.drawable.bluevpn_screen_background)
            fitsSystemWindows = true
            clipChildren = false
            clipToPadding = false
        }

        val scroll = ScrollView(this).apply {
            isFillViewport = true
            overScrollMode = View.OVER_SCROLL_NEVER
            clipToPadding = false
            setPadding(dpHome(16), dpHome(10), dpHome(16), dpHome(24))
        }
        root.addView(
            scroll,
            FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT,
            ),
        )

        val content = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            layoutDirection = View.LAYOUT_DIRECTION_RTL
        }
        scroll.addView(
            content,
            FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT,
            ),
        )

        content.addView(
            createHeader(),
            LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                dpHome(58),
            ),
        )
        content.addView(
            createOrbStage(),
            LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                dpHome(410),
            ).apply { topMargin = dpHome(12) },
        )
        content.addView(
            createAiCard(),
            LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT,
            ).apply { topMargin = dpHome(12) },
        )
        content.addView(
            createServerCard(),
            LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT,
            ).apply { topMargin = dpHome(10) },
        )
        content.addView(
            createModeRow(),
            LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                dpHome(46),
            ).apply { topMargin = dpHome(12) },
        )
        content.addView(
            createActionRow(),
            LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                dpHome(56),
            ).apply { topMargin = dpHome(12) },
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

        val logo = ImageView(this).apply {
            setImageResource(R.mipmap.ic_launcher)
            contentDescription = getString(R.string.app_name)
            background = roundedGradient(
                intArrayOf(
                    Color.parseColor("#203D78"),
                    Color.parseColor("#10244A"),
                ),
                18,
                Color.parseColor("#4D8FE8"),
            )
            setPadding(dpHome(5), dpHome(5), dpHome(5), dpHome(5))
            elevation = dpHome(4).toFloat()
        }
        row.addView(
            logo,
            LinearLayout.LayoutParams(dpHome(48), dpHome(48)),
        )

        val titleColumn = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER_VERTICAL
        }
        row.addView(
            titleColumn,
            LinearLayout.LayoutParams(0, dpHome(56), 1f).apply {
                marginStart = dpHome(11)
            },
        )
        titleColumn.addView(
            uiText("BlueVPN", 22f, Color.WHITE, bold = true),
        )
        subscriptionSummary = uiText(
            "در حال بررسی اشتراک",
            10.5f,
            Color.parseColor("#A8BBDD"),
        ).apply {
            id = R.id.bluevpn_subscription_summary
            maxLines = 1
            ellipsize = android.text.TextUtils.TruncateAt.END
        }
        titleColumn.addView(
            subscriptionSummary,
            LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT,
            ).apply { topMargin = dpHome(2) },
        )

        row.addView(
            uiText("PREMIUM", 9.5f, Color.parseColor("#EAF3FF"), bold = true).apply {
                id = R.id.bluevpn_premium_badge
                gravity = Gravity.CENTER
                background = roundedGradient(
                    intArrayOf(
                        Color.parseColor("#264B83"),
                        Color.parseColor("#17345F"),
                    ),
                    18,
                    Color.parseColor("#4F8EDB"),
                )
                setPadding(dpHome(12), 0, dpHome(12), 0)
            },
            LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT,
                dpHome(36),
            ),
        )

        return row
    }

    private fun createOrbStage(): View {
        val card = glassCard(30, Color.parseColor("#1C3868"), Color.argb(174, 10, 28, 58))
        val stage = FrameLayout(this).apply {
            clipChildren = false
            clipToPadding = false
            setPadding(dpHome(14), dpHome(14), dpHome(14), dpHome(14))
        }
        card.addView(
            stage,
            FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT,
            ),
        )

        statusDot = View(this).apply {
            id = R.id.bluevpn_status_dot
            background = circleDrawable(Color.parseColor("#8FA7CA"))
        }
        stage.addView(
            statusDot,
            FrameLayout.LayoutParams(dpHome(10), dpHome(10), Gravity.TOP or Gravity.END).apply {
                topMargin = dpHome(16)
                marginEnd = dpHome(18)
            },
        )

        statusText = uiText("آماده اتصال", 21f, Color.WHITE, bold = true, gravity = Gravity.CENTER).apply {
            id = R.id.bluevpn_status_text
        }
        stage.addView(
            statusText,
            FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                dpHome(34),
                Gravity.TOP or Gravity.CENTER_HORIZONTAL,
            ).apply { topMargin = dpHome(4) },
        )

        statusCaption = uiText(
            "بهترین مسیر هنگام اتصال انتخاب می‌شود",
            10.5f,
            Color.parseColor("#A7BBDD"),
            gravity = Gravity.CENTER,
        ).apply {
            id = R.id.bluevpn_status_caption
            maxLines = 2
        }
        stage.addView(
            statusCaption,
            FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                dpHome(40),
                Gravity.TOP or Gravity.CENTER_HORIZONTAL,
            ).apply {
                topMargin = dpHome(39)
                marginStart = dpHome(22)
                marginEnd = dpHome(22)
            },
        )

        val qualityChip = glassCard(
            18,
            Color.parseColor("#315F98"),
            Color.argb(190, 15, 40, 79),
        )
        val qualityColumn = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER
        }
        qualityChip.addView(
            qualityColumn,
            FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT,
            ),
        )
        qualityColumn.addView(
            uiText("AI SCORE", 7.5f, Color.parseColor("#92A9CE"), gravity = Gravity.CENTER),
        )
        qualityValue = uiText("—", 12.5f, Color.parseColor("#73E6C0"), bold = true, gravity = Gravity.CENTER).apply {
            id = R.id.bluevpn_quality_value
        }
        qualityColumn.addView(qualityValue)
        stage.addView(
            qualityChip,
            FrameLayout.LayoutParams(dpHome(72), dpHome(54), Gravity.TOP or Gravity.END).apply {
                topMargin = dpHome(82)
                marginEnd = dpHome(2)
            },
        )

        orbHaloOuter = View(this).apply {
            background = radialHaloDrawable(Color.parseColor("#2F7BFF"), 68)
            alpha = 0.42f
        }
        stage.addView(
            orbHaloOuter,
            FrameLayout.LayoutParams(dpHome(236), dpHome(236), Gravity.TOP or Gravity.CENTER_HORIZONTAL).apply {
                topMargin = dpHome(76)
            },
        )

        orbHaloInner = View(this).apply {
            background = radialHaloDrawable(Color.parseColor("#4DA0FF"), 92)
            alpha = 0.55f
        }
        stage.addView(
            orbHaloInner,
            FrameLayout.LayoutParams(dpHome(194), dpHome(194), Gravity.TOP or Gravity.CENTER_HORIZONTAL).apply {
                topMargin = dpHome(97)
            },
        )

        connectButton = AppCompatTextView(this).apply {
            id = R.id.bluevpn_connect_button
            text = "اتصال"
            textSize = 18f
            setTextColor(Color.WHITE)
            typeface = Typeface.DEFAULT_BOLD
            gravity = Gravity.CENTER
            isClickable = true
            isFocusable = true
            contentDescription = "اتصال یا قطع BlueVPN"
            elevation = dpHome(18).toFloat()
            setPadding(dpHome(12), dpHome(12), dpHome(12), dpHome(12))
        }
        stage.addView(
            connectButton,
            FrameLayout.LayoutParams(dpHome(158), dpHome(158), Gravity.TOP or Gravity.CENTER_HORIZONTAL).apply {
                topMargin = dpHome(115)
            },
        )

        val downloadCard = createFloatingStatCard(
            "↓ دانلود",
            "۰ B/s",
            "↑ آپلود ۰ B/s",
        ) { primary, secondary ->
            downloadSpeed = primary.apply { id = R.id.bluevpn_download_speed }
            uploadSpeed = secondary.apply { id = R.id.bluevpn_upload_speed }
        }
        stage.addView(
            downloadCard,
            FrameLayout.LayoutParams(dpHome(108), dpHome(74), Gravity.TOP or Gravity.START).apply {
                topMargin = dpHome(250)
                marginStart = dpHome(2)
            },
        )

        val pingCard = createFloatingStatCard(
            "پینگ",
            "— ms",
            "مسیر واقعی",
        ) { primary, _ ->
            pingValue = primary.apply { id = R.id.bluevpn_ping_value }
        }
        stage.addView(
            pingCard,
            FrameLayout.LayoutParams(dpHome(108), dpHome(74), Gravity.TOP or Gravity.END).apply {
                topMargin = dpHome(250)
                marginEnd = dpHome(2)
            },
        )

        durationValue = uiText("۰۰:۰۰:۰۰", 10f, Color.parseColor("#A9C8F2"), bold = true, gravity = Gravity.CENTER).apply {
            id = R.id.bluevpn_duration_value
            background = roundedGradient(
                intArrayOf(Color.argb(125, 18, 51, 95), Color.argb(105, 11, 33, 68)),
                16,
                Color.parseColor("#315F98"),
            )
        }
        stage.addView(
            durationValue,
            FrameLayout.LayoutParams(dpHome(96), dpHome(30), Gravity.TOP or Gravity.CENTER_HORIZONTAL).apply {
                topMargin = dpHome(282)
            },
        )

        serverName = uiText("انتخاب خودکار BlueAI", 14.5f, Color.WHITE, bold = true, gravity = Gravity.CENTER).apply {
            id = R.id.bluevpn_server_name
            maxLines = 1
            ellipsize = android.text.TextUtils.TruncateAt.END
        }
        stage.addView(
            serverName,
            FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                dpHome(26),
                Gravity.BOTTOM or Gravity.CENTER_HORIZONTAL,
            ).apply {
                bottomMargin = dpHome(38)
                marginStart = dpHome(20)
                marginEnd = dpHome(20)
            },
        )

        serverMeta = uiText(
            "برای انتخاب کشور، کارت مسیر را لمس کنید",
            10f,
            Color.parseColor("#8FAED6"),
            gravity = Gravity.CENTER,
        ).apply {
            id = R.id.bluevpn_server_meta
            maxLines = 1
            ellipsize = android.text.TextUtils.TruncateAt.END
        }
        stage.addView(
            serverMeta,
            FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                dpHome(24),
                Gravity.BOTTOM or Gravity.CENTER_HORIZONTAL,
            ).apply {
                bottomMargin = dpHome(13)
                marginStart = dpHome(20)
                marginEnd = dpHome(20)
            },
        )

        applyOrbVisual(OrbVisualState.IDLE)
        return card
    }

    private fun createAiCard(): View {
        val card = glassCard(22, Color.parseColor("#2B568F"), Color.argb(168, 9, 31, 65)).apply {
            id = R.id.bluevpn_ai_card
            isClickable = true
            isFocusable = true
        }
        val row = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            setPadding(dpHome(14), dpHome(13), dpHome(14), dpHome(13))
        }
        card.addView(
            row,
            FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT,
            ),
        )

        row.addView(
            uiText("AI", 12f, Color.parseColor("#9EEBFF"), bold = true, gravity = Gravity.CENTER).apply {
                background = roundedGradient(
                    intArrayOf(Color.parseColor("#214D83"), Color.parseColor("#123460")),
                    17,
                    Color.parseColor("#3B78BD"),
                )
            },
            LinearLayout.LayoutParams(dpHome(42), dpHome(42)),
        )
        aiSummaryValue = uiText(
            "در حال شناخت شبکه شما",
            10.5f,
            Color.parseColor("#E1ECFF"),
        ).apply {
            id = R.id.bluevpn_ai_summary
            maxLines = 2
        }
        row.addView(
            aiSummaryValue,
            LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f).apply {
                marginStart = dpHome(11)
            },
        )
        row.addView(uiText("جزئیات ←", 9.5f, Color.parseColor("#7FB9FF"), bold = true))
        return card
    }

    private fun createServerCard(): View {
        val card = glassCard(22, Color.parseColor("#294F85"), Color.argb(164, 8, 28, 60)).apply {
            id = R.id.bluevpn_server_card
            isClickable = true
            isFocusable = true
        }
        val row = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            setPadding(dpHome(14), dpHome(12), dpHome(14), dpHome(12))
        }
        card.addView(
            row,
            FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT,
            ),
        )
        row.addView(
            uiText("🌍", 18f, Color.WHITE, gravity = Gravity.CENTER).apply {
                background = roundedGradient(
                    intArrayOf(Color.parseColor("#214A7D"), Color.parseColor("#12325D")),
                    17,
                    Color.parseColor("#3C76B7"),
                )
            },
            LinearLayout.LayoutParams(dpHome(44), dpHome(44)),
        )
        val column = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL }
        row.addView(
            column,
            LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f).apply {
                marginStart = dpHome(11)
            },
        )
        column.addView(uiText("کشور و مسیر اتصال", 13f, Color.WHITE, bold = true))
        column.addView(
            uiText("انتخاب خودکار یا دستی لوکیشن", 9.5f, Color.parseColor("#92A9CD")),
            LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT,
                ViewGroup.LayoutParams.WRAP_CONTENT,
            ).apply { topMargin = dpHome(3) },
        )
        row.addView(uiText("تغییر ←", 9.5f, Color.parseColor("#7FB9FF"), bold = true))
        return card
    }

    private fun createModeRow(): View {
        val row = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER
        }
        balancedModeButton = modeButton("متعادل", R.id.bluevpn_mode_balanced)
        gamingModeButton = modeButton("بازی", R.id.bluevpn_mode_gaming)
        streamingModeButton = modeButton("استریم", R.id.bluevpn_mode_streaming)
        row.addView(balancedModeButton, LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.MATCH_PARENT, 1f))
        row.addView(
            gamingModeButton,
            LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.MATCH_PARENT, 1f).apply { marginStart = dpHome(7) },
        )
        row.addView(
            streamingModeButton,
            LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.MATCH_PARENT, 1f).apply { marginStart = dpHome(7) },
        )
        return row
    }

    private fun createActionRow(): View {
        val row = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL }
        row.addView(actionButton("سرورها", R.id.bluevpn_action_servers), LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.MATCH_PARENT, 1f))
        row.addView(
            actionButton("اشتراک", R.id.bluevpn_action_subscription),
            LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.MATCH_PARENT, 1f).apply { marginStart = dpHome(8) },
        )
        row.addView(
            actionButton("تنظیمات", R.id.bluevpn_action_settings),
            LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.MATCH_PARENT, 1f).apply { marginStart = dpHome(8) },
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
        remainingVolume = hiddenText(R.id.bluevpn_remaining_volume)
        remainingTime = hiddenText(R.id.bluevpn_remaining_time)
        modeValue = hiddenText(R.id.bluevpn_mode_value)
        activeRoutesValue = hiddenText(R.id.bluevpn_active_routes_value)
        historyValue = hiddenText(R.id.bluevpn_history_value)
        hidden.addView(locationValue)
        hidden.addView(remainingVolume)
        hidden.addView(remainingTime)
        hidden.addView(modeValue)
        hidden.addView(activeRoutesValue)
        hidden.addView(historyValue)
        hidden.addView(MaterialButton(this).apply { id = R.id.bluevpn_refresh_subscription })
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
                intArrayOf(Color.argb(190, 25, 61, 108), Color.argb(185, 12, 38, 78)),
                19,
                Color.parseColor("#386CA8"),
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

        val palette = when (state) {
            OrbVisualState.IDLE -> intArrayOf(
                Color.parseColor("#276DDF"),
                Color.parseColor("#123E8C"),
                Color.parseColor("#0C285D"),
            )
            OrbVisualState.CONNECTING -> intArrayOf(
                Color.parseColor("#2F8CFF"),
                Color.parseColor("#5E4BDE"),
                Color.parseColor("#173E8D"),
            )
            OrbVisualState.CONNECTED -> intArrayOf(
                Color.parseColor("#22D39B"),
                Color.parseColor("#147FC4"),
                Color.parseColor("#0B4D88"),
            )
            OrbVisualState.ERROR -> intArrayOf(
                Color.parseColor("#F15C72"),
                Color.parseColor("#A92E55"),
                Color.parseColor("#551D42"),
            )
        }
        val stroke = when (state) {
            OrbVisualState.CONNECTED -> Color.parseColor("#8DFFE0")
            OrbVisualState.ERROR -> Color.parseColor("#FF9FB0")
            else -> Color.parseColor("#8DC5FF")
        }
        val halo = when (state) {
            OrbVisualState.CONNECTED -> Color.parseColor("#31E6B2")
            OrbVisualState.ERROR -> Color.parseColor("#FF5576")
            OrbVisualState.CONNECTING -> Color.parseColor("#6B70FF")
            OrbVisualState.IDLE -> Color.parseColor("#357DFF")
        }

        connectButton.background = GradientDrawable(
            GradientDrawable.Orientation.TL_BR,
            palette,
        ).apply {
            shape = GradientDrawable.OVAL
            setStroke(dpHome(2), stroke)
        }
        connectButton.alpha = if (connectButton.isEnabled) 1f else 0.72f
        orbHaloInner.background = radialHaloDrawable(halo, 100)
        orbHaloOuter.background = radialHaloDrawable(halo, 74)

        // Keep the connected state visually rich but static. A permanent
        // 60-fps pulse while the tunnel is active wastes GPU and heats phones.
        setOrbPulseEnabled(state == OrbVisualState.CONNECTING)
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
        setContentView(createScreen())

        window.statusBarColor = Color.parseColor("#07152F")
        window.navigationBarColor = Color.parseColor("#07152F")

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
        ).text = "PREMIUM ${BuildConfig.VERSION_NAME}"

        findViewById<View>(R.id.bluevpn_ai_card).setOnClickListener {
            startActivity(Intent(this, BlueVpnAiActivity::class.java))
        }

        connectButton.setOnClickListener {
            toggleConnection()
        }
        balancedModeButton.setOnClickListener {
            setConnectionMode(
                BlueVpnConnectionMode.BALANCED
            )
        }
        gamingModeButton.setOnClickListener {
            setConnectionMode(
                BlueVpnConnectionMode.GAMING
            )
        }
        streamingModeButton.setOnClickListener {
            setConnectionMode(
                BlueVpnConnectionMode.STREAMING
            )
        }

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
    }

    override fun onStop() {
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
        super.onDestroy()
    }

    override fun onResume() {
        super.onResume()
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
                    if (active) "#247CFF" else "#173B6C"
                )
            )
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

    val all = cachedCandidates ?: BlueVpnLocationUtil.allCandidates()
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
        .allCandidates()
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
            "نسخه Ultimate با طراحی حرفه‌ای، BlueAI، یادگیری اپراتور، رتبه‌بندی جمعی، ترمیم خودکار مسیر و داشبورد هوشمند آماده است."
        )
        .setPositiveButton("شروع هوشمند") { _, _ ->
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
        statusCaption.text = "به‌روزرسانی سریع اشتراک در پس‌زمینه"
    }

    syncManagedAccount(force = false)
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
                    "آماده اتصال؛ بهترین مسیر هنگام اتصال انتخاب می‌شود"
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
        .allCandidates()
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

    val all = BlueVpnLocationUtil.allCandidates()

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
        .orderedCandidates(this, preferred)
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
        connectButton.text = "در حال قطع"
        statusText.text = "در حال قطع اتصال"
        statusCaption.text =
            "درخواست توقف فوری به هسته ارسال شد"
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

        val candidates = BlueVpnLocationUtil.orderedCandidates(
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
                "بررسی ${failoverQueue.size} سرور از همه لوکیشن‌ها"
            } else {
                "در حال آماده‌سازی ${failoverQueue.size} مسیر این لوکیشن"
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
        statusCaption.text =
            "$locationName • مسیر ${failoverIndex + 1} از ${failoverQueue.size}"
        connectButton.text = "لغو اتصال"
        connectButton.isEnabled = true

        val startCore = Runnable {
            if (
                !failoverActive ||
                userDisconnecting ||
                attemptedGuid != guid
            ) return@Runnable
            CoreServiceManager.startVService(this)
            handler.postDelayed(attemptTimeout, 6_000L)
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
                    "این مسیر از داخل هسته Xray اینترنت ندارد"
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
        connectButton.text = "لغو اتصال"
        connectButton.isEnabled = true
        applyOrbVisual(OrbVisualState.CONNECTING)
        statusText.text = "در حال تأیید اینترنت"
        statusCaption.text =
            "روشن‌شدن هسته کافی نیست؛ مسیر واقعی در حال بررسی است"
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
                        "مسیر واقعی Xray تأیید شد • ${latency} ms"
                    recordCurrentConnection(latency)
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
                        "هسته روشن بود اما هیچ درخواست واقعی از مسیر Xray عبور نکرد"
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
        BlueVpnPreferences.clearServerFailure(this, attemptedGuid)
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
        val delayText = verifiedDelay
            .takeIf { it > 0L }
            ?.let { " • ${it} ms" }
            .orEmpty()
        statusCaption.text =
            if (completedLiveSwitch) {
                "لوکیشن به ${completedTargetTitle.ifBlank { "مقصد جدید" }} تغییر کرد${delayText}"
            } else {
                "اینترنت واقعی تأیید شد${delayText} • جایگزینی خودکار فعال است"
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
            "$reason؛ امتحان مسیر ${failoverIndex + 1} از ${failoverQueue.size}"

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
        connectButton.text = "تلاش دوباره"
        applyOrbVisual(OrbVisualState.ERROR)
        statusText.text = "لوکیشن در دسترس نیست"
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
            connectButton.text = "لغو اتصال"
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

            connectButton.text = "قطع اتصال"
            applyOrbVisual(OrbVisualState.CONNECTED)
            statusText.text = "متصل هستید"
            statusCaption.text = "ارتباط امن BlueVPN برقرار است"
            statusDot.backgroundTintList =
                ColorStateList.valueOf(Color.parseColor("#36E6A7"))
        } else {
            BlueVpnPreferences.clearConnected(this)
            connectionVerified = false
            lastHistoryGuid = ""
            resetTrafficBaseline()

            connectButton.text = "اتصال"
            applyOrbVisual(OrbVisualState.IDLE)
            statusText.text = "آماده اتصال"
            statusCaption.text = "یک لوکیشن انتخاب کنید و اتصال را بزنید"
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

        val candidates = BlueVpnLocationUtil.allCandidates()
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
                    "${location.flag} ${location.title} • انتخاب از $routeCount سرور"
                } else {
                    when {
                        failoverActive ->
                            "$routeCount مسیر جایگزین • بررسی مسیر ${failoverIndex + 1}"
                        delay > 0L ->
                            "$routeCount مسیر جایگزین • پینگ ${delay} ms"
                        else ->
                            "$routeCount مسیر این لوکیشن"
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
                "${managed.email} • اشتراک فعال • $locationCount لوکیشن"
            managed.email.isNotBlank() ->
                "${managed.email} • نیاز به تمدید"
            subscriptionCount > 0 ->
                "$subscriptionCount اشتراک • $locationCount لوکیشن • $usableCount مسیر"
            usableCount > 0 ->
                "$locationCount لوکیشن • $usableCount مسیر"
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
            "بدون قطع دستی، اتصال به ${selectedTitle.ifBlank { "لوکیشن جدید" }} آغاز شد"
        statusDot.backgroundTintList =
            ColorStateList.valueOf(Color.parseColor("#FFB44A"))
        connectButton.text = "در حال جابه‌جایی"
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
