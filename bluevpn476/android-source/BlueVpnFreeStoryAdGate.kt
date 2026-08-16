package com.v2ray.ang.bluevpn

import android.app.Activity
import android.app.Dialog
import android.graphics.BitmapFactory
import android.graphics.Color
import android.graphics.Typeface
import android.graphics.drawable.ColorDrawable
import android.graphics.drawable.GradientDrawable
import android.os.CountDownTimer
import android.os.Handler
import android.os.Looper
import android.view.Gravity
import android.view.View
import android.view.ViewGroup
import android.view.Window
import android.view.WindowManager
import android.widget.FrameLayout
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.ProgressBar
import android.widget.TextView
import android.widget.VideoView
import com.v2ray.ang.BuildConfig
import org.json.JSONObject
import java.io.ByteArrayOutputStream
import java.net.HttpURLConnection
import java.net.URL
import java.util.concurrent.Executors
import kotlin.math.max
import kotlin.random.Random

/**
 * Mandatory first-party story ad gate for the Free plan.
 *
 * Xray may already be RUNNING when this gate opens, but BlueVPN deliberately
 * keeps the connection in a pending state: no connected timestamp, no Free
 * session timer and no CONNECTED UI are committed until the media completes.
 * The host Activity stops the VPN if the user backgrounds the mandatory story,
 * preventing Home/Back from becoming an ad bypass.
 *
 * Media/config failures are fail-open so a broken campaign or temporary cPanel
 * outage can never disable Free VPN for every user.
 */
class BlueVpnFreeStoryAdGate(private val activity: Activity) {

    enum class Outcome {
        COMPLETED,
        UNAVAILABLE,
        ABORTED,
        ACTION_OPENED,
    }

    private data class StoryItem(
        val id: String,
        val title: String,
        val subtitle: String,
        val mediaType: String,
        val mediaUrl: String,
        val targetAction: String,
        val targetPlanId: Int,
        val targetUrl: String,
        val buttonText: String,
        val weight: Int,
        val imageDurationSeconds: Int,
    )

    private val main = Handler(Looper.getMainLooper())
    private val worker = Executors.newSingleThreadExecutor()
    private var dialog: Dialog? = null
    private var videoView: VideoView? = null
    private var imageTimer: CountDownTimer? = null
    private var videoProgress: Runnable? = null
    private var loadTimeout: Runnable? = null
    private var callback: ((Outcome) -> Unit)? = null
    private var active = false
    private var mediaStarted = false
    private var required = true
    private var loadTimeoutMs = 8_000L
    private var maxVideoMs = 30_000L

    fun isActive(): Boolean = active

    fun start(onOutcome: (Outcome) -> Unit) {
        if (active) return
        active = true
        callback = onOutcome
        worker.execute {
            val parsed = runCatching {
                val root = BlueVpnAccountManager.mobileConfig(activity.applicationContext, force = false).getOrThrow()
                parseConfig(root)
            }.getOrNull()
            if (parsed == null) {
                main.post { finish(Outcome.UNAVAILABLE) }
                return@execute
            }

            val (config, item) = parsed
            required = config.optBoolean("required", true)
            loadTimeoutMs = config.optLong("load_timeout_ms", 8_000L).coerceIn(3_000L, 15_000L)
            maxVideoMs = config.optLong("max_video_seconds", 30L).coerceIn(5L, 60L) * 1000L

            if (item.mediaType == "image") {
                val bitmap = downloadImage(item.mediaUrl)
                main.post {
                    if (!active) return@post
                    if (bitmap == null) finish(Outcome.UNAVAILABLE)
                    else showImageStory(item, bitmap)
                }
            } else {
                main.post {
                    if (!active) return@post
                    showVideoStory(item)
                }
            }
        }
    }

    fun abort() {
        if (!active) return
        finish(Outcome.ABORTED)
    }

    fun release() {
        if (!active) {
            worker.shutdownNow()
            return
        }
        callback = null
        cleanupUi()
        active = false
        worker.shutdownNow()
    }

    private fun parseConfig(root: JSONObject): Pair<JSONObject, StoryItem>? {
        val config = root.optJSONObject("free_story_ads") ?: return null
        if (!config.optBoolean("enabled", false)) return null
        val rows = config.optJSONArray("items") ?: return null
        val items = mutableListOf<StoryItem>()
        for (i in 0 until rows.length()) {
            val row = rows.optJSONObject(i) ?: continue
            val id = row.optString("id").trim()
            val type = row.optString("media_type", "image").trim().lowercase()
            val media = resolveUrl(row.optString("media_url"))
            if (id.isBlank() || media.isBlank() || type !in setOf("image", "video")) continue
            items += StoryItem(
                id = id,
                title = row.optString("title").trim(),
                subtitle = row.optString("subtitle").trim(),
                mediaType = type,
                mediaUrl = media,
                targetAction = row.optString("target_action").trim().lowercase(),
                targetPlanId = row.optInt("target_plan_id", 0).coerceAtLeast(0),
                targetUrl = row.optString("target_url").trim(),
                buttonText = row.optString("button_text").trim(),
                weight = row.optInt("weight", 1).coerceIn(1, 100),
                imageDurationSeconds = row.optInt(
                    "image_duration_seconds",
                    config.optInt("image_duration_seconds", 6),
                ).coerceIn(3, 30),
            )
        }
        if (items.isEmpty()) return null
        return config to weightedRandom(items)
    }

    private fun weightedRandom(items: List<StoryItem>): StoryItem {
        val total = items.sumOf { it.weight }.coerceAtLeast(1)
        var pick = Random.nextInt(total)
        for (item in items) {
            pick -= item.weight
            if (pick < 0) return item
        }
        return items.last()
    }

    private fun resolveUrl(raw: String): String {
        val value = raw.trim()
        if (value.startsWith("/")) {
            return BlueVpnAccountManager.apiBaseUrl().trimEnd('/') + value
        }
        return if (value.startsWith("https://") || value.startsWith("http://")) value else ""
    }

    private fun downloadImage(url: String) = runCatching {
        val connection = URL(url).openConnection() as HttpURLConnection
        try {
            connection.instanceFollowRedirects = true
            connection.connectTimeout = loadTimeoutMs.toInt().coerceAtLeast(3_000)
            connection.readTimeout = loadTimeoutMs.toInt().coerceAtLeast(3_000)
            connection.useCaches = true
            connection.setRequestProperty("Accept", "image/webp,image/jpeg,image/png,image/*")
            connection.setRequestProperty("Accept-Encoding", "identity")
            connection.setRequestProperty("User-Agent", "BlueVPN/${BuildConfig.VERSION_NAME}")
            val code = connection.responseCode
            if (code !in 200..299) error("HTTP $code")
            val output = ByteArrayOutputStream()
            connection.inputStream.use { input ->
                val buffer = ByteArray(16 * 1024)
                var total = 0
                while (true) {
                    val read = input.read(buffer)
                    if (read <= 0) break
                    total += read
                    if (total > 8 * 1024 * 1024) error("story image too large")
                    output.write(buffer, 0, read)
                }
            }
            val bytes = output.toByteArray()
            BitmapFactory.decodeByteArray(bytes, 0, bytes.size) ?: error("invalid image")
        } finally {
            connection.disconnect()
        }
    }.getOrNull()

    private fun showImageStory(item: StoryItem, bitmap: android.graphics.Bitmap) {
        val image = ImageView(activity).apply {
            scaleType = ImageView.ScaleType.CENTER_CROP
            setImageBitmap(bitmap)
            setBackgroundColor(Color.BLACK)
        }
        val root = storyRoot(item, image)
        showDialog(root)
        mediaStarted = true
        val durationMs = item.imageDurationSeconds * 1000L
        val progress = root.findViewWithTag<ProgressBar>("story-progress")
        imageTimer = object : CountDownTimer(durationMs, 50L) {
            override fun onTick(remaining: Long) {
                progress?.progress = (((durationMs - remaining).toDouble() / durationMs) * 1000).toInt().coerceIn(0, 1000)
            }

            override fun onFinish() {
                progress?.progress = 1000
                finish(Outcome.COMPLETED)
            }
        }.start()
    }

    private fun showVideoStory(item: StoryItem) {
        val video = VideoView(activity).apply {
            setBackgroundColor(Color.BLACK)
        }
        videoView = video
        val root = storyRoot(item, video)
        val progress = root.findViewWithTag<ProgressBar>("story-progress")
        showDialog(root)

        val timeout = Runnable {
            if (active && !mediaStarted) finish(Outcome.UNAVAILABLE)
        }
        loadTimeout = timeout
        main.postDelayed(timeout, loadTimeoutMs)

        video.setOnPreparedListener { player ->
            if (!active) return@setOnPreparedListener
            mediaStarted = true
            loadTimeout?.let { main.removeCallbacks(it) }
            player.isLooping = false
            player.setVolume(1f, 1f)
            video.start()
            val reported = player.duration.toLong().takeIf { it > 0L } ?: maxVideoMs
            val effectiveDuration = reported.coerceAtMost(maxVideoMs).coerceAtLeast(1_000L)
            val ticker = object : Runnable {
                override fun run() {
                    if (!active || videoView !== video) return
                    val pos = video.currentPosition.toLong().coerceAtLeast(0L)
                    progress?.progress = ((pos.toDouble() / effectiveDuration) * 1000).toInt().coerceIn(0, 1000)
                    if (pos >= effectiveDuration) {
                        finish(Outcome.COMPLETED)
                    } else {
                        main.postDelayed(this, 80L)
                    }
                }
            }
            videoProgress = ticker
            main.post(ticker)
        }
        video.setOnCompletionListener {
            progress?.progress = 1000
            finish(Outcome.COMPLETED)
        }
        video.setOnErrorListener { _, _, _ ->
            finish(Outcome.UNAVAILABLE)
            true
        }
        runCatching { video.setVideoPath(item.mediaUrl) }
            .onFailure { finish(Outcome.UNAVAILABLE) }
    }

    private fun storyRoot(item: StoryItem, media: View): FrameLayout {
        val root = FrameLayout(activity).apply {
            setBackgroundColor(Color.BLACK)
            layoutDirection = View.LAYOUT_DIRECTION_RTL
        }
        root.addView(
            media,
            FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT,
            ),
        )

        val destination = BlueVpnAdActionRouter.destination(
            item.targetAction,
            item.targetPlanId,
            item.targetUrl,
        )
        if (destination.isActionable()) {
            media.isClickable = true
            media.setOnClickListener { openTarget(item) }
        }

        val shade = View(activity).apply {
            background = GradientDrawable(
                GradientDrawable.Orientation.TOP_BOTTOM,
                intArrayOf(Color.argb(135, 0, 0, 0), Color.TRANSPARENT, Color.argb(205, 0, 0, 0)),
            )
        }
        root.addView(shade, FrameLayout.LayoutParams(-1, -1))

        val progress = ProgressBar(activity, null, android.R.attr.progressBarStyleHorizontal).apply {
            tag = "story-progress"
            max = 1000
            progress = 0
            isIndeterminate = false
        }
        root.addView(
            progress,
            FrameLayout.LayoutParams(-1, dp(4)).apply {
                gravity = Gravity.TOP
                marginStart = dp(16)
                marginEnd = dp(16)
                topMargin = dp(30)
            },
        )

        val badge = text("تبلیغ • اتصال رایگان", 13f, Typeface.BOLD, Color.WHITE).apply {
            gravity = Gravity.CENTER
            setPadding(dp(14), 0, dp(14), 0)
            background = rounded(Color.argb(135, 0, 0, 0), 18f)
        }
        root.addView(
            badge,
            FrameLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, dp(38)).apply {
                gravity = Gravity.TOP or Gravity.END
                topMargin = dp(48)
                marginEnd = dp(18)
            },
        )

        val bottom = LinearLayout(activity).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.END
            setPadding(dp(24), dp(12), dp(24), dp(36))
        }
        if (item.title.isNotBlank()) bottom.addView(text(item.title, 25f, Typeface.BOLD, Color.WHITE))
        if (item.subtitle.isNotBlank()) {
            bottom.addView(text(item.subtitle, 15f, Typeface.NORMAL, Color.argb(230, 255, 255, 255)).apply {
                setPadding(0, dp(8), 0, 0)
            })
        }
        if (destination.isActionable()) {
            val cta = text(
                item.buttonText.ifBlank { BlueVpnAdActionRouter.defaultButtonText(destination.action) },
                15f,
                Typeface.BOLD,
                Color.WHITE,
            ).apply {
                gravity = Gravity.CENTER
                setPadding(dp(18), 0, dp(18), 0)
                background = rounded(Color.rgb(37, 99, 235), 18f)
                isClickable = true
                isFocusable = true
                contentDescription = item.buttonText.ifBlank {
                    BlueVpnAdActionRouter.defaultButtonText(destination.action)
                }
                setOnClickListener { openTarget(item) }
            }
            bottom.addView(
                cta,
                LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(50)).apply {
                    topMargin = dp(14)
                },
            )
        }

        bottom.addView(text(
            if (required) "اتصال بعد از پایان این تبلیغ فعال می‌شود" else "در حال آماده‌سازی اتصال رایگان",
            12f,
            Typeface.NORMAL,
            Color.argb(205, 255, 255, 255),
        ).apply { setPadding(0, dp(12), 0, 0) })

        root.addView(
            bottom,
            FrameLayout.LayoutParams(-1, ViewGroup.LayoutParams.WRAP_CONTENT).apply {
                gravity = Gravity.BOTTOM
            },
        )
        return root
    }

    private fun openTarget(item: StoryItem) {
        if (!active) return
        val destination = BlueVpnAdActionRouter.destination(
            item.targetAction,
            item.targetPlanId,
            item.targetUrl,
        )
        if (!destination.isActionable()) return

        // A CTA intentionally leaves the mandatory Free gate. Do not count it
        // as a completed impression: the host stops the pending Free VPN first,
        // then navigation continues to auth/plans/account without an ad bypass.
        finish(Outcome.ACTION_OPENED)
        main.post {
            BlueVpnAdActionRouter.open(
                context = activity,
                action = destination.action,
                planId = destination.planId,
                fallbackUrl = destination.fallbackUrl,
                source = "story:${item.id}",
            )
        }
    }

    private fun showDialog(root: View) {
        val next = Dialog(activity).apply {
            requestWindowFeature(Window.FEATURE_NO_TITLE)
            setContentView(root)
            setCancelable(!required)
            setCanceledOnTouchOutside(false)
            setOnCancelListener {
                if (!required) finish(Outcome.COMPLETED)
            }
        }
        dialog = next
        next.show()
        next.window?.apply {
            setBackgroundDrawable(ColorDrawable(Color.BLACK))
            setLayout(WindowManager.LayoutParams.MATCH_PARENT, WindowManager.LayoutParams.MATCH_PARENT)
            addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        }
    }

    private fun finish(outcome: Outcome) {
        if (!active) return
        active = false
        cleanupUi()
        val cb = callback
        callback = null
        cb?.invoke(outcome)
    }

    private fun cleanupUi() {
        imageTimer?.cancel()
        imageTimer = null
        videoProgress?.let { main.removeCallbacks(it) }
        videoProgress = null
        loadTimeout?.let { main.removeCallbacks(it) }
        loadTimeout = null
        runCatching { videoView?.stopPlayback() }
        videoView = null
        runCatching { dialog?.dismiss() }
        dialog = null
        mediaStarted = false
    }

    private fun text(value: String, size: Float, style: Int, color: Int) = TextView(activity).apply {
        text = value
        textSize = size
        typeface = Typeface.create(Typeface.DEFAULT, style)
        setTextColor(color)
        gravity = Gravity.END
        layoutDirection = View.LAYOUT_DIRECTION_RTL
    }

    private fun rounded(color: Int, radiusDp: Float) = GradientDrawable().apply {
        shape = GradientDrawable.RECTANGLE
        cornerRadius = dp(radiusDp.toInt()).toFloat()
        setColor(color)
    }

    private fun dp(value: Int): Int = (value * activity.resources.displayMetrics.density).toInt().coerceAtLeast(1)
}
