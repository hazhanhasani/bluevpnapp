package com.v2ray.ang.bluevpn

import android.app.Activity
import android.app.Dialog
import android.content.Context
import android.content.Intent
import android.content.res.ColorStateList
import android.graphics.Color
import android.graphics.Typeface
import android.graphics.drawable.ColorDrawable
import android.graphics.drawable.GradientDrawable
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.net.Uri
import android.os.Build
import android.os.Environment
import android.provider.Settings
import android.view.Gravity
import android.view.View
import android.view.ViewGroup
import android.view.Window
import android.view.WindowManager
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.ProgressBar
import android.widget.TextView
import android.widget.Toast
import com.v2ray.ang.BuildConfig
import androidx.core.content.FileProvider
import org.json.JSONObject
import java.io.BufferedInputStream
import java.io.File
import java.io.FileOutputStream
import java.io.InterruptedIOException
import java.net.HttpURLConnection
import java.net.URL

object BlueVpnUpdateManager {
    private const val PREFS = "bluevpn_update"
    private const val KEY_LAST_CHECK = "last"
    private const val KEY_MAINTENANCE = "remote_maintenance"
    private const val KEY_FORCE_BLOCK = "remote_force_block"
    private const val KEY_SUPPORT_URL = "remote_support_url"
    private const val KEY_UPDATE_URL = "remote_update_url"
    private const val KEY_UPDATE_VERSION = "remote_update_version"
    private const val KEY_AUTO_UPDATE = "remote_auto_update"
    private const val KEY_DOWNLOAD_ID = "download_id"
    private const val KEY_DOWNLOAD_VERSION = "download_version"
    private const val KEY_PENDING_INSTALL_URI = "pending_install_uri"
    private const val KEY_DOWNLOADED_FILE = "downloaded_file"
    private const val KEY_SHOWN_ANNOUNCEMENT = "shown_announcement"
    private const val KEY_INSTALLED_VERSION = "installed_version"
    private const val KEY_INSTALLED_CODE = "installed_code"
    private const val KEY_INSTALL_PROMPTED_VERSION = "install_prompted_version"
    private const val KEY_AUTO_DIALOG_VERSION = "auto_dialog_version"
    private const val KEY_OPTIONAL_SNOOZE_VERSION = "optional_snooze_version"
    private const val KEY_OPTIONAL_SNOOZE_UNTIL = "optional_snooze_until"
    private const val CHECK_INTERVAL_MS = 30_000L
    private const val OPTIONAL_SNOOZE_MS = 6 * 60 * 60 * 1000L

    @Volatile
    private var dialogShowing = false

    @Volatile
    private var activeDownloadVersion: String = ""

    @Volatile
    private var activeDownloadToken: Long = 0L

    private var activeDownloadDialog: Dialog? = null
    private var activeDownloadProgress: ProgressBar? = null
    private var activeDownloadStatus: TextView? = null

    fun check(
        activity: Activity,
        force: Boolean = false,
        showStatus: Boolean = false,
    ) {
        reconcileInstalledVersion(activity)
        resumePendingInstall(activity)
        resumeCompletedDownload(activity)

        val preferences = activity.getSharedPreferences(
            PREFS,
            Context.MODE_PRIVATE,
        )
        val now = System.currentTimeMillis()

        if (
            !force &&
            now - preferences.getLong(KEY_LAST_CHECK, 0L) <
            CHECK_INTERVAL_MS
        ) {
            showStoredBlockIfNeeded(activity)
            return
        }

        preferences.edit()
            .putLong(KEY_LAST_CHECK, now)
            .apply()

        Thread {
            runCatching {
                val connection = URL(
                    BlueVpnAccountManager.apiBaseUrl() +
                        "/api/v1/mobile/config" +
                        if (force) "?refresh=true" else ""
                ).openConnection() as HttpURLConnection

                try {
                    connection.connectTimeout = 10_000
                    connection.readTimeout = 14_000
                    connection.setRequestProperty(
                        "Accept",
                        "application/json",
                    )
                    connection.setRequestProperty(
                        "Cache-Control",
                        "no-cache",
                    )
                    connection.setRequestProperty(
                        "User-Agent",
                        "BlueVPN/${BuildConfig.VERSION_NAME}",
                    )

                    if (connection.responseCode !in 200..299) {
                        error(
                            "HTTP ${connection.responseCode}"
                        )
                    }

                    JSONObject(
                        connection.inputStream
                            .bufferedReader()
                            .use { it.readText() }
                    )
                } finally {
                    connection.disconnect()
                }
            }.onSuccess { config ->
                activity.runOnUiThread {
                    val updateFound = applyRemoteConfig(
                        activity,
                        config,
                    )

                    if (
                        showStatus &&
                        !updateFound &&
                        !config.optBoolean(
                            "maintenance",
                            false,
                        )
                    ) {
                        Toast.makeText(
                            activity,
                            "نسخه ${BuildConfig.VERSION_NAME} آخرین نسخه است",
                            Toast.LENGTH_LONG,
                        ).show()
                    }
                }
            }.onFailure { error ->
                activity.runOnUiThread {
                    showStoredBlockIfNeeded(activity)

                    if (showStatus) {
                        Toast.makeText(
                            activity,
                            "بررسی بروزرسانی ناموفق بود: " +
                                (
                                    error.message
                                        ?: "خطای ارتباط"
                                    ),
                            Toast.LENGTH_LONG,
                        ).show()
                    }
                }
            }
        }.apply {
            name = "BlueVPN-Remote-Settings"
            isDaemon = true
            start()
        }
    }

    fun blockInteraction(
        activity: Activity,
    ): Boolean {
        reconcileInstalledVersion(activity)

        val preferences = activity.getSharedPreferences(
            PREFS,
            Context.MODE_PRIVATE,
        )

        return when {
            preferences.getBoolean(
                KEY_MAINTENANCE,
                false,
            ) -> {
                showMaintenanceDialog(
                    activity,
                    preferences.getString(
                        KEY_SUPPORT_URL,
                        "",
                    ).orEmpty(),
                )
                true
            }

            preferences.getBoolean(
                KEY_FORCE_BLOCK,
                false,
            ) -> {
                showForcedUpdateDialog(
                    activity,
                    preferences.getString(
                        KEY_UPDATE_URL,
                        "",
                    ).orEmpty(),
                    preferences.getString(
                        KEY_UPDATE_VERSION,
                        "",
                    ).orEmpty(),
                )
                true
            }

            else -> false
        }
    }

    fun resumePendingInstall(
        activity: Activity,
    ) {
        reconcileInstalledVersion(activity)

        val preferences = activity.getSharedPreferences(
            PREFS,
            Context.MODE_PRIVATE,
        )
        val path = preferences.getString(
            KEY_PENDING_INSTALL_URI,
            "",
        ).orEmpty()

        if (path.isBlank()) return

        val file = File(path)
        if (!file.isFile || file.length() <= 0L) {
            preferences.edit()
                .remove(KEY_PENDING_INSTALL_URI)
                .remove(KEY_DOWNLOADED_FILE)
                .apply()
            return
        }

        if (
            Build.VERSION.SDK_INT >= Build.VERSION_CODES.O &&
            !activity.packageManager.canRequestPackageInstalls()
        ) {
            return
        }

        preferences.edit()
            .remove(KEY_PENDING_INSTALL_URI)
            .apply()

        installDownloadedFile(
            activity,
            file,
            forceLaunch = true,
        )
    }

    private fun applyRemoteConfig(
        activity: Activity,
        config: JSONObject,
    ): Boolean {
        val preferences = activity.getSharedPreferences(
            PREFS,
            Context.MODE_PRIVATE,
        )

        val maintenance = config.optBoolean(
            "maintenance",
            false,
        )
        val supportUrl = config.optString(
            "support_url",
            "",
        )
        val latestCode = config.optInt(
            "latest_version_code",
            0,
        )
        val latestVersion = config.optString(
            "latest_version",
            "",
        )
        val apkUrl = selectApkUrl(config)
        val autoUpdate = config.optBoolean(
            "auto_update",
            true,
        )

        val belowMinimum = compareVersions(
            BuildConfig.VERSION_NAME,
            config.optString(
                "minimum_version",
                "0.0.0",
            ),
        ) < 0

        val latestHasSemanticVersion =
            latestVersion.any(Char::isDigit) &&
                latestVersion.contains(".")

        val codeIsNewer =
            latestCode > BuildConfig.VERSION_CODE

        val nameComparison =
            if (latestHasSemanticVersion) {
                compareVersions(
                    latestVersion,
                    BuildConfig.VERSION_NAME,
                )
            } else {
                0
            }

        val updateAvailable =
            (
                if (latestHasSemanticVersion) {
                    nameComparison > 0
                } else {
                    codeIsNewer
                }
                ) &&
                apkUrl.startsWith("http")

        if (!updateAvailable) {
            clearObsoleteUpdateState(
                activity,
                preferences,
            )
        }

        val forced =
            updateAvailable &&
                (
                    config.optBoolean(
                        "force_update",
                        false,
                    ) ||
                        belowMinimum
                    )

        preferences.edit()
            .putBoolean(KEY_MAINTENANCE, maintenance)
            .putBoolean(KEY_FORCE_BLOCK, forced)
            .putBoolean(KEY_AUTO_UPDATE, autoUpdate)
            .putString(KEY_SUPPORT_URL, supportUrl)
            .putString(KEY_UPDATE_URL, apkUrl)
            .putString(KEY_UPDATE_VERSION, latestVersion)
            .apply()

        when {
            maintenance -> {
                showMaintenanceDialog(
                    activity,
                    supportUrl,
                )
            }

            forced -> {
                startAutomaticDownload(
                    activity,
                    apkUrl,
                    latestVersion,
                    forced = true,
                )
                showForcedUpdateDialog(
                    activity,
                    apkUrl,
                    latestVersion,
                )
            }

            updateAvailable && autoUpdate -> {
                startAutomaticDownload(
                    activity,
                    apkUrl,
                    latestVersion,
                    forced = false,
                )
                showAutomaticUpdateDialog(
                    activity,
                    latestVersion,
                )
            }

            updateAvailable -> {
                showOptionalUpdateDialog(
                    activity,
                    config,
                    apkUrl,
                )
            }

            else -> {
                showAnnouncementIfNeeded(
                    activity,
                    config,
                )
            }
        }

        return updateAvailable
    }

    private fun showStoredBlockIfNeeded(
        activity: Activity,
    ) {
        blockInteraction(activity)
    }
private fun dp(
    context: Context,
    value: Int,
): Int = (
    value * context.resources.displayMetrics.density
    ).toInt()

private fun roundedBackground(
    fillColor: Int,
    radiusDp: Int,
    context: Context,
    strokeColor: Int? = null,
    strokeDp: Int = 1,
): GradientDrawable {
    return GradientDrawable().apply {
        shape = GradientDrawable.RECTANGLE
        cornerRadius = dp(
            context,
            radiusDp,
        ).toFloat()
        setColor(fillColor)

        if (strokeColor != null) {
            setStroke(
                dp(context, strokeDp),
                strokeColor,
            )
        }
    }
}

private fun premiumText(
    context: Context,
    text: String,
    sizeSp: Float,
    color: Int,
    bold: Boolean = false,
): TextView {
    return TextView(context).apply {
        this.text = text
        textSize = sizeSp
        setTextColor(color)
        gravity = Gravity.RIGHT
        textDirection = View.TEXT_DIRECTION_RTL
        setLineSpacing(
            0f,
            1.18f,
        )

        if (bold) {
            setTypeface(
                Typeface.DEFAULT,
                Typeface.BOLD,
            )
        }
    }
}

private fun premiumButton(
    context: Context,
    text: String,
    fillColor: Int,
    textColor: Int,
    outlined: Boolean = false,
): TextView {
    return premiumText(
        context,
        text,
        14f,
        textColor,
        bold = true,
    ).apply {
        gravity = Gravity.CENTER
        minHeight = dp(context, 50)
        setPadding(
            dp(context, 14),
            dp(context, 12),
            dp(context, 14),
            dp(context, 12),
        )
        background = roundedBackground(
            if (outlined) {
                Color.TRANSPARENT
            } else {
                fillColor
            },
            16,
            context,
            if (outlined) fillColor else null,
        )
        isClickable = true
        isFocusable = true
    }
}

private fun showPremiumDialog(
    activity: Activity,
    eyebrow: String,
    title: String,
    message: String,
    accentColor: Int,
    primaryText: String,
    secondaryText: String? = null,
    cancelable: Boolean = true,
    showProgress: Boolean = false,
    onPrimary: () -> Unit = {},
    onSecondary: (() -> Unit)? = null,
): Dialog? {
    if (
        activity.isFinishing ||
        activity.isDestroyed ||
        dialogShowing
    ) {
        return null
    }

    dialogShowing = true

    val dialog = Dialog(activity)
    dialog.requestWindowFeature(
        Window.FEATURE_NO_TITLE
    )
    dialog.setCancelable(cancelable)
    dialog.setCanceledOnTouchOutside(
        cancelable
    )

    val card = LinearLayout(activity).apply {
        orientation = LinearLayout.VERTICAL
        layoutDirection = View.LAYOUT_DIRECTION_RTL
        setPadding(
            dp(activity, 22),
            dp(activity, 20),
            dp(activity, 22),
            dp(activity, 20),
        )
        background = roundedBackground(
            Color.parseColor("#0A1830"),
            28,
            activity,
            Color.parseColor("#2B568F"),
        )
    }

    val header = LinearLayout(activity).apply {
        orientation = LinearLayout.HORIZONTAL
        gravity = Gravity.CENTER_VERTICAL
        layoutDirection = View.LAYOUT_DIRECTION_RTL
    }

    val icon = ImageView(activity).apply {
        setImageDrawable(
            activity.applicationInfo.loadIcon(
                activity.packageManager
            )
        )
        background = roundedBackground(
            Color.parseColor("#102D56"),
            18,
            activity,
            Color.parseColor("#3576CE"),
        )
        setPadding(
            dp(activity, 7),
            dp(activity, 7),
            dp(activity, 7),
            dp(activity, 7),
        )
    }

    header.addView(
        icon,
        LinearLayout.LayoutParams(
            dp(activity, 56),
            dp(activity, 56),
        ).apply {
            marginStart = dp(activity, 14)
        },
    )

    val heading = LinearLayout(activity).apply {
        orientation = LinearLayout.VERTICAL
        gravity = Gravity.RIGHT
    }

    val eyebrowView = premiumText(
        activity,
        eyebrow,
        12f,
        accentColor,
        bold = true,
    ).apply {
        setPadding(
            dp(activity, 10),
            dp(activity, 5),
            dp(activity, 10),
            dp(activity, 5),
        )
        background = roundedBackground(
            Color.argb(
                38,
                Color.red(accentColor),
                Color.green(accentColor),
                Color.blue(accentColor),
            ),
            12,
            activity,
            Color.argb(
                105,
                Color.red(accentColor),
                Color.green(accentColor),
                Color.blue(accentColor),
            ),
        )
    }

    heading.addView(
        eyebrowView,
        LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.WRAP_CONTENT,
            ViewGroup.LayoutParams.WRAP_CONTENT,
        ).apply {
            gravity = Gravity.RIGHT
            bottomMargin = dp(activity, 7)
        },
    )

    heading.addView(
        premiumText(
            activity,
            title,
            21f,
            Color.parseColor("#F5F8FF"),
            bold = true,
        ),
    )

    header.addView(
        heading,
        LinearLayout.LayoutParams(
            0,
            ViewGroup.LayoutParams.WRAP_CONTENT,
            1f,
        ),
    )

    card.addView(header)

    card.addView(
        premiumText(
            activity,
            message,
            15f,
            Color.parseColor("#AEBBD0"),
        ),
        LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT,
            ViewGroup.LayoutParams.WRAP_CONTENT,
        ).apply {
            topMargin = dp(activity, 18)
        },
    )

    if (showProgress) {
        val progress = ProgressBar(
            activity,
            null,
            android.R.attr.progressBarStyleHorizontal,
        ).apply {
            isIndeterminate = true
            max = 100
            indeterminateTintList =
                ColorStateList.valueOf(
                    accentColor
                )
            progressTintList =
                ColorStateList.valueOf(
                    accentColor
                )
        }

        activeDownloadProgress = progress

        card.addView(
            progress,
            LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                dp(activity, 7),
            ).apply {
                topMargin = dp(activity, 18)
                bottomMargin = dp(activity, 8)
            },
        )

        val progressStatus = premiumText(
            activity,
            "در حال آماده‌سازی دانلود داخل برنامه…",
            12f,
            Color.parseColor("#8EA6C8"),
            bold = true,
        ).apply {
            gravity = Gravity.CENTER
        }
        activeDownloadStatus = progressStatus
        card.addView(progressStatus)
    }

    val buttons = LinearLayout(activity).apply {
        orientation = LinearLayout.HORIZONTAL
        gravity = Gravity.CENTER
        layoutDirection = View.LAYOUT_DIRECTION_RTL
    }

    val primary = premiumButton(
        activity,
        primaryText,
        accentColor,
        Color.WHITE,
    )

    buttons.addView(
        primary,
        LinearLayout.LayoutParams(
            0,
            dp(activity, 52),
            1f,
        ),
    )

    val secondary = secondaryText?.let {
        premiumButton(
            activity,
            it,
            accentColor,
            Color.parseColor("#C7D5EA"),
            outlined = true,
        )
    }

    if (secondary != null) {
        buttons.addView(
            secondary,
            LinearLayout.LayoutParams(
                0,
                dp(activity, 52),
                1f,
            ).apply {
                marginStart = dp(activity, 10)
            },
        )
    }

    card.addView(
        buttons,
        LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT,
            ViewGroup.LayoutParams.WRAP_CONTENT,
        ).apply {
            topMargin = dp(activity, 22)
        },
    )

    dialog.setContentView(card)

    primary.setOnClickListener {
        dialog.dismiss()
        onPrimary()
    }

    secondary?.setOnClickListener {
        dialog.dismiss()
        onSecondary?.invoke()
    }

    dialog.setOnDismissListener {
        dialogShowing = false
        if (activeDownloadDialog === dialog) {
            activeDownloadDialog = null
            activeDownloadProgress = null
            activeDownloadStatus = null
        }
    }

    dialog.show()

    dialog.window?.apply {
        setBackgroundDrawable(
            ColorDrawable(Color.TRANSPARENT)
        )
        addFlags(
            WindowManager.LayoutParams.FLAG_DIM_BEHIND
        )
        attributes = attributes.apply {
            width = (
                activity.resources.displayMetrics.widthPixels *
                    0.90f
                ).toInt()
            dimAmount = 0.72f
        }
    }

    return dialog
}

private fun showMaintenanceDialog(
    activity: Activity,
    supportUrl: String,
) {
    showPremiumDialog(
        activity = activity,
        eyebrow = "وضعیت سرویس",
        title = "در حال بهبود زیرساخت",
        message = "اتصال برای مدت کوتاهی متوقف شده است. اطلاعات حساب شما محفوظ می‌ماند و پس از پایان تعمیرات، سرویس دوباره در دسترس خواهد بود.",
        accentColor = Color.parseColor("#FFB547"),
        primaryText = if (
            supportUrl.startsWith("http")
        ) {
            "پشتیبانی"
        } else {
            "متوجه شدم"
        },
        secondaryText = "بستن برنامه",
        cancelable = false,
        onPrimary = {
            if (supportUrl.startsWith("http")) {
                activity.startActivity(
                    Intent(
                        Intent.ACTION_VIEW,
                        Uri.parse(supportUrl),
                    )
                )
            }
        },
        onSecondary = {
            activity.finishAffinity()
        },
    )
}

private fun showForcedUpdateDialog(
    activity: Activity,
    apkUrl: String,
    version: String,
) {
    showPremiumDialog(
        activity = activity,
        eyebrow = "بروزرسانی ضروری",
        title = "نسخه $version آماده نصب است",
        message = "برای حفظ پایداری اتصال باید این نسخه نصب شود. فایل فقط یک‌بار دانلود می‌شود؛ پس از آماده‌شدن، نصب را از همین پنجره ادامه دهید.",
        accentColor = Color.parseColor("#4D8DFF"),
        primaryText = "دانلود داخل برنامه",
        secondaryText = "تلاش دوباره",
        cancelable = false,
        showProgress = true,
        onPrimary = {
            retryUpdateInstall(
                activity,
                apkUrl,
                version,
                forced = true,
            )
        },
        onSecondary = {
            retryUpdateInstall(
                activity,
                apkUrl,
                version,
                forced = true,
            )
        },
    )
}

private fun showAutomaticUpdateDialog(
    activity: Activity,
    version: String,
) {
    val preferences = activity.getSharedPreferences(
        PREFS,
        Context.MODE_PRIVATE,
    )

    if (
        preferences.getString(
            KEY_AUTO_DIALOG_VERSION,
            "",
        ) == version
    ) {
        return
    }

    preferences.edit()
        .putString(
            KEY_AUTO_DIALOG_VERSION,
            version,
        )
        .apply()

    showPremiumDialog(
        activity = activity,
        eyebrow = "نسخه تازه",
        title = "BlueVPN $version آماده است",
        message = "دانلود از داخل خود BlueVPN انجام می‌شود و لازم نیست اتصال VPN را قطع کنید یا وارد پوشه دانلودها شوید. پس از پایان، صفحه نصب خودکار باز می‌شود.",
        accentColor = Color.parseColor("#2E82FF"),
        primaryText = "نمایش دانلود داخل برنامه",
        secondaryText = "ادامه در پس‌زمینه",
        showProgress = true,
        onPrimary = {
            showActiveDownloadDialog(
                activity,
                version,
                forced = false,
            )
        },
    )
}

private fun showOptionalUpdateDialog(
    activity: Activity,
    config: JSONObject,
    apkUrl: String,
) {
    val preferences = activity.getSharedPreferences(
        PREFS,
        Context.MODE_PRIVATE,
    )
    val version = config.optString(
        "latest_version",
        "",
    )
    val snoozedVersion = preferences.getString(
        KEY_OPTIONAL_SNOOZE_VERSION,
        "",
    )
    val snoozedUntil = preferences.getLong(
        KEY_OPTIONAL_SNOOZE_UNTIL,
        0L,
    )

    if (
        snoozedVersion == version &&
        System.currentTimeMillis() < snoozedUntil
    ) {
        return
    }

    showPremiumDialog(
        activity = activity,
        eyebrow = "پیشنهاد بروزرسانی",
        title = config.optString(
            "update_title",
            "نسخه $version منتشر شد",
        ),
        message = config.optString(
            "update_message",
            "این نسخه شامل بهبودهای ظاهری، پایداری بیشتر و اصلاحات اتصال است.",
        ),
        accentColor = Color.parseColor("#7A6CFF"),
        primaryText = "بروزرسانی",
        secondaryText = "بعداً",
        onPrimary = {
            startAutomaticDownload(
                activity,
                apkUrl,
                version,
                forced = false,
            )
            showAutomaticUpdateDialog(
                activity,
                version,
            )
        },
        onSecondary = {
            preferences.edit()
                .putString(
                    KEY_OPTIONAL_SNOOZE_VERSION,
                    version,
                )
                .putLong(
                    KEY_OPTIONAL_SNOOZE_UNTIL,
                    System.currentTimeMillis() +
                        OPTIONAL_SNOOZE_MS,
                )
                .apply()
        },
    )
}

private fun showAnnouncementIfNeeded(
    activity: Activity,
    config: JSONObject,
) {
    val announcement = config.optJSONObject(
        "announcement"
    ) ?: return

    if (!announcement.optBoolean("enabled")) {
        return
    }

    val identifier = announcement.optString(
        "id",
        "",
    ).trim()

    if (identifier.isBlank()) return

    val preferences = activity.getSharedPreferences(
        PREFS,
        Context.MODE_PRIVATE,
    )

    if (
        preferences.getString(
            KEY_SHOWN_ANNOUNCEMENT,
            "",
        ) == identifier
    ) {
        return
    }

    showPremiumDialog(
        activity = activity,
        eyebrow = "اطلاعیه",
        title = announcement.optString(
            "title",
            "خبر تازه BlueVPN",
        ),
        message = announcement.optString(
            "message",
            "",
        ),
        accentColor = Color.parseColor("#24C7A5"),
        primaryText = "متوجه شدم",
        onPrimary = {
            preferences.edit()
                .putString(
                    KEY_SHOWN_ANNOUNCEMENT,
                    identifier,
                )
                .apply()
        },
    )
}

private fun startAutomaticDownload(
    activity: Activity,
    apkUrl: String,
    version: String,
    forced: Boolean,
) {
    if (!apkUrl.startsWith("http")) return

    val preferences = activity.getSharedPreferences(
        PREFS,
        Context.MODE_PRIVATE,
    )

    val existing = preferences.getString(
        KEY_DOWNLOADED_FILE,
        "",
    ).orEmpty()
        .takeIf { it.isNotBlank() }
        ?.let(::File)

    if (
        preferences.getString(
            KEY_DOWNLOAD_VERSION,
            "",
        ) == version &&
        existing != null &&
        existing.isFile &&
        existing.length() > 0L
    ) {
        installDownloadedFile(
            activity,
            existing,
            forceLaunch = true,
        )
        return
    }

    if (activeDownloadVersion == version) {
        showActiveDownloadDialog(
            activity,
            version,
            forced,
        )
        return
    }

    clearDownloadState(
        activity,
        preferences,
        removeDownload = true,
    )

    val safeVersion = version
        .ifBlank { "latest" }
        .replace(
            Regex("[^A-Za-z0-9._-]"),
            "_",
        )

    val directory = activity.getExternalFilesDir(
        Environment.DIRECTORY_DOWNLOADS
    ) ?: File(
        activity.filesDir,
        "updates",
    )

    directory.mkdirs()

    val target = File(
        directory,
        "BlueVPN_$safeVersion.apk",
    )
    val temporary = File(
        directory,
        "BlueVPN_$safeVersion.apk.part",
    )

    target.delete()
    temporary.delete()

    val token = System.nanoTime()
    activeDownloadToken = token
    activeDownloadVersion = version

    preferences.edit()
        .putString(KEY_DOWNLOAD_VERSION, version)
        .remove(KEY_DOWNLOADED_FILE)
        .remove(KEY_INSTALL_PROMPTED_VERSION)
        .remove(KEY_PENDING_INSTALL_URI)
        .apply()

    showActiveDownloadDialog(
        activity,
        version,
        forced,
    )

    Thread {
        var connection: HttpURLConnection? = null

        runCatching {
            connection = openDownloadConnection(
                activity.applicationContext,
                apkUrl,
            ).apply {
                connectTimeout = 15_000
                readTimeout = 35_000
                instanceFollowRedirects = true
                setRequestProperty(
                    "Accept",
                    "application/vnd.android.package-archive,*/*",
                )
                setRequestProperty(
                    "Cache-Control",
                    "no-cache",
                )
                setRequestProperty(
                    "User-Agent",
                    "BlueVPN/${BuildConfig.VERSION_NAME}",
                )
            }

            val responseCode = connection!!.responseCode
            if (responseCode !in 200..299) {
                error("HTTP $responseCode")
            }

            val total = connection!!.contentLengthLong
            var downloaded = 0L
            var lastUiUpdate = 0L

            BufferedInputStream(
                connection!!.inputStream,
                64 * 1024,
            ).use { input ->
                FileOutputStream(temporary).use { output ->
                    val buffer = ByteArray(64 * 1024)

                    while (true) {
                        if (activeDownloadToken != token) {
                            throw InterruptedIOException(
                                "download replaced"
                            )
                        }

                        val count = input.read(buffer)
                        if (count < 0) break

                        output.write(buffer, 0, count)
                        downloaded += count

                        val now = System.currentTimeMillis()
                        if (now - lastUiUpdate >= 220L) {
                            lastUiUpdate = now
                            updateDownloadProgress(
                                activity,
                                downloaded,
                                total,
                            )
                        }
                    }

                    output.flush()
                    output.fd.sync()
                }
            }

            if (downloaded <= 0L) {
                error("فایل دانلودشده خالی است")
            }

            if (!temporary.renameTo(target)) {
                temporary.copyTo(
                    target,
                    overwrite = true,
                )
                temporary.delete()
            }

            if (!target.isFile || target.length() <= 0L) {
                error("ذخیره فایل بروزرسانی ناموفق بود")
            }

            preferences.edit()
                .putString(
                    KEY_DOWNLOADED_FILE,
                    target.absolutePath,
                )
                .remove(KEY_PENDING_INSTALL_URI)
                .remove(KEY_INSTALL_PROMPTED_VERSION)
                .apply()

            activeDownloadVersion = ""

            activity.runOnUiThread {
                activeDownloadDialog?.dismiss()
                activeDownloadDialog = null
                Toast.makeText(
                    activity.applicationContext,
                    "دانلود کامل شد؛ نصب BlueVPN در حال بازشدن است",
                    Toast.LENGTH_LONG,
                ).show()

                installDownloadedFile(
                    if (
                        activity.isFinishing ||
                        activity.isDestroyed
                    ) {
                        activity.applicationContext
                    } else {
                        activity
                    },
                    target,
                    forceLaunch = true,
                )
            }
        }.onFailure { error ->
            temporary.delete()

            if (
                activeDownloadToken == token &&
                error !is InterruptedIOException
            ) {
                activeDownloadVersion = ""

                activity.runOnUiThread {
                    activeDownloadDialog?.dismiss()
                    activeDownloadDialog = null

                    showPremiumDialog(
                        activity = activity,
                        eyebrow = "دانلود ناموفق",
                        title = "دریافت نسخه $version کامل نشد",
                        message = (
                            error.message
                                ?: "ارتباط با سرور بروزرسانی قطع شد"
                            ) + "\n\nاتصال VPN لازم نیست قطع شود؛ دوباره تلاش کنید.",
                        accentColor = Color.parseColor("#FF6E83"),
                        primaryText = "تلاش دوباره",
                        secondaryText = "بعداً",
                        cancelable = !forced,
                        onPrimary = {
                            startAutomaticDownload(
                                activity,
                                apkUrl,
                                version,
                                forced,
                            )
                        },
                    )
                }
            }
        }

        connection?.disconnect()
    }.apply {
        name = "BlueVPN-InApp-Updater-$safeVersion"
        isDaemon = true
        start()
    }
}

private fun showActiveDownloadDialog(
    activity: Activity,
    version: String,
    forced: Boolean,
) {
    if (
        activeDownloadDialog?.isShowing == true ||
        activity.isFinishing ||
        activity.isDestroyed
    ) {
        return
    }

    activeDownloadDialog = showPremiumDialog(
        activity = activity,
        eyebrow = "دانلود داخلی BlueVPN",
        title = "نسخه $version در حال دریافت است",
        message = "دانلود از مسیر اینترنت اصلی دستگاه انجام می‌شود؛ اتصال VPN برقرار می‌ماند و نیازی به رفتن به بخش Downloads نیست.",
        accentColor = Color.parseColor("#2E82FF"),
        primaryText = "ادامه در پس‌زمینه",
        secondaryText = null,
        cancelable = !forced,
        showProgress = true,
    )
}

private fun updateDownloadProgress(
    activity: Activity,
    downloaded: Long,
    total: Long,
) {
    if (activity.isFinishing || activity.isDestroyed) {
        return
    }

    activity.runOnUiThread {
        val progress = activeDownloadProgress
        val status = activeDownloadStatus

        if (total > 0L) {
            val percent = (
                downloaded * 100L / total
                ).toInt().coerceIn(0, 100)

            progress?.isIndeterminate = false
            progress?.progress = percent
            status?.text = (
                "$percent٪  •  " +
                    formatBytes(downloaded) +
                    " از " +
                    formatBytes(total)
                )
        } else {
            progress?.isIndeterminate = true
            status?.text = (
                "دریافت‌شده: " +
                    formatBytes(downloaded)
                )
        }
    }
}

private fun formatBytes(value: Long): String {
    if (value < 1024L) return "$value B"

    val kilo = value / 1024.0
    if (kilo < 1024.0) {
        return String.format(
            java.util.Locale.US,
            "%.1f KB",
            kilo,
        )
    }

    return String.format(
        java.util.Locale.US,
        "%.1f MB",
        kilo / 1024.0,
    )
}

private fun openDownloadConnection(
    context: Context,
    url: String,
): HttpURLConnection {
    val connectivity = context.getSystemService(
        Context.CONNECTIVITY_SERVICE
    ) as ConnectivityManager

    val physicalNetwork = connectivity.allNetworks
        .mapNotNull { network ->
            val capabilities =
                connectivity.getNetworkCapabilities(
                    network
                ) ?: return@mapNotNull null

            val physical =
                !capabilities.hasTransport(
                    NetworkCapabilities.TRANSPORT_VPN
                ) &&
                    (
                        capabilities.hasTransport(
                            NetworkCapabilities.TRANSPORT_WIFI
                        ) ||
                            capabilities.hasTransport(
                                NetworkCapabilities.TRANSPORT_CELLULAR
                            ) ||
                            capabilities.hasTransport(
                                NetworkCapabilities.TRANSPORT_ETHERNET
                            )
                        ) &&
                    capabilities.hasCapability(
                        NetworkCapabilities.NET_CAPABILITY_INTERNET
                    )

            if (physical) {
                val validated = capabilities.hasCapability(
                    NetworkCapabilities.NET_CAPABILITY_VALIDATED
                )
                Triple(
                    network,
                    validated,
                    capabilities.hasCapability(
                        NetworkCapabilities.NET_CAPABILITY_NOT_METERED
                    ),
                )
            } else {
                null
            }
        }
        .sortedWith(
            compareByDescending<Triple<android.net.Network, Boolean, Boolean>> {
                it.second
            }.thenByDescending {
                it.third
            }
        )
        .firstOrNull()
        ?.first

    val target = URL(url)

    return (
        physicalNetwork?.openConnection(target)
            ?: target.openConnection()
        ) as HttpURLConnection
}

private fun resumeCompletedDownload(
    activity: Activity,
    forceLaunch: Boolean = false,
) {
    val preferences = activity.getSharedPreferences(
        PREFS,
        Context.MODE_PRIVATE,
    )
    val targetVersion = preferences.getString(
        KEY_DOWNLOAD_VERSION,
        "",
    ).orEmpty()
    val path = preferences.getString(
        KEY_DOWNLOADED_FILE,
        "",
    ).orEmpty()

    if (path.isBlank()) return

    if (
        targetVersion.isBlank() ||
        compareVersions(
            targetVersion,
            BuildConfig.VERSION_NAME,
        ) <= 0
    ) {
        clearDownloadState(
            activity,
            preferences,
            removeDownload = true,
        )
        return
    }

    if (
        !forceLaunch &&
        preferences.getString(
            KEY_INSTALL_PROMPTED_VERSION,
            "",
        ) == targetVersion
    ) {
        return
    }

    val file = File(path)
    if (!file.isFile || file.length() <= 0L) {
        preferences.edit()
            .remove(KEY_DOWNLOADED_FILE)
            .apply()
        return
    }

    installDownloadedFile(
        activity,
        file,
        forceLaunch,
    )
}

private fun retryUpdateInstall(
    activity: Activity,
    apkUrl: String,
    version: String,
    forced: Boolean,
) {
    val preferences = activity.getSharedPreferences(
        PREFS,
        Context.MODE_PRIVATE,
    )
    val path = preferences.getString(
        KEY_DOWNLOADED_FILE,
        "",
    ).orEmpty()
    val file = path
        .takeIf { it.isNotBlank() }
        ?.let(::File)

    preferences.edit()
        .remove(KEY_INSTALL_PROMPTED_VERSION)
        .remove(KEY_PENDING_INSTALL_URI)
        .apply()

    if (
        preferences.getString(
            KEY_DOWNLOAD_VERSION,
            "",
        ) == version &&
        file != null &&
        file.isFile &&
        file.length() > 0L
    ) {
        installDownloadedFile(
            activity,
            file,
            forceLaunch = true,
        )
    } else {
        startAutomaticDownload(
            activity,
            apkUrl,
            version,
            forced,
        )
    }
}

private fun installDownloadedFile(
    context: Context,
    file: File,
    forceLaunch: Boolean = false,
) {
    val preferences = context.getSharedPreferences(
        PREFS,
        Context.MODE_PRIVATE,
    )
    val targetVersion = preferences.getString(
        KEY_DOWNLOAD_VERSION,
        "",
    ).orEmpty()

    if (
        !file.isFile ||
        file.length() <= 0L ||
        targetVersion.isBlank() ||
        compareVersions(
            targetVersion,
            BuildConfig.VERSION_NAME,
        ) <= 0
    ) {
        return
    }

    if (
        !forceLaunch &&
        preferences.getString(
            KEY_INSTALL_PROMPTED_VERSION,
            "",
        ) == targetVersion
    ) {
        return
    }

    if (
        Build.VERSION.SDK_INT >= Build.VERSION_CODES.O &&
        !context.packageManager.canRequestPackageInstalls()
    ) {
        preferences.edit()
            .putString(
                KEY_PENDING_INSTALL_URI,
                file.absolutePath,
            )
            .putString(
                KEY_DOWNLOADED_FILE,
                file.absolutePath,
            )
            .remove(KEY_INSTALL_PROMPTED_VERSION)
            .apply()

        context.startActivity(
            Intent(
                Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,
                Uri.parse(
                    "package:${context.packageName}"
                ),
            ).addFlags(
                Intent.FLAG_ACTIVITY_NEW_TASK
            )
        )

        Toast.makeText(
            context,
            "اجازه نصب برنامه‌های ناشناس را برای BlueVPN فعال کنید؛ سپس به برنامه برگردید",
            Toast.LENGTH_LONG,
        ).show()
        return
    }

    preferences.edit()
        .putString(
            KEY_INSTALL_PROMPTED_VERSION,
            targetVersion,
        )
        .remove(KEY_PENDING_INSTALL_URI)
        .apply()

    val uri = FileProvider.getUriForFile(
        context,
        context.packageName +
            ".bluevpn.updateprovider",
        file,
    )

    launchInstaller(
        context,
        uri,
    )
}

private fun launchInstaller(
    context: Context,
    uri: Uri,
) {
    context.startActivity(
        Intent(Intent.ACTION_VIEW)
            .setDataAndType(
                uri,
                "application/vnd.android.package-archive",
            )
            .addFlags(
                Intent.FLAG_GRANT_READ_URI_PERMISSION or
                    Intent.FLAG_ACTIVITY_NEW_TASK
            )
    )
}

private fun clearDownloadState(
    context: Context,
    preferences: android.content.SharedPreferences,
    removeDownload: Boolean,
) {
    activeDownloadToken = System.nanoTime()
    activeDownloadVersion = ""

    if (removeDownload) {
        listOf(
            preferences.getString(
                KEY_DOWNLOADED_FILE,
                "",
            ).orEmpty(),
            preferences.getString(
                KEY_PENDING_INSTALL_URI,
                "",
            ).orEmpty(),
        )
            .filter { it.isNotBlank() }
            .map(::File)
            .forEach { file ->
                runCatching {
                    if (file.isFile) file.delete()
                }
            }
    }

    preferences.edit()
        .remove(KEY_DOWNLOAD_ID)
        .remove(KEY_DOWNLOAD_VERSION)
        .remove(KEY_DOWNLOADED_FILE)
        .remove(KEY_PENDING_INSTALL_URI)
        .remove(KEY_INSTALL_PROMPTED_VERSION)
        .apply()
}

private fun clearObsoleteUpdateState(
    context: Context,
    preferences: android.content.SharedPreferences,
) {
    clearDownloadState(
        context,
        preferences,
        removeDownload = true,
    )

    preferences.edit()
        .putBoolean(KEY_FORCE_BLOCK, false)
        .remove(KEY_UPDATE_URL)
        .remove(KEY_UPDATE_VERSION)
        .remove(KEY_AUTO_DIALOG_VERSION)
        .remove(KEY_OPTIONAL_SNOOZE_VERSION)
        .remove(KEY_OPTIONAL_SNOOZE_UNTIL)
        .apply()
}

private fun reconcileInstalledVersion(
    context: Context,
) {
    val preferences = context.getSharedPreferences(
        PREFS,
        Context.MODE_PRIVATE,
    )
    val storedVersion = preferences.getString(
        KEY_INSTALLED_VERSION,
        "",
    )
    val storedCode = preferences.getInt(
        KEY_INSTALLED_CODE,
        -1,
    )

    if (
        storedVersion == BuildConfig.VERSION_NAME &&
        storedCode == BuildConfig.VERSION_CODE
    ) {
        return
    }

    clearDownloadState(
        context,
        preferences,
        removeDownload = true,
    )

    preferences.edit()
        .putString(
            KEY_INSTALLED_VERSION,
            BuildConfig.VERSION_NAME,
        )
        .putInt(
            KEY_INSTALLED_CODE,
            BuildConfig.VERSION_CODE,
        )
        .putLong(KEY_LAST_CHECK, 0L)
        .putBoolean(KEY_FORCE_BLOCK, false)
        .remove(KEY_UPDATE_URL)
        .remove(KEY_UPDATE_VERSION)
        .remove(KEY_AUTO_DIALOG_VERSION)
        .remove(KEY_OPTIONAL_SNOOZE_VERSION)
        .remove(KEY_OPTIONAL_SNOOZE_UNTIL)
        .apply()
}


    private fun selectApkUrl(
        config: JSONObject,
    ): String {
        val assets = config.optJSONObject(
            "apk_assets"
        )

        if (assets != null) {
            Build.SUPPORTED_ABIS.forEach { abi ->
                val exact = assets.optString(abi)
                if (exact.startsWith("http")) {
                    return exact
                }

                val normalized = when {
                    abi.contains("arm64", true) ->
                        assets.optString("arm64-v8a")

                    abi.contains("armeabi", true) ||
                        abi.contains("v7a", true) ->
                        assets.optString(
                            "armeabi-v7a"
                        )

                    else -> ""
                }

                if (normalized.startsWith("http")) {
                    return normalized
                }
            }

            listOf(
                "universal",
                "arm64-v8a",
                "armeabi-v7a",
                "other",
            ).forEach { key ->
                val value = assets.optString(key)
                if (value.startsWith("http")) {
                    return value
                }
            }
        }

        return config.optString("apk_url")
    }

    private fun compareVersions(
        left: String,
        right: String,
    ): Int {
        val leftParts = left
            .split(".")
            .map {
                it.filter(Char::isDigit)
                    .toIntOrNull() ?: 0
            }

        val rightParts = right
            .split(".")
            .map {
                it.filter(Char::isDigit)
                    .toIntOrNull() ?: 0
            }

        val size = maxOf(
            leftParts.size,
            rightParts.size,
        )

        for (index in 0 until size) {
            val l = leftParts.getOrElse(index) { 0 }
            val r = rightParts.getOrElse(index) { 0 }

            if (l != r) {
                return l.compareTo(r)
            }
        }

        return 0
    }
}
