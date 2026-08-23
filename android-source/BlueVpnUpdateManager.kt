package com.v2ray.ang.bluevpn

import android.app.Activity
import android.app.Dialog
import android.app.PendingIntent
import android.content.ClipData
import android.content.Context
import android.content.Intent
import android.content.pm.PackageInfo
import android.content.pm.PackageInstaller
import android.content.pm.PackageManager
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
import android.os.Handler
import android.os.Looper
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
import java.security.MessageDigest
import java.util.zip.ZipFile

object BlueVpnUpdateManager {
    private data class ApkAsset(
        val url: String,
        val sha256: String = "",
        val sizeBytes: Long = 0L,
    )

    private const val PREFS = "bluevpn_update"
    private const val KEY_LAST_CHECK = "last"
    private const val KEY_MAINTENANCE = "remote_maintenance"
    private const val KEY_FORCE_BLOCK = "remote_force_block"
    private const val KEY_SUPPORT_URL = "remote_support_url"
    private const val KEY_UPDATE_URL = "remote_update_url"
    private const val KEY_UPDATE_VERSION = "remote_update_version"
    private const val KEY_UPDATE_SHA256 = "remote_update_sha256"
    private const val KEY_UPDATE_SIZE = "remote_update_size"
    private const val KEY_AUTO_UPDATE = "remote_auto_update"
    private const val KEY_RELEASE_CHANNEL = "remote_release_channel"
    private const val KEY_BETA_TESTER = "remote_beta_tester"
    private const val KEY_DOWNLOAD_ID = "download_id"
    private const val KEY_DOWNLOAD_VERSION = "download_version"
    private const val KEY_PENDING_INSTALL_URI = "pending_install_uri"
    private const val KEY_DOWNLOADED_FILE = "downloaded_file"
    private const val KEY_SHOWN_ANNOUNCEMENT = "shown_announcement"
    private const val KEY_INSTALLED_VERSION = "installed_version"
    private const val KEY_INSTALLED_CODE = "installed_code"
    private const val KEY_INSTALL_PROMPTED_VERSION = "install_prompted_version"
    private const val KEY_INSTALL_SESSION_ID = "install_session_id"
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
                // Use the canonical account HTTP pipeline instead of a second
                // ad-hoc connection. This guarantees Beta checks are authenticated
                // and can transparently refresh an expired access token.
                BlueVpnAccountManager.mobileConfig(
                    activity,
                    force = force,
                ).getOrThrow()
            }.onSuccess { firstConfig ->
                var config = firstConfig

                // refresh=true intentionally queues the WordPress/GitHub release
                // refresh in the background. A manual Beta check used to read the
                // old MySQL snapshot immediately and incorrectly say "latest".
                // Give that bounded background refresh one chance to converge, then
                // re-fetch before presenting the final result to the tester.
                if (
                    force &&
                    !configHasUpdate(config) &&
                    config.optBoolean("release_refresh_forced", false) &&
                    config.optString("release_refresh_mode") == "background_cache_first"
                ) {
                    repeat(2) {
                        if (configHasUpdate(config)) return@repeat
                        runCatching {
                            Thread.sleep(3_000L)
                            BlueVpnAccountManager.mobileConfig(
                                activity,
                                force = true,
                            ).getOrThrow()
                        }.onSuccess { refreshed ->
                            config = refreshed
                        }
                    }
                }

                val finalConfig = config
                activity.runOnUiThread {
                    val finalUpdateFound = applyRemoteConfig(
                        activity,
                        finalConfig,
                    )

                    if (
                        showStatus &&
                        !finalUpdateFound &&
                        !finalConfig.optBoolean(
                            "maintenance",
                            false,
                        )
                    ) {
                        val channel = finalConfig.optString(
                            "release_channel",
                            "stable",
                        ).lowercase()
                        val betaTester = finalConfig.optBoolean(
                            "beta_tester",
                            false,
                        )
                        val suffix = if (channel == "beta" && betaTester) {
                            " • کانال Beta"
                        } else {
                            ""
                        }
                        Toast.makeText(
                            activity,
                            "نسخه ${BuildConfig.VERSION_NAME} آخرین نسخه$suffix است",
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
                if (BlueVpnStorePolicy.isGooglePlayBuild()) {
                    showGooglePlayUpdateDialog(
                        activity,
                        preferences.getString(KEY_UPDATE_VERSION, "").orEmpty(),
                        forced = true,
                    )
                } else {
                    showForcedUpdateDialog(
                        activity,
                        preferences.getString(KEY_UPDATE_URL, "").orEmpty(),
                        preferences.getString(KEY_UPDATE_VERSION, "").orEmpty(),
                    )
                }
                true
            }

            else -> false
        }
    }

    fun resumePendingInstall(
        activity: Activity,
    ) {
        reconcileInstalledVersion(activity)
        if (!BlueVpnStorePolicy.allowPackageInstallerUpdates()) {
            clearDownloadState(activity, activity.getSharedPreferences(PREFS, Context.MODE_PRIVATE), removeDownload = true)
            return
        }

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

    private fun configHasUpdate(config: JSONObject): Boolean {
        val latestCode = config.optInt("latest_version_code", 0)
        val latestVersion = config.optString("latest_version", "")
        val apkUrl = selectApkAsset(config).url
        val latestHasSemanticVersion =
            latestVersion.any(Char::isDigit) && latestVersion.contains(".")
        val newer = if (latestHasSemanticVersion) {
            compareVersions(latestVersion, BuildConfig.VERSION_NAME) > 0
        } else {
            latestCode > BuildConfig.VERSION_CODE
        }
        return newer && (BlueVpnStorePolicy.isGooglePlayBuild() || apkUrl.startsWith("http"))
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
        val apkAsset = selectApkAsset(config)
        val apkUrl = apkAsset.url
        val autoUpdate = config.optBoolean(
            "auto_update",
            true,
        )
        val releaseChannel = config.optString(
            "release_channel",
            "stable",
        ).trim().lowercase().let { if (it == "beta") "beta" else "stable" }
        val betaTester = config.optBoolean(
            "beta_tester",
            false,
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
                (BlueVpnStorePolicy.isGooglePlayBuild() || apkUrl.startsWith("http"))

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
            .putString(KEY_RELEASE_CHANNEL, releaseChannel)
            .putBoolean(KEY_BETA_TESTER, betaTester)
            .putString(KEY_SUPPORT_URL, supportUrl)
            .putString(KEY_UPDATE_URL, if (BlueVpnStorePolicy.isGooglePlayBuild()) "" else apkUrl)
            .putString(KEY_UPDATE_VERSION, latestVersion)
            .putString(KEY_UPDATE_SHA256, apkAsset.sha256)
            .putLong(KEY_UPDATE_SIZE, apkAsset.sizeBytes)
            .apply()

        if (BlueVpnStorePolicy.isGooglePlayBuild()) {
            when {
                maintenance -> showMaintenanceDialog(activity, supportUrl)
                updateAvailable -> showGooglePlayUpdateDialog(activity, latestVersion, forced)
                else -> showAnnouncementIfNeeded(activity, config)
            }
            return updateAvailable
        }

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

    private fun showGooglePlayUpdateDialog(
        activity: Activity,
        latestVersion: String,
        forced: Boolean,
    ) {
        val versionLabel = latestVersion.takeIf { it.isNotBlank() } ?: "جدید"
        showPremiumDialog(
            activity = activity,
            eyebrow = "GOOGLE PLAY",
            title = "بروزرسانی BlueVPN $versionLabel",
            message = if (forced) {
                "برای ادامه استفاده، نسخه جدید را از Google Play نصب کنید. BlueVPN در نسخه فروشگاهی هیچ APK خارجی دانلود یا نصب نمی‌کند."
            } else {
                "نسخه جدید BlueVPN در Google Play در دسترس است. بروزرسانی فقط توسط فروشگاه انجام می‌شود."
            },
            accentColor = Color.parseColor("#38BDF8"),
            primaryText = "باز کردن Google Play",
            secondaryText = if (forced) null else "بعداً",
            cancelable = !forced,
            onPrimary = { BlueVpnStorePolicy.openGooglePlay(activity) },
        )
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

private fun updateChannel(activity: Activity): String =
    activity.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        .getString(KEY_RELEASE_CHANNEL, "stable")
        .orEmpty()
        .lowercase()
        .let { if (it == "beta") "beta" else "stable" }

private fun updateEyebrow(activity: Activity, stableText: String): String =
    if (updateChannel(activity) == "beta") "🧪 Beta • $stableText" else stableText

private fun showForcedUpdateDialog(
    activity: Activity,
    apkUrl: String,
    version: String,
) {
    showPremiumDialog(
        activity = activity,
        eyebrow = updateEyebrow(activity, "بروزرسانی ضروری"),
        title = if (updateChannel(activity) == "beta") "نسخه آزمایشی $version آماده نصب است" else "نسخه $version آماده نصب است",
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
        eyebrow = updateEyebrow(activity, "نسخه تازه"),
        title = if (updateChannel(activity) == "beta") "BlueVPN Beta $version آماده است" else "BlueVPN $version آماده است",
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
        eyebrow = updateEyebrow(activity, "پیشنهاد بروزرسانی"),
        title = config.optString(
            "update_title",
            if (updateChannel(activity) == "beta") "نسخه آزمایشی $version منتشر شد" else "نسخه $version منتشر شد",
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
    val expectedSha256 = preferences.getString(
        KEY_UPDATE_SHA256,
        "",
    ).orEmpty().lowercase()
    val expectedSize = preferences.getLong(
        KEY_UPDATE_SIZE,
        0L,
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

    // Keep the staged APK in app-private storage. The package installer
    // receives the bytes through a PackageInstaller session, so OEM file URI
    // parsers never need to read an APK from external storage.
    val directory = File(
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
            )

            val responseCode = connection!!.responseCode
            if (responseCode !in 200..299) {
                error("HTTP $responseCode")
            }

            val contentType = connection!!.contentType
                .orEmpty()
                .substringBefore(';')
                .trim()
                .lowercase()
            if (
                contentType.startsWith("text/") ||
                contentType.contains("json") ||
                contentType.contains("html")
            ) {
                error("APK_INVALID: پاسخ سرور فایل APK نبود ($contentType)")
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
            if (total > 0L && downloaded != total) {
                error("APK_INVALID: دانلود ناقص بود ($downloaded از $total بایت)")
            }
            if (expectedSize > 0L && downloaded != expectedSize) {
                error("APK_INVALID: اندازه فایل با نسخه منتشرشده مطابقت ندارد")
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

            validateDownloadedApk(
                activity.applicationContext,
                target,
                version,
                expectedSha256,
                expectedSize,
            )?.let { validationError ->
                target.delete()
                error("APK_INVALID: $validationError")
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
                        message = friendlyDownloadError(error) +
                            "\n\nBlueVPN ابتدا مسیر مستقیم دستگاه و سپس مسیر عادی را امتحان می‌کند؛ اتصال VPN لازم نیست قطع شود.",
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

private fun configureDownloadConnection(
    connection: HttpURLConnection,
): HttpURLConnection = connection.apply {
    connectTimeout = 15_000
    readTimeout = 35_000
    instanceFollowRedirects = true
    useCaches = false
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

    if (physicalNetwork != null) {
        val directConnection = runCatching {
            configureDownloadConnection(
                physicalNetwork.openConnection(
                    target
                ) as HttpURLConnection
            )
        }.getOrNull()

        if (directConnection != null) {
            try {
                // Force socket creation here. Some Android/OEM builds reject
                // binding the updater socket to the underlying network with
                // EPERM while the app VPN is active.
                directConnection.connect()
                directConnection.responseCode
                return directConnection
            } catch (error: Throwable) {
                directConnection.disconnect()
                if (!shouldFallbackToDefaultNetwork(error)) {
                    throw error
                }
            }
        }
    }

    // Reliable fallback: use the app's current default route. When BlueVPN is
    // connected this normally goes through the tunnel, so the VPN does not
    // need to be disconnected just to install an update.
    return configureDownloadConnection(
        target.openConnection() as HttpURLConnection
    ).also { fallback ->
        fallback.connect()
        fallback.responseCode
    }
}

private fun shouldFallbackToDefaultNetwork(
    error: Throwable,
): Boolean {
    var current: Throwable? = error
    while (current != null) {
        if (
            current is java.io.IOException ||
            current is SecurityException ||
            current is android.system.ErrnoException
        ) {
            return true
        }

        val message = current.message.orEmpty().lowercase()
        if (
            "binding socket to network" in message ||
            "eperm" in message ||
            "operation not permitted" in message
        ) {
            return true
        }

        current = current.cause
    }
    return false
}

private fun friendlyDownloadError(
    error: Throwable,
): String {
    var current: Throwable? = error
    val messages = mutableListOf<String>()

    while (current != null) {
        current.message
            ?.trim()
            ?.takeIf { it.isNotBlank() }
            ?.let(messages::add)
        current = current.cause
    }

    val combined = messages.joinToString(" | ")
    val normalized = combined.lowercase()

    return when {
        "apk_invalid:" in normalized ->
            combined.substringAfter("APK_INVALID:", combined).trim()

        "binding socket to network" in normalized ||
            "eperm" in normalized ||
            "operation not permitted" in normalized ->
            "اندروید اجازه استفاده از مسیر مستقیم شبکه را نداد و مسیر جایگزین نیز برقرار نشد."

        "timed out" in normalized ||
            "timeout" in normalized ->
            "ارتباط با سرور بروزرسانی بیش از حد طول کشید."

        "unable to resolve host" in normalized ||
            "unknownhost" in normalized ->
            "نام سرور بروزرسانی از طریق اینترنت فعلی پیدا نشد."

        combined.isNotBlank() -> combined
        else -> "ارتباط با سرور بروزرسانی قطع شد."
    }
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

    val validationError = validateDownloadedApk(
        context,
        file,
        targetVersion,
        preferences.getString(KEY_UPDATE_SHA256, "").orEmpty(),
        preferences.getLong(KEY_UPDATE_SIZE, 0L),
    )
    if (validationError != null) {
        file.delete()
        preferences.edit()
            .remove(KEY_DOWNLOADED_FILE)
            .remove(KEY_PENDING_INSTALL_URI)
            .remove(KEY_INSTALL_PROMPTED_VERSION)
            .apply()
        Toast.makeText(
            context,
            "فایل بروزرسانی سالم نبود و حذف شد؛ دوباره دانلود کنید: $validationError",
            Toast.LENGTH_LONG,
        ).show()
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

    installWithPackageInstaller(
        context,
        file,
        targetVersion,
    )
}

private fun installWithPackageInstaller(
    context: Context,
    file: File,
    targetVersion: String,
) {
    Thread {
        var sessionId = -1
        runCatching {
            val packageInstaller =
                context.packageManager.packageInstaller
            val params = PackageInstaller.SessionParams(
                PackageInstaller.SessionParams.MODE_FULL_INSTALL,
            ).apply {
                setAppPackageName(context.packageName)
                setSize(file.length())
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                    setRequireUserAction(
                        PackageInstaller.SessionParams.USER_ACTION_REQUIRED,
                    )
                }
            }

            sessionId = packageInstaller.createSession(params)
            packageInstaller.openSession(sessionId).use { session ->
                session.openWrite(
                    "base.apk",
                    0L,
                    file.length(),
                ).use { output ->
                    file.inputStream().buffered(128 * 1024).use { input ->
                        input.copyTo(output, 128 * 1024)
                    }
                    session.fsync(output)
                }

                val callback = Intent(
                    context,
                    com.v2ray.ang.ui.BlueVpnUpdateInstallActivity::class.java,
                ).apply {
                    action =
                        "com.v2ray.ang.bluevpn.UPDATE_INSTALL_STATUS"
                    putExtra(
                        PackageInstaller.EXTRA_SESSION_ID,
                        sessionId,
                    )
                    putExtra(
                        KEY_DOWNLOAD_VERSION,
                        targetVersion,
                    )
                    addFlags(
                        Intent.FLAG_ACTIVITY_NEW_TASK or
                            Intent.FLAG_ACTIVITY_CLEAR_TOP or
                            Intent.FLAG_ACTIVITY_SINGLE_TOP,
                    )
                }
                val pendingFlags =
                    PendingIntent.FLAG_UPDATE_CURRENT or
                        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                            PendingIntent.FLAG_MUTABLE
                        } else {
                            0
                        }
                val statusReceiver = PendingIntent.getActivity(
                    context,
                    sessionId,
                    callback,
                    pendingFlags,
                )

                context.getSharedPreferences(
                    PREFS,
                    Context.MODE_PRIVATE,
                ).edit()
                    .putInt(KEY_INSTALL_SESSION_ID, sessionId)
                    .apply()

                session.commit(statusReceiver.intentSender)
            }
        }.onFailure { error ->
            if (sessionId > 0) {
                runCatching {
                    context.packageManager.packageInstaller
                        .abandonSession(sessionId)
                }
            }
            Handler(Looper.getMainLooper()).post {
                // Last-resort compatibility path for heavily customized OEM
                // installers. The primary path above never exposes the APK as
                // a URI and therefore avoids package parser read failures.
                launchLegacyInstaller(context, file)
                Toast.makeText(
                    context,
                    "روش نصب سازگار اندروید باز شد: " +
                        (error.message ?: "خطای آماده‌سازی نصب"),
                    Toast.LENGTH_LONG,
                ).show()
            }
        }
    }.apply {
        name = "BlueVPN-PackageInstaller"
        isDaemon = true
        start()
    }
}

fun handlePackageInstallerStatus(
    activity: Activity,
    intent: Intent,
) {
    val status = intent.getIntExtra(
        PackageInstaller.EXTRA_STATUS,
        PackageInstaller.STATUS_FAILURE,
    )
    val preferences = activity.getSharedPreferences(
        PREFS,
        Context.MODE_PRIVATE,
    )

    when (status) {
        PackageInstaller.STATUS_PENDING_USER_ACTION -> {
            val confirmation = if (
                Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU
            ) {
                intent.getParcelableExtra(
                    Intent.EXTRA_INTENT,
                    Intent::class.java,
                )
            } else {
                @Suppress("DEPRECATION")
                intent.getParcelableExtra<Intent>(
                    Intent.EXTRA_INTENT,
                )
            }

            if (confirmation != null) {
                runCatching {
                    activity.startActivity(confirmation)
                }.onFailure { error ->
                    Toast.makeText(
                        activity,
                        "صفحه تأیید نصب باز نشد: " +
                            (error.message ?: "خطای اندروید"),
                        Toast.LENGTH_LONG,
                    ).show()
                }
            } else {
                Toast.makeText(
                    activity,
                    "اندروید صفحه تأیید نصب را برنگرداند",
                    Toast.LENGTH_LONG,
                ).show()
            }
            activity.finish()
        }

        PackageInstaller.STATUS_SUCCESS -> {
            clearDownloadState(
                activity,
                preferences,
                removeDownload = true,
            )
            preferences.edit()
                .remove(KEY_INSTALL_SESSION_ID)
                .apply()
            Toast.makeText(
                activity,
                "بروزرسانی BlueVPN با موفقیت نصب شد",
                Toast.LENGTH_LONG,
            ).show()
            activity.finish()
        }

        else -> {
            val detail = intent.getStringExtra(
                PackageInstaller.EXTRA_STATUS_MESSAGE,
            ).orEmpty()
            val userMessage = when (status) {
                PackageInstaller.STATUS_FAILURE_INVALID ->
                    "فایل بروزرسانی توسط اندروید نامعتبر تشخیص داده شد"
                PackageInstaller.STATUS_FAILURE_INCOMPATIBLE ->
                    "این نسخه با دستگاه شما سازگار نیست"
                PackageInstaller.STATUS_FAILURE_CONFLICT ->
                    "امضا یا نسخه نصب‌شده با بروزرسانی سازگار نیست"
                PackageInstaller.STATUS_FAILURE_STORAGE ->
                    "فضای کافی برای نصب بروزرسانی وجود ندارد"
                PackageInstaller.STATUS_FAILURE_ABORTED ->
                    "نصب بروزرسانی لغو شد"
                PackageInstaller.STATUS_FAILURE_BLOCKED ->
                    "اندروید نصب بروزرسانی را مسدود کرد"
                else -> "نصب بروزرسانی انجام نشد"
            }
            preferences.edit()
                .remove(KEY_INSTALL_PROMPTED_VERSION)
                .remove(KEY_INSTALL_SESSION_ID)
                .apply()
            Toast.makeText(
                activity,
                if (detail.isBlank()) userMessage
                else "$userMessage: $detail",
                Toast.LENGTH_LONG,
            ).show()
            activity.finish()
        }
    }
}

private fun launchLegacyInstaller(
    context: Context,
    file: File,
) {
    val uri = FileProvider.getUriForFile(
        context,
        context.packageName + ".bluevpn.updateprovider",
        file,
    )
    launchInstaller(context, uri)
}

private fun launchInstaller(
    context: Context,
    uri: Uri,
) {
    val flags =
        Intent.FLAG_GRANT_READ_URI_PERMISSION or
            Intent.FLAG_GRANT_WRITE_URI_PERMISSION or
            Intent.FLAG_ACTIVITY_NEW_TASK

    fun installerIntent(action: String): Intent =
        Intent(action).apply {
            setDataAndType(
                uri,
                "application/vnd.android.package-archive",
            )
            clipData = ClipData.newRawUri(
                "BlueVPN update",
                uri,
            )
            addFlags(flags)
        }

    val primary = installerIntent(
        Intent.ACTION_INSTALL_PACKAGE
    )
    val fallback = installerIntent(Intent.ACTION_VIEW)
    val packageManager = context.packageManager

    val handlers = (
        packageManager.queryIntentActivities(
            primary,
            PackageManager.MATCH_DEFAULT_ONLY,
        ) + packageManager.queryIntentActivities(
            fallback,
            PackageManager.MATCH_DEFAULT_ONLY,
        )
        ).mapNotNull { it.activityInfo?.packageName }
        .toSet()

    handlers.forEach { installerPackage ->
        runCatching {
            context.grantUriPermission(
                installerPackage,
                uri,
                Intent.FLAG_GRANT_READ_URI_PERMISSION or
                    Intent.FLAG_GRANT_WRITE_URI_PERMISSION,
            )
        }
    }

    runCatching {
        context.startActivity(primary)
    }.recoverCatching {
        context.startActivity(fallback)
    }.getOrElse { error ->
        Toast.makeText(
            context,
            "نصب‌کننده اندروید باز نشد: ${error.message ?: "خطای ناشناخته"}",
            Toast.LENGTH_LONG,
        ).show()
    }
}

private fun validateDownloadedApk(
    context: Context,
    file: File,
    expectedVersion: String,
    expectedSha256: String,
    expectedSize: Long,
): String? {
    if (!file.isFile || file.length() < 1024L) {
        return "فایل ناقص یا خالی است"
    }
    if (expectedSize > 0L && file.length() != expectedSize) {
        return "اندازه فایل با نسخه منتشرشده مطابقت ندارد"
    }

    val hasManifest = runCatching {
        ZipFile(file).use { zip ->
            zip.getEntry("AndroidManifest.xml") != null &&
                zip.getEntry("classes.dex") != null
        }
    }.getOrDefault(false)
    if (!hasManifest) {
        return "فایل دریافت‌شده یک APK معتبر نیست"
    }

    val normalizedExpectedHash = expectedSha256
        .substringAfter("sha256:", expectedSha256)
        .trim()
        .lowercase()
    if (normalizedExpectedHash.matches(Regex("[0-9a-f]{64}"))) {
        val actualHash = MessageDigest.getInstance("SHA-256").let { digest ->
            file.inputStream().buffered(128 * 1024).use { input ->
                val buffer = ByteArray(128 * 1024)
                while (true) {
                    val count = input.read(buffer)
                    if (count < 0) break
                    digest.update(buffer, 0, count)
                }
            }
            digest.digest().joinToString("") { byte ->
                "%02x".format(byte)
            }
        }
        if (actualHash != normalizedExpectedHash) {
            return "اثر انگشت فایل با GitHub Release یکسان نیست"
        }
    }

    val archiveInfo = packageArchiveInfoWithSignatures(
        context,
        file,
    ) ?: return "اندروید نتوانست اطلاعات بسته را بخواند"

    if (archiveInfo.packageName != context.packageName) {
        return "نام بسته با BlueVPN نصب‌شده مطابقت ندارد"
    }
    val archiveVersion = archiveInfo.versionName.orEmpty()
    if (
        expectedVersion.isNotBlank() &&
        archiveVersion.isNotBlank() &&
        compareVersions(archiveVersion, expectedVersion) != 0
    ) {
        return "نسخه داخل فایل ($archiveVersion) با نسخه اعلام‌شده ($expectedVersion) متفاوت است"
    }

    val installedInfo = installedPackageInfoWithSignatures(context)
        ?: return "اطلاعات نسخه نصب‌شده قابل بررسی نیست"
    val archiveCode = packageVersionCode(archiveInfo)
    val installedCode = packageVersionCode(installedInfo)
    if (archiveCode <= installedCode) {
        return "کد نسخه بروزرسانی باید از نسخه نصب‌شده بالاتر باشد"
    }

    val currentSigners = signingCertificateDigests(installedInfo)
    val updateSigners = signingCertificateDigests(archiveInfo)
    if (
        currentSigners.isNotEmpty() &&
        updateSigners.isNotEmpty() &&
        currentSigners.intersect(updateSigners).isEmpty()
    ) {
        return "امضای بروزرسانی با نسخه نصب‌شده یکسان نیست"
    }
    return null
}

private fun packageArchiveInfoWithSignatures(
    context: Context,
    file: File,
): PackageInfo? {
    val flags = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
        PackageManager.GET_SIGNING_CERTIFICATES
    } else {
        @Suppress("DEPRECATION")
        PackageManager.GET_SIGNATURES
    }
    return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
        context.packageManager.getPackageArchiveInfo(
            file.absolutePath,
            PackageManager.PackageInfoFlags.of(flags.toLong()),
        )
    } else {
        @Suppress("DEPRECATION")
        context.packageManager.getPackageArchiveInfo(
            file.absolutePath,
            flags,
        )
    }
}

private fun installedPackageInfoWithSignatures(
    context: Context,
): PackageInfo? = runCatching {
    val flags = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
        PackageManager.GET_SIGNING_CERTIFICATES
    } else {
        @Suppress("DEPRECATION")
        PackageManager.GET_SIGNATURES
    }
    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
        context.packageManager.getPackageInfo(
            context.packageName,
            PackageManager.PackageInfoFlags.of(flags.toLong()),
        )
    } else {
        @Suppress("DEPRECATION")
        context.packageManager.getPackageInfo(
            context.packageName,
            flags,
        )
    }
}.getOrNull()

private fun packageVersionCode(info: PackageInfo): Long =
    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
        info.longVersionCode
    } else {
        @Suppress("DEPRECATION")
        info.versionCode.toLong()
    }

private fun signingCertificateDigests(
    info: PackageInfo,
): Set<String> {
    val signatures = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
        val signing = info.signingInfo ?: return emptySet()
        if (signing.hasMultipleSigners()) {
            signing.apkContentsSigners
        } else {
            signing.signingCertificateHistory
        }
    } else {
        @Suppress("DEPRECATION")
        info.signatures.orEmpty()
    }

    return signatures.map { signature ->
        MessageDigest.getInstance("SHA-256")
            .digest(signature.toByteArray())
            .joinToString("") { byte -> "%02x".format(byte) }
    }.toSet()
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
        .remove(KEY_INSTALL_SESSION_ID)
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
        .remove(KEY_UPDATE_SHA256)
        .remove(KEY_UPDATE_SIZE)
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
        .remove(KEY_UPDATE_SHA256)
        .remove(KEY_UPDATE_SIZE)
        .remove(KEY_AUTO_DIALOG_VERSION)
        .remove(KEY_OPTIONAL_SNOOZE_VERSION)
        .remove(KEY_OPTIONAL_SNOOZE_UNTIL)
        .apply()
}


    private fun selectApkAsset(
        config: JSONObject,
    ): ApkAsset {
        val assets = config.optJSONObject("apk_assets")
        val metadata = config.optJSONObject("apk_asset_meta")

        fun assetFor(key: String): ApkAsset? {
            val url = assets?.optString(key).orEmpty()
            if (!url.startsWith("http")) return null
            val meta = metadata?.optJSONObject(key)
            return ApkAsset(
                url = url,
                sha256 = meta?.optString("sha256").orEmpty(),
                sizeBytes = meta?.optLong("size", 0L) ?: 0L,
            )
        }

        Build.SUPPORTED_ABIS.forEach { abi ->
            assetFor(abi)?.let { return it }
            val normalized = when {
                abi.contains("arm64", true) -> "arm64-v8a"
                abi.contains("armeabi", true) ||
                    abi.contains("v7a", true) -> "armeabi-v7a"
                else -> ""
            }
            if (normalized.isNotBlank()) {
                assetFor(normalized)?.let { return it }
            }
        }

        listOf(
            "universal",
            "arm64-v8a",
            "armeabi-v7a",
            "other",
        ).forEach { key ->
            assetFor(key)?.let { return it }
        }

        return ApkAsset(config.optString("apk_url"))
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
