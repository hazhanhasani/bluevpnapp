package com.v2ray.ang.bluevpn

import android.app.Activity
import android.app.Dialog
import android.graphics.BitmapFactory
import android.graphics.Color
import android.graphics.Typeface
import android.graphics.SurfaceTexture
import android.media.MediaPlayer
import android.graphics.drawable.ColorDrawable
import android.graphics.drawable.GradientDrawable
import android.os.CountDownTimer
import android.os.Handler
import android.os.Looper
import android.view.Gravity
import android.view.Surface
import android.view.TextureView
import android.view.View
import android.view.ViewGroup
import android.view.Window
import android.view.WindowManager
import android.widget.FrameLayout
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.ProgressBar
import android.widget.TextView
import com.v2ray.ang.BuildConfig
import org.json.JSONObject
import java.io.ByteArrayOutputStream
import java.io.File
import java.io.FileOutputStream
import java.net.HttpURLConnection
import java.net.URL
import java.util.concurrent.Executors
import kotlin.math.max
import kotlin.random.Random

/**
 * First-party post-connect story ad for the Free plan.
 *
 * The VPN session is already verified and committed before this UI opens.
 * Advertising is strictly presentation-only: media/config/lifecycle failures,
 * backgrounding, CTA navigation, or player errors must never stop/restart VPN,
 * clear the connected timestamp, or alter connection verification.
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
    private var mediaPlayer: MediaPlayer? = null
    private var videoTexture: TextureView? = null
    private var videoSurface: Surface? = null
    private var tempVideoFile: File? = null
    private var firstFrameTimeout: Runnable? = null
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
                val localVideo = downloadVideo(item.mediaUrl)
                main.post {
                    if (!active) {
                        localVideo?.delete()
                        return@post
                    }
                    if (localVideo == null) finish(Outcome.UNAVAILABLE)
                    else showVideoStory(item, localVideo)
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

    private fun downloadVideo(url: String): File? = runCatching {
        val connection = URL(url).openConnection() as HttpURLConnection
        try {
            connection.instanceFollowRedirects = true
            connection.connectTimeout = loadTimeoutMs.toInt().coerceAtLeast(3_000)
            connection.readTimeout = max(loadTimeoutMs.toInt(), 12_000)
            connection.useCaches = true
            connection.setRequestProperty("Accept", "video/mp4,video/webm,video/*")
            connection.setRequestProperty("Accept-Encoding", "identity")
            connection.setRequestProperty("User-Agent", "BlueVPN/${BuildConfig.VERSION_NAME}")
            val code = connection.responseCode
            if (code !in 200..299) error("HTTP $code")
            val advertised = connection.contentLengthLong
            val maxBytes = 24L * 1024L * 1024L
            if (advertised > maxBytes) error("story video too large")
            val mime = connection.contentType.orEmpty().substringBefore(';').trim().lowercase()
            val suffix = if (mime == "video/webm" || url.substringBefore('?').endsWith(".webm", true)) ".webm" else ".mp4"
            val file = File.createTempFile("bluevpn-story-", suffix, activity.cacheDir)
            try {
                FileOutputStream(file).use { output ->
                    connection.inputStream.use { input ->
                        val buffer = ByteArray(32 * 1024)
                        var total = 0L
                        while (true) {
                            val read = input.read(buffer)
                            if (read <= 0) break
                            total += read
                            if (total > maxBytes) error("story video too large")
                            output.write(buffer, 0, read)
                        }
                        output.fd.sync()
                    }
                }
                if (file.length() <= 0L) error("empty story video")
                file
            } catch (t: Throwable) {
                file.delete()
                throw t
            }
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

    private fun showVideoStory(item: StoryItem, file: File) {
        tempVideoFile = file
        val texture = TextureView(activity).apply {
            setBackgroundColor(Color.BLACK)
            isOpaque = true
        }
        videoTexture = texture
        val root = storyRoot(item, texture)
        val progress = root.findViewWithTag<ProgressBar>("story-progress")
        showDialog(root)

        val timeout = Runnable {
            if (active && !mediaStarted) finish(Outcome.UNAVAILABLE)
        }
        loadTimeout = timeout
        main.postDelayed(timeout, loadTimeoutMs)

        fun attach(surfaceTexture: SurfaceTexture) {
            if (!active || videoTexture !== texture) return
            val surface = Surface(surfaceTexture)
            videoSurface?.release()
            videoSurface = surface
            val player = MediaPlayer()
            mediaPlayer = player
            runCatching {
                player.setSurface(surface)
                player.setDataSource(file.absolutePath)
                player.setOnInfoListener { _, what, _ ->
                    if (what == MediaPlayer.MEDIA_INFO_VIDEO_RENDERING_START) {
                        mediaStarted = true
                        loadTimeout?.let { main.removeCallbacks(it) }
                        loadTimeout = null
                        firstFrameTimeout?.let { main.removeCallbacks(it) }
                        firstFrameTimeout = null
                    }
                    false
                }
                player.setOnPreparedListener { prepared ->
                    if (!active || mediaPlayer !== prepared) return@setOnPreparedListener
                    prepared.isLooping = false
                    prepared.setVolume(1f, 1f)
                    prepared.start()
                    val frameTimeout = Runnable {
                        if (active && !mediaStarted && mediaPlayer === prepared) {
                            finish(Outcome.UNAVAILABLE)
                        }
                    }
                    firstFrameTimeout = frameTimeout
                    main.postDelayed(frameTimeout, 4_000L.coerceAtMost(loadTimeoutMs))
                    val reported = prepared.duration.toLong().takeIf { it > 0L } ?: maxVideoMs
                    val effectiveDuration = reported.coerceAtMost(maxVideoMs).coerceAtLeast(1_000L)
                    val ticker = object : Runnable {
                        override fun run() {
                            if (!active || mediaPlayer !== prepared) return
                            val pos = runCatching { prepared.currentPosition.toLong() }.getOrDefault(0L).coerceAtLeast(0L)
                            if (mediaStarted) {
                                progress?.progress = ((pos.toDouble() / effectiveDuration) * 1000).toInt().coerceIn(0, 1000)
                                if (pos >= effectiveDuration) {
                                    finish(Outcome.COMPLETED)
                                    return
                                }
                            }
                            main.postDelayed(this, 80L)
                        }
                    }
                    videoProgress = ticker
                    main.post(ticker)
                }
                player.setOnCompletionListener {
                    if (!mediaStarted) {
                        finish(Outcome.UNAVAILABLE)
                    } else {
                        progress?.progress = 1000
                        finish(Outcome.COMPLETED)
                    }
                }
                player.setOnErrorListener { _, _, _ ->
                    finish(Outcome.UNAVAILABLE)
                    true
                }
                player.prepareAsync()
            }.onFailure { finish(Outcome.UNAVAILABLE) }
        }

        texture.surfaceTextureListener = object : TextureView.SurfaceTextureListener {
            override fun onSurfaceTextureAvailable(surface: SurfaceTexture, width: Int, height: Int) = attach(surface)
            override fun onSurfaceTextureSizeChanged(surface: SurfaceTexture, width: Int, height: Int) = Unit
            override fun onSurfaceTextureUpdated(surface: SurfaceTexture) = Unit
            override fun onSurfaceTextureDestroyed(surface: SurfaceTexture): Boolean {
                if (active && videoTexture === texture) finish(Outcome.UNAVAILABLE)
                return true
            }
        }
        texture.surfaceTexture?.takeIf { texture.isAvailable }?.let(::attach)
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
            "اتصال امن برقرار است",
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

        // CTA navigation is presentation-only and must never own VPN lifecycle.
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
        firstFrameTimeout?.let { main.removeCallbacks(it) }
        firstFrameTimeout = null
        runCatching { mediaPlayer?.stop() }
        runCatching { mediaPlayer?.reset() }
        runCatching { mediaPlayer?.release() }
        mediaPlayer = null
        runCatching { videoSurface?.release() }
        videoSurface = null
        videoTexture = null
        tempVideoFile?.let { runCatching { it.delete() } }
        tempVideoFile = null
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
