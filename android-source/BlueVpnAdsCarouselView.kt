package com.v2ray.ang.bluevpn

import android.app.Activity
import android.content.Context
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
import kotlin.math.roundToInt

class BlueVpnAdsCarouselView(context: Context) : FrameLayout(context) {
    private data class AdItem(
        val id: String,
        val title: String,
        val subtitle: String,
        val imageUrl: String,
        val targetAction: String,
        val targetPlanId: Int,
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
    private val badgeView = TextView(context)
    private val tapsellHost = FrameLayout(context)
    private var items: List<AdItem> = emptyList()
    private var tapsellStandardEnabled = false
    private var tapsellEverySlides = 3
    private var ownSlidesSinceTapsell = 0
    private var tapsellShowing = false
    private var tapsellLoading = false
    private var tapsellCleanup: (() -> Unit)? = null
    private var currentIndex = 0
    private var intervalMs = 6_000L
    private var autoplay = true
    private var loop = true
    private var running = false
    private var fetchInFlight = false
    private var desiredHeightPx = 0
    private var artworkAspectRatio = 20f / 9f
    private var tapsellBannerSize = "BANNER_320_50"
    private var hasRenderedContent = false
    private var lastFetchAt = 0L

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
        if (!running || !autoplay) return@Runnable

        val activity = context as? Activity
        if (
            tapsellStandardEnabled &&
            !tapsellShowing &&
            !tapsellLoading &&
            ownSlidesSinceTapsell >= tapsellEverySlides &&
            activity != null
        ) {
            showTapsellBanner(activity)
            return@Runnable
        }

        if (items.isEmpty()) return@Runnable
        val next = currentIndex + 1
        if (next >= items.size && !loop && items.size > 1) return@Runnable
        showItem(
            if (items.size <= 1) currentIndex
            else if (next >= items.size) 0
            else next,
            animate = items.size > 1,
        )
        ownSlidesSinceTapsell += 1
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
        // Campaign rotation is controlled by the panel/autoplay schedule.
        // Users may open the current campaign but cannot swipe the carousel.
        setOnClickListener { openCurrentCampaign() }
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
        hideTapsellBanner()
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
            // Fit the whole creative. Height adapts separately so artwork is
            // never silently cropped on narrow or wide devices.
            scaleType = ImageView.ScaleType.FIT_CENTER
            adjustViewBounds = false
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

        tapsellHost.apply {
            visibility = View.GONE
            isClickable = true
            isFocusable = true
            setBackgroundColor(if (palette.dark) Color.parseColor("#12131A") else Color.WHITE)
        }
        addView(
            tapsellHost,
            LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT,
                Gravity.CENTER,
            ),
        )
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
        val tapsell = root.optJSONObject("tapsell")
        val standard = tapsell?.optJSONObject("placements")
            ?.optJSONObject("standard_banner")
        tapsellStandardEnabled =
            tapsell?.optBoolean("enabled", false) == true &&
            standard?.optBoolean("enabled", false) == true
        tapsellEverySlides = tapsell
            ?.optInt("standard_banner_every_slides", 3)
            ?.coerceIn(1, 10)
            ?: 3
        tapsellBannerSize = tapsell
            ?.optString("standard_banner_size", "BANNER_320_50")
            ?.trim()
            ?.ifBlank { "BANNER_320_50" }
            ?: "BANNER_320_50"

        if (persist) {
            cachePrefs.edit().putString("mobile_config", root.toString()).apply()
            lastFetchAt = System.currentTimeMillis()
        }
        val config = root.optJSONObject("advertising") ?: root.optJSONObject("ads")
        if (config == null || !config.optBoolean("enabled", false)) {
            items = emptyList()
            if (tapsellStandardEnabled) {
                hasRenderedContent = false
                visibility = View.GONE
                ownSlidesSinceTapsell = tapsellEverySlides
                val activity = context as? Activity
                if (activity != null) {
                    showTapsellBanner(activity)
                } else {
                    hideBanner()
                }
            } else {
                hideBanner()
                handler.removeCallbacks(slideRunnable)
            }
            return
        }
        autoplay = config.optBoolean("autoplay", true)
        loop = config.optBoolean("loop", true)
        intervalMs = config.optLong("interval_ms", 6_000L).coerceIn(3_000L, 30_000L)
        desiredHeightPx = dp(config.optInt("height_dp", 180).coerceIn(96, 280))
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
                    targetAction = row.optString("target_action").trim().lowercase(),
                    targetPlanId = row.optInt("target_plan_id", 0).coerceAtLeast(0),
                    targetUrl = safeUrl(row.optString("target_url")),
                    buttonText = row.optString("button_text").trim(),
                )
            }
        }
        items = parsed
        if (items.isEmpty()) {
            if (tapsellStandardEnabled) {
                hasRenderedContent = false
                visibility = View.GONE
                ownSlidesSinceTapsell = tapsellEverySlides
                val activity = context as? Activity
                if (activity != null) {
                    showTapsellBanner(activity)
                } else {
                    hideBanner()
                }
            } else {
                hideBanner()
            }
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
                val downloaded = downloadBitmapWithRetry(url) ?: return@execute
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
        val destination = BlueVpnAdActionRouter.destination(
            item.targetAction,
            item.targetPlanId,
            item.targetUrl,
        )
        actionView.text = item.buttonText.ifBlank {
            BlueVpnAdActionRouter.defaultButtonText(destination.action)
        }
        actionView.visibility = if (destination.isActionable()) View.VISIBLE else View.GONE
        if (item.imageUrl.isBlank()) {
            dropBrokenCurrentItem(item.id)
            return
        }
        // Never expose a blank ad card. On a cold start the carousel stays
        // collapsed until the first bitmap is decoded; during slide changes
        // the previous bitmap remains visible until the next one is ready.
        loadImage(item.imageUrl, animate)
    }

    private fun loadImage(url: String, animate: Boolean = false) {
        val cached = bitmapCache.get(url)
        if (cached != null) {
            revealBitmap(cached, animate)
            return
        }

        // Disk cache survives Activity/process recreation. A cached campaign
        // therefore paints on the first frame instead of downloading again.
        val diskBitmap = readDiskBitmap(url)
        if (diskBitmap != null) {
            bitmapCache.put(url, diskBitmap)
            revealBitmap(diskBitmap, animate)
            return
        }

        val expectedId = items.getOrNull(currentIndex)?.id ?: return
        worker.execute {
            val downloaded = downloadBitmapWithRetry(url)
            if (downloaded != null) {
                bitmapCache.put(url, downloaded.bitmap)
                writeDiskBytes(url, downloaded.bytes)
            }
            handler.post {
                val current = items.getOrNull(currentIndex)
                if (current?.id == expectedId && current.imageUrl == url) {
                    if (downloaded != null) {
                        revealBitmap(downloaded.bitmap, animate)
                    } else {
                        dropBrokenCurrentItem(expectedId)
                    }
                }
            }
        }
    }

    private data class DownloadedBitmap(val bitmap: Bitmap, val bytes: ByteArray)

    private fun downloadBitmapWithRetry(url: String): DownloadedBitmap? {
        downloadBitmap(url, forceNetwork = false)?.let { return it }
        // One short retry is enough for shared-host/proxy races without making
        // a broken campaign keep a blank box on screen for many seconds.
        runCatching { Thread.sleep(180L) }
        return downloadBitmap(url, forceNetwork = true)
    }

    private fun downloadBitmap(url: String, forceNetwork: Boolean): DownloadedBitmap? = runCatching {
        val connection = URL(url).openConnection() as HttpURLConnection
        try {
            connection.connectTimeout = 3_500
            connection.readTimeout = 5_500
            connection.instanceFollowRedirects = true
            connection.useCaches = !forceNetwork
            connection.setRequestProperty("Accept", "image/webp,image/jpeg,image/png,image/*,*/*;q=0.5")
            // cPanel/PHP output compression was able to corrupt DB-backed REST
            // image responses. Prefer the byte-for-byte representation.
            connection.setRequestProperty("Accept-Encoding", "identity")
            connection.setRequestProperty("Connection", "close")
            if (forceNetwork) {
                connection.setRequestProperty("Cache-Control", "no-cache")
                connection.setRequestProperty("Pragma", "no-cache")
            }
            connection.setRequestProperty("User-Agent", "BlueVPN/${BuildConfig.VERSION_NAME}")
            val status = connection.responseCode
            if (status !in 200..299) error("HTTP $status")
            val contentType = connection.contentType.orEmpty().lowercase()
            if (!contentType.startsWith("image/") && contentType.isNotBlank()) error("Invalid image content-type")
            val bytes = readAdBytes(connection) ?: error("Invalid image bytes")
            val bitmap = decodeAdBytes(bytes) ?: error("Invalid bitmap")
            DownloadedBitmap(bitmap, bytes)
        } finally {
            connection.disconnect()
        }
    }.getOrNull()

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
        val width = availableWidth.coerceAtLeast(dp(240))

        if (tapsellLoading || tapsellShowing) {
            val match = Regex("""BANNER_(\d+)_(\d+)""")
                .find(tapsellBannerSize.uppercase(Locale.US))
            val sourceWidth = match?.groupValues?.getOrNull(1)?.toFloatOrNull() ?: 320f
            val sourceHeight = match?.groupValues?.getOrNull(2)?.toFloatOrNull() ?: 50f
            val natural = (width * (sourceHeight / sourceWidth.coerceAtLeast(1f))).roundToInt()
            return natural.coerceIn(dp(50), dp(280))
        }

        val ratio = artworkAspectRatio.takeIf { it > 0.25f } ?: (20f / 9f)
        val natural = (width / ratio).roundToInt()
        val maxConfigured = desiredHeightPx.coerceIn(dp(96), dp(280))
        return natural.coerceIn(dp(96), maxConfigured)
    }

    private fun revealBitmap(bitmap: Bitmap, animateIn: Boolean = false) {
        imageView.background = null
        imageView.setImageBitmap(bitmap)
        if (bitmap.width > 0 && bitmap.height > 0) {
            artworkAspectRatio = bitmap.width.toFloat() / bitmap.height.toFloat()
        }
        val wasVisible = hasRenderedContent && visibility == View.VISIBLE
        hasRenderedContent = true
        val targetHeight = calculateBannerHeight()
        layoutParams?.let { params ->
            if (params.height != targetHeight) {
                params.height = targetHeight
                layoutParams = params
            }
        }
        visibility = View.VISIBLE
        if (animateIn || !wasVisible) {
            alpha = if (wasVisible) 0.82f else 0.92f
            scaleX = if (wasVisible) 0.99f else 1f
            animate().alpha(1f).scaleX(1f).setDuration(if (wasVisible) 160L else 120L).start()
        } else {
            alpha = 1f
            scaleX = 1f
        }
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
        val canRotateOwn = items.size > 1
        val canRotateTapsell = tapsellStandardEnabled
        if (running && autoplay && (canRotateOwn || canRotateTapsell)) {
            handler.postDelayed(slideRunnable, intervalMs)
        }
    }

    private fun showTapsellBanner(activity: Activity) {
        if (
            tapsellLoading ||
            tapsellShowing ||
            !tapsellStandardEnabled
        ) {
            scheduleNext()
            return
        }

        tapsellLoading = true
        hasRenderedContent = true
        visibility = View.VISIBLE
        tapsellHost.visibility = View.INVISIBLE
        requestLayout()
        BlueVpnTapsellManager.attachStandardBanner(
            activity = activity,
            host = tapsellHost,
            onShown = {
                tapsellLoading = false
                tapsellShowing = true
                ownSlidesSinceTapsell = 0
                hasRenderedContent = true
                visibility = View.VISIBLE
                tapsellHost.visibility = View.VISIBLE
                requestLayout()
                handler.removeCallbacks(slideRunnable)
                handler.postDelayed({
                    hideTapsellBanner()
                    if (items.isNotEmpty()) {
                        showItem(currentIndex, animate = false)
                    }
                    scheduleNext()
                }, intervalMs)
            },
            onUnavailable = {
                tapsellLoading = false
                tapsellShowing = false
                tapsellHost.visibility = View.GONE
                if (items.isEmpty()) {
                    hideBanner()
                } else {
                    showItem(currentIndex, animate = false)
                }
                scheduleNext()
            },
            onCleanup = { cleanup ->
                tapsellCleanup?.invoke()
                tapsellCleanup = cleanup
            },
        )
    }

    private fun hideTapsellBanner() {
        tapsellCleanup?.invoke()
        tapsellCleanup = null
        tapsellLoading = false
        tapsellShowing = false
        tapsellHost.removeAllViews()
        tapsellHost.visibility = View.GONE
        requestLayout()
    }

    private fun openCurrentCampaign() {
        if (tapsellShowing) return
        val item = items.getOrNull(currentIndex) ?: return
        BlueVpnAdActionRouter.open(
            context = context,
            action = item.targetAction,
            planId = item.targetPlanId,
            fallbackUrl = item.targetUrl,
            source = "banner:${item.id}",
        )
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
