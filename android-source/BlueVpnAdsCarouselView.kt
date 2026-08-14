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
import android.view.View.MeasureSpec
import android.view.ViewGroup
import android.widget.FrameLayout
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.TextView
import com.v2ray.ang.BuildConfig
import org.json.JSONObject
import java.io.ByteArrayOutputStream
import java.io.File
import java.security.MessageDigest
import java.net.HttpURLConnection
import java.net.URL
import java.util.concurrent.Executors
import kotlin.math.abs
import kotlin.math.roundToInt

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
    private val worker = Executors.newFixedThreadPool(if (BlueVpnPerformance.isLowEnd(context)) 2 else 3)
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
    private var fetchInFlight = false
    private var desiredHeightPx = 0
    private var hasRenderedContent = false
    private var lastFetchAt = 0L
    private var touchDownX = 0f
    private var touchDownY = 0f
    private var touchMoved = false

    private val cachePrefs = context.applicationContext.getSharedPreferences(
        "bluevpn_ads_carousel_cache",
        Context.MODE_PRIVATE,
    )
    private val diskCacheDir = File(context.applicationContext.cacheDir, "bluevpn_ads").apply {
        runCatching { mkdirs() }
    }

    private val bitmapCache = object : LruCache<String, Bitmap>(
        BlueVpnPerformance.adCacheKb(context)
    ) {
        override fun sizeOf(key: String, value: Bitmap): Int =
            (value.byteCount / 1024).coerceAtLeast(1)
    }

    private val refreshRunnable = Runnable {
        if (running) fetchConfig()
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
        clipToOutline = true
        desiredHeightPx = dp(146)
        minimumHeight = 0
        buildUi()
        isClickable = true
        isFocusable = true
        contentDescription = "تبلیغات BlueVPN"
        setOnTouchListener { _, event -> handleTouch(event) }
    }

    fun start() {
        running = true

        // Stale-while-revalidate for campaign UI: render the most recent valid
        // config from disk immediately, then refresh it in the background.
        // This removes the previous blank period on every cold app launch.
        if (items.isEmpty()) hydrateCachedConfig()

        if (System.currentTimeMillis() - lastFetchAt > 60_000L || items.isEmpty()) {
            fetchConfig()
        } else {
            scheduleNext()
            scheduleRefresh()
        }
    }

    fun stop() {
        running = false
        handler.removeCallbacks(slideRunnable)
        handler.removeCallbacks(refreshRunnable)
    }

    fun release() {
        stop()
        worker.shutdownNow()
        bitmapCache.evictAll()
    }

    fun trimMemory() {
        bitmapCache.evictAll()
        if (BlueVpnPerformance.isLowEnd(context) && !hasRenderedContent) {
            visibility = View.GONE
        }
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

    private fun hydrateCachedConfig() {
        val raw = cachePrefs.getString("mobile_config", "").orEmpty()
        if (raw.isBlank()) return
        runCatching { JSONObject(raw) }
            .onSuccess { applyConfig(it, persist = false) }
            .onFailure { cachePrefs.edit().remove("mobile_config").apply() }
    }

    private fun fetchConfig() {
        if (fetchInFlight) {
            scheduleRefresh()
            return
        }
        fetchInFlight = true
        worker.execute {
            val result = BlueVpnAccountManager.mobileConfig(context, force = false)
            handler.post {
                fetchInFlight = false
                if (!running) return@post
                result.onSuccess { applyConfig(it, persist = true) }.onFailure {
                    // Keep the last-known-good campaign visible when the API is
                    // temporarily slow or unavailable.
                    if (items.isEmpty()) hideBanner()
                }
                scheduleRefresh()
            }
        }
    }

    private fun applyConfig(root: JSONObject, persist: Boolean) {
        if (persist) {
            cachePrefs.edit().putString("mobile_config", root.toString()).apply()
            lastFetchAt = System.currentTimeMillis()
        }
        val config = root.optJSONObject("advertising")
        if (config == null || !config.optBoolean("enabled", false)) {
            items = emptyList()
            hideBanner()
            handler.removeCallbacks(slideRunnable)
            return
        }
        autoplay = config.optBoolean("autoplay", true)
        loop = config.optBoolean("loop", true)
        intervalMs = config.optLong("interval_ms", 6_000L).coerceIn(3_000L, 30_000L)
        desiredHeightPx = dp(config.optInt("height_dp", 146).coerceIn(116, 160))
        val parsed = mutableListOf<AdItem>()
        val array = config.optJSONArray("items")
        if (array != null) {
            for (index in 0 until array.length()) {
                val row = array.optJSONObject(index) ?: continue
                val id = row.optString("id").trim()
                val title = row.optString("title").trim()
                val imageUrl = imageAssetUrl(row.optString("image_path"))
                    .ifBlank { imageAssetUrl(row.optString("image_url")) }
                if (id.isBlank() || imageUrl.isBlank()) continue
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
            hideBanner()
            return
        }
        currentIndex = currentIndex.coerceIn(0, items.lastIndex)
        showItem(currentIndex, animate = false)
        prefetchUpcomingImages()
        scheduleNext()
    }

    private fun prefetchUpcomingImages() {
        if (items.size < 2) return
        val indexes = (1..minOf(2, items.lastIndex)).map { (currentIndex + it) % items.size }
        indexes.distinct().forEach { index ->
            val url = items.getOrNull(index)?.imageUrl.orEmpty()
            if (url.isBlank() || bitmapCache.get(url) != null) return@forEach
            val fileReady = runCatching { cacheFile(url).isFile }.getOrDefault(false)
            if (fileReady) return@forEach
            worker.execute {
                val downloaded = downloadBitmap(url) ?: return@execute
                bitmapCache.put(url, downloaded.bitmap)
                writeDiskBytes(url, downloaded.bytes)
            }
        }
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
            dropBrokenCurrentItem(item.id)
            return
        }
        if (!hasRenderedContent) showPlaceholder()
        loadImage(item.imageUrl)
        if (animate) {
            alpha = 0.72f
            scaleX = 0.985f
            animate().alpha(1f).scaleX(1f).setDuration(220L).start()
        }
    }

    private fun loadImage(url: String) {
        val cached = bitmapCache.get(url)
        if (cached != null) {
            revealBitmap(cached)
            return
        }

        // Disk cache survives Activity/process recreation. A cached campaign
        // therefore paints on the first frame instead of downloading again.
        val diskBitmap = readDiskBitmap(url)
        if (diskBitmap != null) {
            bitmapCache.put(url, diskBitmap)
            revealBitmap(diskBitmap)
            return
        }

        showPlaceholder()
        val expectedId = items.getOrNull(currentIndex)?.id ?: return
        worker.execute {
            val downloaded = downloadBitmap(url)
            if (downloaded != null) {
                bitmapCache.put(url, downloaded.bitmap)
                writeDiskBytes(url, downloaded.bytes)
            }
            handler.post {
                val current = items.getOrNull(currentIndex)
                if (current?.id == expectedId && current.imageUrl == url) {
                    if (downloaded != null) {
                        revealBitmap(downloaded.bitmap)
                    } else {
                        dropBrokenCurrentItem(expectedId)
                    }
                }
            }
        }
    }

    private data class DownloadedBitmap(val bitmap: Bitmap, val bytes: ByteArray)

    private fun downloadBitmap(url: String): DownloadedBitmap? = runCatching {
        val connection = URL(url).openConnection() as HttpURLConnection
        try {
            connection.connectTimeout = 4_500
            connection.readTimeout = 7_000
            connection.instanceFollowRedirects = true
            connection.useCaches = true
            connection.setRequestProperty("Accept", "image/avif,image/webp,image/*,*/*;q=0.8")
            connection.setRequestProperty("User-Agent", "BlueVPN/${BuildConfig.VERSION_NAME}")
            if (connection.responseCode !in 200..299) error("HTTP ${connection.responseCode}")
            val contentType = connection.contentType.orEmpty().lowercase()
            if (!contentType.startsWith("image/") && contentType.isNotBlank()) error("Invalid image")
            val bytes = readAdBytes(connection) ?: error("Invalid image bytes")
            val bitmap = decodeAdBytes(bytes) ?: error("Invalid bitmap")
            DownloadedBitmap(bitmap, bytes)
        } finally {
            connection.disconnect()
        }
    }.getOrNull()

    private fun showPlaceholder() {
        if (!hasRenderedContent) {
            hasRenderedContent = true
            visibility = View.VISIBLE
        }
        imageView.setImageDrawable(null)
        imageView.background = GradientDrawable(
            GradientDrawable.Orientation.TL_BR,
            intArrayOf(Color.parseColor("#1D2A4D"), Color.parseColor("#090D18")),
        )
        val targetHeight = calculateBannerHeight()
        layoutParams?.let { params ->
            if (params.height != targetHeight) {
                params.height = targetHeight
                layoutParams = params
            }
        }
        requestLayout()
    }

    private fun cacheFile(url: String): File {
        val digest = MessageDigest.getInstance("SHA-256")
            .digest(url.toByteArray(Charsets.UTF_8))
            .joinToString("") { "%02x".format(it) }
        return File(diskCacheDir, "$digest.img")
    }

    private fun readDiskBitmap(url: String): Bitmap? {
        val file = runCatching { cacheFile(url) }.getOrNull() ?: return null
        if (!file.isFile) return null
        val maxAgeMs = 7L * 24L * 60L * 60L * 1000L
        if (System.currentTimeMillis() - file.lastModified() > maxAgeMs) {
            runCatching { file.delete() }
            return null
        }
        val bytes = runCatching { file.readBytes() }.getOrNull() ?: return null
        return decodeAdBytes(bytes) ?: run {
            runCatching { file.delete() }
            null
        }
    }

    private fun writeDiskBytes(url: String, bytes: ByteArray) {
        if (bytes.isEmpty()) return
        runCatching {
            val target = cacheFile(url)
            val temp = File(target.parentFile, target.name + ".tmp")
            temp.writeBytes(bytes)
            if (!temp.renameTo(target)) {
                target.writeBytes(bytes)
                temp.delete()
            }
            trimDiskCacheFiles()
        }
    }

    private fun trimDiskCacheFiles() {
        val keep = if (BlueVpnPerformance.isLowEnd(context)) 6 else 12
        diskCacheDir.listFiles()
            .orEmpty()
            .filter { it.isFile && !it.name.endsWith(".tmp") }
            .sortedByDescending { it.lastModified() }
            .drop(keep)
            .forEach { runCatching { it.delete() } }
    }

    private fun readAdBytes(connection: HttpURLConnection): ByteArray? {
        val lowEnd = BlueVpnPerformance.isLowEnd(context)
        val maxBytes = if (lowEnd) 3 * 1024 * 1024 else 6 * 1024 * 1024
        val declared = connection.contentLength
        if (declared > maxBytes) return null

        val output = ByteArrayOutputStream(
            declared.takeIf { it in 1..maxBytes } ?: 64 * 1024
        )
        connection.inputStream.use { input ->
            val buffer = ByteArray(16 * 1024)
            var total = 0
            while (true) {
                val read = input.read(buffer)
                if (read <= 0) break
                total += read
                if (total > maxBytes) return null
                output.write(buffer, 0, read)
            }
        }
        return output.toByteArray().takeIf { it.isNotEmpty() }
    }

    private fun decodeAdBytes(bytes: ByteArray): Bitmap? {
        if (bytes.isEmpty()) return null
        val lowEnd = BlueVpnPerformance.isLowEnd(context)
        val maxBytes = if (lowEnd) 3 * 1024 * 1024 else 6 * 1024 * 1024
        if (bytes.size > maxBytes) return null

        val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        BitmapFactory.decodeByteArray(bytes, 0, bytes.size, bounds)
        if (bounds.outWidth <= 0 || bounds.outHeight <= 0) return null

        val targetWidth = resources.displayMetrics.widthPixels
            .coerceAtLeast(320)
            .coerceAtMost(if (lowEnd) 720 else 1280)
        var sample = 1
        while (bounds.outWidth / (sample * 2) >= targetWidth) sample *= 2

        val options = BitmapFactory.Options().apply {
            inSampleSize = sample.coerceAtLeast(1)
            inPreferredConfig = if (lowEnd) Bitmap.Config.RGB_565 else Bitmap.Config.ARGB_8888
        }
        return BitmapFactory.decodeByteArray(bytes, 0, bytes.size, options)
    }


    override fun onMeasure(widthMeasureSpec: Int, heightMeasureSpec: Int) {
        if (!hasRenderedContent) {
            super.onMeasure(widthMeasureSpec, MeasureSpec.makeMeasureSpec(0, MeasureSpec.EXACTLY))
            return
        }
        val finalHeight = calculateBannerHeight(MeasureSpec.getSize(widthMeasureSpec))
        super.onMeasure(
            widthMeasureSpec,
            MeasureSpec.makeMeasureSpec(finalHeight, MeasureSpec.EXACTLY),
        )
    }

    private fun calculateBannerHeight(widthHint: Int = 0): Int {
        val fallbackWidth = resources.displayMetrics.widthPixels - dp(40)
        val availableWidth = widthHint.takeIf { it > 0 }
            ?: measuredWidth.takeIf { it > 0 }
            ?: (parent as? View)?.width?.takeIf { it > 0 }
            ?: fallbackWidth
        val ratioHeight = (availableWidth.coerceAtLeast(dp(240)) / 2.222f).roundToInt()
        return minOf(
            desiredHeightPx.coerceIn(dp(116), dp(160)),
            ratioHeight.coerceIn(dp(116), dp(160)),
        )
    }

    private fun revealBitmap(bitmap: Bitmap) {
        imageView.background = null
        imageView.setImageBitmap(bitmap)
        hasRenderedContent = true
        val targetHeight = calculateBannerHeight()
        layoutParams?.let { params ->
            if (params.height != targetHeight) {
                params.height = targetHeight
                layoutParams = params
            }
        }
        visibility = View.VISIBLE
        requestLayout()
    }

    private fun hideBanner() {
        hasRenderedContent = false
        visibility = View.GONE
        minimumHeight = 0
        layoutParams?.let { params ->
            if (params.height != 0) {
                params.height = 0
                layoutParams = params
            }
        }
        requestLayout()
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


    private fun dropBrokenCurrentItem(expectedId: String) {
        val remaining = items.filterNot { it.id == expectedId }
        items = remaining
        if (remaining.isEmpty()) {
            hideBanner()
            handler.removeCallbacks(slideRunnable)
            return
        }
        currentIndex = currentIndex.coerceIn(0, remaining.lastIndex)
        showItem(currentIndex, animate = false)
        scheduleNext()
    }

    private fun imageAssetUrl(value: String): String {
        val trimmed = value.trim()
        if (trimmed.isBlank()) return ""
        if (trimmed.startsWith('/')) {
            val supportedPath = trimmed.startsWith("/media/ads/") ||
                trimmed.startsWith("/api/v1/ad-assets/")
            if (!supportedPath) return ""
            val base = runCatching { Uri.parse(BlueVpnAccountManager.apiBaseUrl().trim()) }.getOrNull() ?: return ""
            val scheme = base.scheme?.lowercase().orEmpty()
            val authority = base.encodedAuthority.orEmpty()
            return if ((scheme == "http" || scheme == "https") && authority.isNotBlank()) "$scheme://$authority$trimmed" else ""
        }
        return safeUrl(trimmed)
    }

    private fun scheduleRefresh() {
        handler.removeCallbacks(refreshRunnable)
        if (running) handler.postDelayed(refreshRunnable, 60_000L)
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
