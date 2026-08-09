package com.v2ray.ang.bluevpn

import android.content.Context
import android.content.Intent
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Color
import android.graphics.Typeface
import android.graphics.drawable.GradientDrawable
import android.net.Uri
import android.os.Handler
import android.os.Looper
import android.util.LruCache
import android.view.Gravity
import android.view.MotionEvent
import android.view.View
import android.view.ViewGroup
import android.widget.FrameLayout
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.TextView
import com.v2ray.ang.BuildConfig
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.util.concurrent.Executors
import kotlin.math.abs

class BlueVpnAdsCarouselView(context: Context) : FrameLayout(context) {
    private data class AdItem(
        val id: String,
        val title: String,
        val subtitle: String,
        val imageUrl: String,
        val targetUrl: String,
        val buttonText: String,
    )

    private val handler = Handler(Looper.getMainLooper())
    private val worker = Executors.newSingleThreadExecutor()
    private val palette = BlueVpnTheme.palette(context)
    private val imageView = ImageView(context)
    private val titleView = TextView(context)
    private val subtitleView = TextView(context)
    private val actionView = TextView(context)
    private val dots = LinearLayout(context)
    private val badgeView = TextView(context)
    private var items: List<AdItem> = emptyList()
    private var currentIndex = 0
    private var intervalMs = 6_000L
    private var autoplay = true
    private var loop = true
    private var running = false
    private var lastFetchAt = 0L
    private var touchDownX = 0f
    private var touchDownY = 0f
    private var touchMoved = false

    private val bitmapCache = object : LruCache<String, Bitmap>(12 * 1024) {
        override fun sizeOf(key: String, value: Bitmap): Int =
            (value.byteCount / 1024).coerceAtLeast(1)
    }

    private val slideRunnable = Runnable {
        if (!running || !autoplay || items.size < 2) return@Runnable
        val next = currentIndex + 1
        if (next >= items.size && !loop) return@Runnable
        showItem(if (next >= items.size) 0 else next, animate = true)
        scheduleNext()
    }

    init {
        visibility = View.GONE
        layoutDirection = View.LAYOUT_DIRECTION_RTL
        clipChildren = true
        clipToPadding = true
        background = rounded(
            if (palette.dark) Color.parseColor("#12131A") else Color.WHITE,
            22f,
            if (palette.dark) Color.parseColor("#30323C") else Color.parseColor("#D8DEEA"),
        )
        elevation = dp(3).toFloat()
        buildUi()
        isClickable = true
        isFocusable = true
        contentDescription = "تبلیغات BlueVPN"
        setOnTouchListener { _, event -> handleTouch(event) }
    }

    fun start() {
        running = true
        if (System.currentTimeMillis() - lastFetchAt > 5 * 60 * 1000L || items.isEmpty()) {
            fetchConfig()
        } else {
            scheduleNext()
        }
    }

    fun stop() {
        running = false
        handler.removeCallbacks(slideRunnable)
    }

    fun release() {
        stop()
        worker.shutdownNow()
        bitmapCache.evictAll()
    }

    private fun buildUi() {
        imageView.apply {
            scaleType = ImageView.ScaleType.CENTER_CROP
            setBackgroundColor(if (palette.dark) Color.parseColor("#10131D") else Color.parseColor("#E9EEF8"))
        }
        addView(imageView, LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT))

        addView(View(context).apply {
            background = GradientDrawable(
                GradientDrawable.Orientation.TOP_BOTTOM,
                intArrayOf(Color.TRANSPARENT, Color.argb(35, 0, 0, 0), Color.argb(225, 2, 4, 10)),
            )
        }, LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT))

        badgeView.apply {
            text = "تبلیغ"
            textSize = 9f
            setTextColor(Color.WHITE)
            gravity = Gravity.CENTER
            setPadding(dp(9), 0, dp(9), 0)
            background = rounded(Color.argb(150, 4, 8, 18), 999f, Color.argb(80, 255, 255, 255))
        }
        addView(badgeView, LayoutParams(dp(54), dp(25), Gravity.TOP or Gravity.END).apply {
            topMargin = dp(10); marginEnd = dp(10)
        })

        val copy = LinearLayout(context).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.BOTTOM or Gravity.START
            setPadding(dp(16), dp(12), dp(16), dp(12))
        }
        titleView.apply {
            textSize = 16f
            setTextColor(Color.WHITE)
            typeface = Typeface.create(Typeface.DEFAULT, Typeface.BOLD)
            maxLines = 1
        }
        subtitleView.apply {
            textSize = 10.5f
            setTextColor(Color.parseColor("#D7D9E1"))
            maxLines = 2
        }
        actionView.apply {
            textSize = 10f
            setTextColor(Color.WHITE)
            typeface = Typeface.create(Typeface.DEFAULT, Typeface.BOLD)
            gravity = Gravity.CENTER
            setPadding(dp(12), 0, dp(12), 0)
            background = rounded(palette.accent, 999f, Color.TRANSPARENT)
        }
        copy.addView(titleView, LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(25)))
        copy.addView(subtitleView, LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT).apply { topMargin = dp(2) })
        copy.addView(actionView, LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, dp(30)).apply { topMargin = dp(8) })
        addView(copy, LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT))

        dots.orientation = LinearLayout.HORIZONTAL
        dots.gravity = Gravity.CENTER
        addView(dots, LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, dp(20), Gravity.BOTTOM or Gravity.CENTER_HORIZONTAL).apply { bottomMargin = dp(4) })
    }

    private fun fetchConfig() {
        worker.execute {
            val result = runCatching {
                val connection = URL(
                    BlueVpnAccountManager.apiBaseUrl().trimEnd('/') + "/api/v1/mobile/config"
                ).openConnection() as HttpURLConnection
                try {
                    connection.connectTimeout = 8_000
                    connection.readTimeout = 10_000
                    connection.useCaches = false
                    connection.setRequestProperty("Accept", "application/json")
                    connection.setRequestProperty("User-Agent", "BlueVPN/${BuildConfig.VERSION_NAME}")
                    if (connection.responseCode !in 200..299) error("HTTP ${connection.responseCode}")
                    JSONObject(connection.inputStream.bufferedReader().use { it.readText() })
                } finally {
                    connection.disconnect()
                }
            }
            handler.post {
                if (!running) return@post
                result.onSuccess { applyConfig(it) }.onFailure {
                    if (items.isEmpty()) visibility = View.GONE
                }
            }
        }
    }

    private fun applyConfig(root: JSONObject) {
        lastFetchAt = System.currentTimeMillis()
        val config = root.optJSONObject("advertising")
        if (config == null || !config.optBoolean("enabled", false)) {
            items = emptyList()
            visibility = View.GONE
            handler.removeCallbacks(slideRunnable)
            return
        }
        autoplay = config.optBoolean("autoplay", true)
        loop = config.optBoolean("loop", true)
        intervalMs = config.optLong("interval_ms", 6_000L).coerceIn(3_000L, 30_000L)
        minimumHeight = dp(config.optInt("height_dp", 142).coerceIn(96, 240))
        val parsed = mutableListOf<AdItem>()
        val array = config.optJSONArray("items")
        if (array != null) {
            for (index in 0 until array.length()) {
                val row = array.optJSONObject(index) ?: continue
                val id = row.optString("id").trim()
                val title = row.optString("title").trim()
                val imageUrl = safeUrl(row.optString("image_url"))
                if (id.isBlank() || (title.isBlank() && imageUrl.isBlank())) continue
                parsed += AdItem(
                    id = id,
                    title = title,
                    subtitle = row.optString("subtitle").trim(),
                    imageUrl = imageUrl,
                    targetUrl = safeUrl(row.optString("target_url")),
                    buttonText = row.optString("button_text").trim(),
                )
            }
        }
        items = parsed
        if (items.isEmpty()) {
            visibility = View.GONE
            return
        }
        currentIndex = currentIndex.coerceIn(0, items.lastIndex)
        visibility = View.VISIBLE
        showItem(currentIndex, animate = false)
        scheduleNext()
    }

    private fun showItem(index: Int, animate: Boolean) {
        if (items.isEmpty()) return
        currentIndex = index.coerceIn(0, items.lastIndex)
        val item = items[currentIndex]
        titleView.text = item.title
        titleView.visibility = if (item.title.isBlank()) View.GONE else View.VISIBLE
        subtitleView.text = item.subtitle
        subtitleView.visibility = if (item.subtitle.isBlank()) View.GONE else View.VISIBLE
        actionView.text = item.buttonText.ifBlank { "مشاهده" }
        actionView.visibility = if (item.targetUrl.isBlank()) View.GONE else View.VISIBLE
        renderDots()
        if (item.imageUrl.isBlank()) {
            imageView.setImageDrawable(null)
            imageView.background = GradientDrawable(
                GradientDrawable.Orientation.TL_BR,
                intArrayOf(Color.parseColor("#244FC2"), Color.parseColor("#091126")),
            )
        } else {
            loadImage(item.imageUrl)
        }
        if (animate) {
            alpha = 0.72f
            scaleX = 0.985f
            animate().alpha(1f).scaleX(1f).setDuration(220L).start()
        }
    }

    private fun loadImage(url: String) {
        val cached = bitmapCache.get(url)
        if (cached != null) {
            imageView.background = null
            imageView.setImageBitmap(cached)
            return
        }
        imageView.setImageDrawable(null)
        imageView.background = GradientDrawable(
            GradientDrawable.Orientation.TL_BR,
            intArrayOf(Color.parseColor("#1D2A4D"), Color.parseColor("#090D18")),
        )
        val expectedId = items.getOrNull(currentIndex)?.id ?: return
        worker.execute {
            val bitmap = runCatching {
                val connection = URL(url).openConnection() as HttpURLConnection
                try {
                    connection.connectTimeout = 8_000
                    connection.readTimeout = 10_000
                    connection.instanceFollowRedirects = true
                    connection.setRequestProperty("User-Agent", "BlueVPN/${BuildConfig.VERSION_NAME}")
                    if (connection.responseCode !in 200..299) error("HTTP ${connection.responseCode}")
                    val contentType = connection.contentType.orEmpty().lowercase()
                    if (!contentType.startsWith("image/") && contentType.isNotBlank()) error("Invalid image")
                    connection.inputStream.use { BitmapFactory.decodeStream(it) ?: error("Invalid bitmap") }
                } finally {
                    connection.disconnect()
                }
            }.getOrNull()
            if (bitmap != null) bitmapCache.put(url, bitmap)
            handler.post {
                val current = items.getOrNull(currentIndex)
                if (bitmap != null && current?.id == expectedId && current.imageUrl == url) {
                    imageView.background = null
                    imageView.setImageBitmap(bitmap)
                }
            }
        }
    }

    private fun renderDots() {
        dots.removeAllViews()
        if (items.size < 2) {
            dots.visibility = View.GONE
            return
        }
        dots.visibility = View.VISIBLE
        items.forEachIndexed { index, _ ->
            dots.addView(View(context).apply {
                background = rounded(
                    if (index == currentIndex) Color.WHITE else Color.argb(105, 255, 255, 255),
                    999f,
                    Color.TRANSPARENT,
                )
            }, LinearLayout.LayoutParams(if (index == currentIndex) dp(16) else dp(6), dp(6)).apply { marginStart = dp(3); marginEnd = dp(3) })
        }
    }

    private fun scheduleNext() {
        handler.removeCallbacks(slideRunnable)
        if (running && autoplay && items.size > 1) handler.postDelayed(slideRunnable, intervalMs)
    }

    private fun handleTouch(event: MotionEvent): Boolean {
        when (event.actionMasked) {
            MotionEvent.ACTION_DOWN -> {
                touchDownX = event.rawX
                touchDownY = event.rawY
                touchMoved = false
                handler.removeCallbacks(slideRunnable)
                return true
            }
            MotionEvent.ACTION_MOVE -> {
                if (abs(event.rawX - touchDownX) > dp(12) || abs(event.rawY - touchDownY) > dp(12)) touchMoved = true
                return true
            }
            MotionEvent.ACTION_UP -> {
                val dx = event.rawX - touchDownX
                if (touchMoved && abs(dx) >= dp(44) && items.size > 1) {
                    val delta = if (dx > 0) -1 else 1
                    var next = currentIndex + delta
                    if (next < 0) next = if (loop) items.lastIndex else 0
                    if (next > items.lastIndex) next = if (loop) 0 else items.lastIndex
                    showItem(next, animate = true)
                } else {
                    performClick()
                }
                scheduleNext()
                return true
            }
            MotionEvent.ACTION_CANCEL -> {
                scheduleNext()
                return true
            }
        }
        return false
    }

    override fun performClick(): Boolean {
        super.performClick()
        val target = items.getOrNull(currentIndex)?.targetUrl.orEmpty()
        if (target.isNotBlank()) openTarget(target)
        return true
    }

    private fun openTarget(value: String) {
        val safe = safeUrl(value)
        if (safe.isBlank()) return
        runCatching {
            context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(safe)).apply {
                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            })
        }
    }

    private fun safeUrl(value: String): String {
        val uri = runCatching { Uri.parse(value.trim()) }.getOrNull() ?: return ""
        val scheme = uri.scheme?.lowercase()
        return if ((scheme == "http" || scheme == "https") && !uri.host.isNullOrBlank()) value.trim() else ""
    }

    private fun rounded(fill: Int, radiusDp: Float, stroke: Int): GradientDrawable =
        GradientDrawable().apply {
            shape = GradientDrawable.RECTANGLE
            cornerRadius = dpFloat(radiusDp)
            setColor(fill)
            if (Color.alpha(stroke) > 0) setStroke(dp(1), stroke)
        }

    private fun dp(value: Int): Int = (value * resources.displayMetrics.density).toInt()
    private fun dpFloat(value: Float): Float = value * resources.displayMetrics.density
}
