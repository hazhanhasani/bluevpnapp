package com.v2ray.ang.bluevpn

import android.content.Context
import android.os.Build
import android.provider.Settings
import com.v2ray.ang.BuildConfig
import com.v2ray.ang.dto.entities.SubscriptionItem
import com.v2ray.ang.handler.AngConfigManager
import com.v2ray.ang.handler.MmkvManager
import org.json.JSONArray
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.security.MessageDigest
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Locale
import java.util.TimeZone
import java.util.UUID

data class BlueVpnAccountSnapshot(
    val email: String,
    val subscriptionActive: Boolean,
    val subscriptionUrl: String,
    val status: String,
    val expire: String?,
    val expireFa: String?,
    val dataLimitBytes: Long,
    val usedTrafficBytes: Long,
    val deviceLimit: Int,
    val syncError: String
)

object BlueVpnPersianDate {
    private val tehran = TimeZone.getTimeZone("Asia/Tehran")
    private val persianDigits = mapOf(
        '0' to '۰', '1' to '۱', '2' to '۲', '3' to '۳', '4' to '۴',
        '5' to '۵', '6' to '۶', '7' to '۷', '8' to '۸', '9' to '۹',
    )

    fun formatIso(raw: String?, includeTime: Boolean = true): String? {
        val value = raw?.trim().orEmpty()
        if (value.isBlank() || value == "null") return null
        if (value.startsWith("9999-")) return "نامحدود"
        val normalized = value.replace(
            Regex("\\.(\\d{3})\\d+([+-]\\d{2}:\\d{2}|Z)$"),
            ".$1$2",
        )
        val patterns = listOf(
            "yyyy-MM-dd'T'HH:mm:ss.SSSXXX",
            "yyyy-MM-dd'T'HH:mm:ssXXX",
            "yyyy-MM-dd'T'HH:mm:ss.SSS'Z'",
            "yyyy-MM-dd'T'HH:mm:ss'Z'",
            "yyyy-MM-dd",
        )
        val millis = patterns.firstNotNullOfOrNull { pattern ->
            runCatching {
                SimpleDateFormat(pattern, Locale.US).apply {
                    timeZone = TimeZone.getTimeZone("UTC")
                    isLenient = false
                }.parse(normalized)?.time
            }.getOrNull()
        } ?: return null
        val calendar = Calendar.getInstance(tehran).apply { timeInMillis = millis }
        val (jy, jm, jd) = gregorianToJalali(
            calendar.get(Calendar.YEAR),
            calendar.get(Calendar.MONTH) + 1,
            calendar.get(Calendar.DAY_OF_MONTH),
        )
        val date = "%04d/%02d/%02d".format(Locale.US, jy, jm, jd)
        val result = if (includeTime) {
            val time = "%02d:%02d".format(
                Locale.US,
                calendar.get(Calendar.HOUR_OF_DAY),
                calendar.get(Calendar.MINUTE),
            )
            "$date، ساعت $time"
        } else {
            date
        }
        return result.map { persianDigits[it] ?: it }.joinToString("")
    }

    private fun gregorianToJalali(gy: Int, gm: Int, gd: Int): Triple<Int, Int, Int> {
        val gDays = intArrayOf(0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334)
        val gy2 = if (gm > 2) gy + 1 else gy
        var days = 355666 + 365 * gy + (gy2 + 3) / 4 -
            (gy2 + 99) / 100 + (gy2 + 399) / 400 + gd + gDays[gm - 1]
        var jy = -1595 + 33 * (days / 12053)
        days %= 12053
        jy += 4 * (days / 1461)
        days %= 1461
        if (days > 365) {
            jy += (days - 1) / 365
            days = (days - 1) % 365
        }
        val jm: Int
        val jd: Int
        if (days < 186) {
            jm = 1 + days / 31
            jd = 1 + days % 31
        } else {
            jm = 7 + (days - 186) / 30
            jd = 1 + (days - 186) % 30
        }
        return Triple(jy, jm, jd)
    }
}

object BlueVpnAccountManager {
    private val refreshLock = Any()

    private const val P = "bluevpn_account"
    private const val BACKUP = "bluevpn_auth_backup"
    private const val SUB = "BlueVPN Account"
    private const val AUTO_SYNC_INTERVAL_MS = 5 * 60_000L

    private fun prefs(c: Context) =
        c.getSharedPreferences(P, Context.MODE_PRIVATE)

    private fun backup(c: Context) =
        c.getSharedPreferences(BACKUP, Context.MODE_PRIVATE)

    private class ApiException(
        val status: Int,
        val code: String,
        message: String,
    ) : Exception(message)

    fun apiBaseUrl() =
        BuildConfig.BLUEVPN_API_BASE_URL.trimEnd('/')

    private fun restorePrimary(c: Context) {
        val primary = prefs(c)
        val secondary = backup(c)

        if (primary.getString("token", "").orEmpty().isBlank()) {
            val saved = secondary.getString("token", "").orEmpty()
            if (saved.isNotBlank()) {
                primary.edit().putString("token", saved).commit()
            }
        }

        if (primary.getString("refresh_token", "").orEmpty().isBlank()) {
            val saved =
                secondary.getString("refresh_token", "").orEmpty()
            if (saved.isNotBlank()) {
                primary.edit()
                    .putString("refresh_token", saved)
                    .commit()
            }
        }

        if (primary.getString("email", "").orEmpty().isBlank()) {
            val saved = secondary.getString("email", "").orEmpty()
            if (saved.isNotBlank()) {
                primary.edit().putString("email", saved).commit()
            }
        }

        if (primary.getString("device_id", "").orEmpty().isBlank()) {
            val saved =
                secondary.getString("device_id", "").orEmpty()
            if (saved.isNotBlank()) {
                primary.edit()
                    .putString("device_id", saved)
                    .commit()
            }
        }
    }

    private fun persistAuth(
        c: Context,
        token: String,
        refreshToken: String,
        email: String,
    ) {
        val device = deviceId(c)

        prefs(c).edit()
            .putString("token", token)
            .putString("refresh_token", refreshToken)
            .putString("email", email)
            .putString("device_id", device)
            .remove("auth_error")
            .commit()

        backup(c).edit()
            .putString("token", token)
            .putString("refresh_token", refreshToken)
            .putString("email", email)
            .putString("device_id", device)
            .putLong("saved_at", System.currentTimeMillis())
            .commit()
    }

    fun token(c: Context): String {
        restorePrimary(c)
        return prefs(c).getString("token", "").orEmpty()
    }

    private fun refreshToken(c: Context): String {
        restorePrimary(c)
        return prefs(c).getString(
            "refresh_token",
            ""
        ).orEmpty()
    }

    fun hasSession(c: Context): Boolean =
        token(c).isNotBlank() || refreshToken(c).isNotBlank()

    fun active(c: Context) =
        prefs(c).getBoolean("active", false)

    fun pendingOrder(c: Context) =
        prefs(c).getString("pending_order", "").orEmpty()

    fun setPendingOrder(c: Context, id: String) =
        prefs(c).edit().putString("pending_order", id).apply()

    fun clearPendingOrder(c: Context) =
        setPendingOrder(c, "")

    fun isDeletedOrderError(error: Throwable): Boolean =
        error is ApiException &&
            (error.status == 404 || error.status == 410) &&
            error.code in setOf("ORDER_NOT_FOUND", "ORDER_GONE", "HTTP_404", "HTTP_410")

    fun markCheckoutBrowserOpen(c: Context, id: String) {
        prefs(c).edit()
            .putString("checkout_browser_order", id)
            .putLong("checkout_browser_opened_at", System.currentTimeMillis())
            .commit()
    }

    fun checkoutBrowserOrder(c: Context): String =
        prefs(c).getString(
            "checkout_browser_order",
            "",
        ).orEmpty()

    fun clearCheckoutBrowserOrder(c: Context) {
        prefs(c).edit()
            .remove("checkout_browser_order")
            .remove("checkout_browser_opened_at")
            .commit()
    }

    fun consumeCheckoutBrowserOrder(c: Context): String {
        val value = checkoutBrowserOrder(c)
        clearCheckoutBrowserOrder(c)
        return value
    }

    fun deviceId(c: Context): String {
        restorePrimary(c)

        val primary = prefs(c)
        val old = primary.getString("device_id", "").orEmpty()
        if (old.isNotBlank()) return old

        val backupId =
            backup(c).getString("device_id", "").orEmpty()
        if (backupId.isNotBlank()) {
            primary.edit()
                .putString("device_id", backupId)
                .commit()
            return backupId
        }

        val androidId = Settings.Secure.getString(
            c.contentResolver,
            Settings.Secure.ANDROID_ID
        ).orEmpty()

        val seed = if (androidId.isBlank()) {
            UUID.randomUUID().toString()
        } else {
            "${c.packageName}:$androidId"
        }

        val id = MessageDigest.getInstance("SHA-256")
            .digest(seed.toByteArray())
            .joinToString("") { "%02x".format(it) }
            .take(40)

        primary.edit().putString("device_id", id).commit()
        backup(c).edit().putString("device_id", id).commit()
        return id
    }

    fun deviceName() =
        "${Build.MANUFACTURER} ${Build.MODEL}".trim()

    private fun parseIsoMillis(raw: String?): Long? {
        val value = raw?.trim().orEmpty()
        if (value.isBlank() || value == "null") return null
        if (value.startsWith("9999-")) return Long.MAX_VALUE
        val normalized = value.replace(
            Regex("\\.(\\d{3})\\d+([+-]\\d{2}:\\d{2}|Z)$"),
            ".$1$2",
        )
        val patterns = listOf(
            "yyyy-MM-dd'T'HH:mm:ss.SSSXXX",
            "yyyy-MM-dd'T'HH:mm:ssXXX",
            "yyyy-MM-dd'T'HH:mm:ss.SSS'Z'",
            "yyyy-MM-dd'T'HH:mm:ss'Z'",
            "yyyy-MM-dd",
        )
        return patterns.firstNotNullOfOrNull { pattern ->
            runCatching {
                SimpleDateFormat(pattern, Locale.US).apply {
                    timeZone = TimeZone.getTimeZone("UTC")
                    isLenient = false
                }.parse(normalized)?.time
            }.getOrNull()
        }
    }

    private fun effectiveSubscriptionActive(
        subscription: JSONObject,
    ): Boolean {
        val url = subscription.optString("url").trim()
        val urlValid = url.startsWith("http://") || url.startsWith("https://")
        val limit = subscription.optLong("data_limit_bytes", 0L)
        val used = subscription.optLong("used_traffic_bytes", 0L)
        val withinTraffic = limit <= 0L || used < limit
        val status = subscription.optString("status", "inactive")
            .trim()
            .lowercase(Locale.US)
        val statusActive = status in setOf("active", "enabled", "on", "valid")
        val terminalStatus = status in setOf(
            "disabled", "expired", "blocked", "banned", "deleted",
            "limited", "revoked", "suspended",
        )
        if (terminalStatus) return false
        val entitlementActive = subscription.optBoolean("entitlement_active", false)
        val unlimited = subscription.optBoolean("unlimited", false) ||
            subscription.optString("expire_mode") == "unlimited" ||
            subscription.optString("expire").startsWith("9999-")
        val skewMillis = subscription.optLong(
            "clock_skew_tolerance_seconds",
            120L,
        ).coerceAtLeast(0L) * 1_000L
        val expiryValid = when {
            unlimited -> true
            subscription.has("remaining_seconds") &&
                !subscription.isNull("remaining_seconds") ->
                subscription.optLong("remaining_seconds", -1L) >=
                    -(skewMillis / 1_000L)
            else -> {
                val expiry = parseIsoMillis(
                    subscription.optString("expire").ifBlank {
                        subscription.optString("expires_at")
                    }
                )
                expiry != null && expiry > System.currentTimeMillis() - skewMillis
            }
        }
        val backendActive = subscription.optBoolean("active", false)
        return urlValid && withinTraffic && expiryValid &&
            (backendActive || statusActive || entitlementActive)
    }

    private fun accountCacheVersion(c: Context): Long =
        prefs(c).getLong("account_app_version", -1L)

    private fun currentAppVersion(): Long = BuildConfig.VERSION_CODE.toLong()

    fun snapshot(c: Context): BlueVpnAccountSnapshot {
        restorePrimary(c)
        val p = prefs(c)

        return BlueVpnAccountSnapshot(
            p.getString("email", "").orEmpty(),
            active(c),
            p.getString("url", "").orEmpty(),
            p.getString("status", "inactive").orEmpty(),
            p.getString("expire", null),
            p.getString("expire_fa", null),
            p.getLong("limit", 0),
            p.getLong("used", 0),
            p.getInt("devices", 1),
            p.getString("sync_error", "").orEmpty(),
        )
    }

    fun logout(c: Context) {
        val id = deviceId(c)

        runCatching {
            request(
                c,
                "POST",
                "/api/v1/auth/logout",
                JSONObject(),
                true,
            )
        }

        prefs(c).edit()
            .clear()
            .putString("device_id", id)
            .commit()

        backup(c).edit()
            .clear()
            .putString("device_id", id)
            .commit()

        c.getSharedPreferences(
            "bluevpn_subscription_info",
            Context.MODE_PRIVATE
        ).edit().clear().apply()
    }

    private fun invalidateSession(
        c: Context,
        code: String,
    ) {
        val id = deviceId(c)
        val email = snapshot(c).email

        prefs(c).edit()
            .remove("token")
            .remove("refresh_token")
            .putString("email", email)
            .putString("device_id", id)
            .putString("auth_error", code)
            .commit()

        backup(c).edit()
            .remove("token")
            .remove("refresh_token")
            .putString("email", email)
            .putString("device_id", id)
            .commit()
    }

    fun authenticate(
        c: Context,
        email: String,
        password: String,
        register: Boolean,
    ): Result<BlueVpnAccountSnapshot> = runCatching {
        val response = request(
            c,
            "POST",
            if (register) {
                "/api/v1/auth/register"
            } else {
                "/api/v1/auth/login"
            },
            JSONObject()
                .put("email", email.trim())
                .put("password", password)
                .put("device_id", deviceId(c))
                .put("device_name", deviceName()),
            false,
        )

        val access = response.optString("token")
        val refresh = response.optString("refresh_token")
        if (access.isBlank()) error(message(response))

        persistAuth(
            c,
            access,
            refresh,
            email.trim(),
        )

        applyAccount(
            c,
            response.getJSONObject("account")
        )
    }

    private fun refreshSession(
        c: Context,
        failedAccessToken: String,
    ): Boolean = synchronized(refreshLock) {
        restorePrimary(c)

        val currentAccess = token(c)

        // Another request may already have refreshed the session while this
        // request was waiting for the lock.
        if (
            failedAccessToken.isNotBlank() &&
            currentAccess.isNotBlank() &&
            currentAccess != failedAccessToken
        ) {
            return@synchronized true
        }

        val refresh = refreshToken(c)
        val email = snapshot(c).email
            .ifBlank {
                backup(c).getString(
                    "email",
                    ""
                ).orEmpty()
            }

        if (refresh.isBlank() || email.isBlank()) {
            return@synchronized false
        }

        try {
            val response = request(
                c,
                "POST",
                "/api/v1/auth/refresh",
                JSONObject()
                    .put("email", email)
                    .put("device_id", deviceId(c))
                    .put("device_name", deviceName())
                    .put("refresh_token", refresh),
                false,
            )

            val access = response.optString("token")
            val newRefresh = response.optString(
                "refresh_token",
                refresh,
            )

            if (access.isBlank()) {
                return@synchronized false
            }

            persistAuth(
                c,
                access,
                newRefresh,
                email,
            )

            response.optJSONObject("account")
                ?.let { applyAccount(c, it) }

            true
        } catch (error: ApiException) {
            val accessChanged =
                token(c).isNotBlank() &&
                    token(c) != failedAccessToken

            val refreshChanged =
                refreshToken(c).isNotBlank() &&
                    refreshToken(c) != refresh

            if (accessChanged || refreshChanged) {
                return@synchronized true
            }

            if (
                error.status == 401 &&
                error.code in setOf(
                    "INVALID_REFRESH",
                    "DEVICE_DISABLED",
                    "ACCOUNT_DISABLED",
                    "REFRESH_REQUIRED",
                )
            ) {
                invalidateSession(c, error.code)
            }

            false
        } catch (_: Exception) {
            // Network, timeout and server errors must never erase login.
            false
        }
    }

    private fun authenticatedRequest(
        c: Context,
        method: String,
        path: String,
        body: JSONObject?,
    ): JSONObject {
        val attemptedAccess = token(c)

        try {
            return request(
                c,
                method,
                path,
                body,
                true,
            )
        } catch (error: ApiException) {
            if (
                error.status == 401 &&
                refreshSession(
                    c,
                    attemptedAccess,
                )
            ) {
                return request(
                    c,
                    method,
                    path,
                    body,
                    true,
                )
            }

            // Only a definitive refresh rejection can clear local login.
            // Timeouts, server errors and simultaneous requests keep it.
            throw error
        }
    }

    fun sync(
        c: Context,
        force: Boolean = false,
    ): Result<BlueVpnAccountSnapshot> = runCatching {
        if (!hasSession(c)) error("AUTH_REQUIRED")

        val last = prefs(c).getLong("last_sync", 0)
        val local = snapshot(c)
        val appUpdated = accountCacheVersion(c) != currentAppVersion()
        val entitlementUnknown =
            !local.subscriptionActive ||
                local.subscriptionUrl.isBlank() ||
                local.status.equals("inactive", ignoreCase = true)
        if (
            !force &&
            !appUpdated &&
            !entitlementUnknown &&
            System.currentTimeMillis() - last <
            AUTO_SYNC_INTERVAL_MS
        ) {
            return@runCatching local
        }

        val response = authenticatedRequest(
            c,
            "POST",
            "/api/v1/account/sync",
            JSONObject(),
        )

        applyAccount(
            c,
            response.getJSONObject("account")
        )
    }

    fun plans(c: Context): Result<JSONArray> =
        runCatching {
            if (!hasSession(c)) {
                error("AUTH_REQUIRED")
            }

            authenticatedRequest(
                c,
                "GET",
                "/api/v1/plans",
                null,
            ).getJSONArray("plans")
        }

    fun createOrder(
        c: Context,
        planId: Int,
    ): Result<JSONObject> = runCatching {
        authenticatedRequest(
            c,
            "POST",
            "/api/v1/orders",
            JSONObject().put("plan_id", planId),
        ).getJSONObject("order")
    }

    fun closeCheckout(
        c: Context,
        id: String,
    ): Result<JSONObject> = runCatching {
        authenticatedRequest(
            c,
            "POST",
            "/api/v1/orders/$id/checkout/close",
            JSONObject(),
        ).getJSONObject("order")
    }

    fun postAiEvent(
        c: Context,
        payload: JSONObject,
    ): Result<JSONObject> = runCatching {
        authenticatedRequest(
            c,
            "POST",
            "/api/v1/ai/events",
            payload,
        )
    }

    fun aiRecommendations(
        c: Context,
        operator: String,
        networkType: String,
        mode: String,
    ): Result<JSONObject> = runCatching {
        val path = "/api/v1/ai/recommendations" +
            "?operator=" + java.net.URLEncoder.encode(operator, "UTF-8") +
            "&network_type=" + java.net.URLEncoder.encode(networkType, "UTF-8") +
            "&mode=" + java.net.URLEncoder.encode(mode, "UTF-8")
        authenticatedRequest(c, "GET", path, null)
    }

    fun aiDashboard(c: Context): Result<JSONObject> = runCatching {
        authenticatedRequest(
            c,
            "GET",
            "/api/v1/ai/dashboard",
            null,
        )
    }

    fun submitFeedback(
        c: Context,
        payload: JSONObject,
    ): Result<JSONObject> = runCatching {
        authenticatedRequest(
            c,
            "POST",
            "/api/v1/feedback",
            payload,
        )
    }

    fun order(
        c: Context,
        id: String,
    ): Result<JSONObject> = runCatching {
        authenticatedRequest(
            c,
            "GET",
            "/api/v1/orders/$id",
            null,
        ).getJSONObject("order")
    }

    private fun applyAccount(
        c: Context,
        account: JSONObject,
    ): BlueVpnAccountSnapshot {
        val subscription =
            account.optJSONObject("subscription") ?: JSONObject()
        val url = subscription.optString("url")

        c.getSharedPreferences(
            "bluevpn_subscription_info",
            Context.MODE_PRIVATE
        ).edit().clear().apply()

        val email = account.optString("email")
        val effectiveActive = effectiveSubscriptionActive(subscription)
        prefs(c).edit()
            .putString("email", email)
            .putBoolean(
                "active",
                effectiveActive
            )
            .putString(
                "status",
                subscription.optString(
                    "status",
                    "inactive"
                )
            )
            .putString(
                "expire",
                subscription.optString("expire")
                    .takeIf {
                        it.isNotBlank() && it != "null"
                    }
            )
            .putString(
                "expire_fa",
                subscription.optString("expire_fa")
                    .takeIf {
                        it.isNotBlank() && it != "null"
                    }
            )
            .putString("url", url)
            .putLong(
                "limit",
                subscription.optLong("data_limit_bytes")
            )
            .putLong(
                "used",
                subscription.optLong("used_traffic_bytes")
            )
            .putInt(
                "devices",
                subscription.optInt("device_limit", 1)
            )
            .putLong(
                "last_sync",
                System.currentTimeMillis()
            )
            .putString(
                "sync_error",
                subscription.optString("sync_error")
            )
            .putString(
                "active_reason",
                subscription.optString("active_reason")
            )
            .putLong(
                "account_app_version",
                currentAppVersion()
            )
            .commit()

        if (email.isNotBlank()) {
            backup(c).edit()
                .putString("email", email)
                .commit()
        }

        if (url.startsWith("http")) install(url)
        return snapshot(c)
    }

    private fun install(url: String) {
        val old = MmkvManager.decodeSubscriptions()
            .firstOrNull {
                it.subscription.remarks == SUB
            }

        val item = SubscriptionItem(
            remarks = SUB,
            url = url,
            enabled = true,
            autoUpdate = true,
        )

        MmkvManager.encodeSubscription(
            old?.guid.orEmpty(),
            item,
        )
        AngConfigManager.updateConfigViaSubAll()
    }

    private fun request(
        c: Context,
        method: String,
        path: String,
        body: JSONObject?,
        auth: Boolean,
    ): JSONObject {
        val connection =
            URL(apiBaseUrl() + path)
                .openConnection() as HttpURLConnection

        try {
            connection.requestMethod = method
            connection.connectTimeout = 7_000
            connection.readTimeout = 12_000
            connection.setRequestProperty(
                "Accept",
                "application/json"
            )
            connection.setRequestProperty(
                "Content-Type",
                "application/json"
            )
            connection.setRequestProperty(
                "X-Device-ID",
                deviceId(c)
            )
            connection.setRequestProperty(
                "User-Agent",
                "BlueVPN/${BuildConfig.VERSION_NAME}"
            )

            if (auth) {
                val access = token(c)
                if (access.isBlank()) {
                    throw ApiException(
                        401,
                        "AUTH_REQUIRED",
                        "ورود لازم است",
                    )
                }
                connection.setRequestProperty(
                    "Authorization",
                    "Bearer $access"
                )
            }

            if (body != null && method != "GET") {
                connection.doOutput = true
                connection.outputStream
                    .bufferedWriter()
                    .use { it.write(body.toString()) }
            }

            val status = connection.responseCode
            val stream = if (status in 200..299) {
                connection.inputStream
            } else {
                connection.errorStream
            }

            val raw = stream
                ?.bufferedReader()
                ?.use { it.readText() }
                .orEmpty()

            val response = if (raw.isBlank()) {
                JSONObject()
            } else {
                JSONObject(raw)
            }

            if (status !in 200..299) {
                val detail = response.opt("detail")
                val code = if (detail is JSONObject) {
                    detail.optString("code", "HTTP_$status")
                } else {
                    "HTTP_$status"
                }

                throw ApiException(
                    status,
                    code,
                    message(response),
                )
            }

            return response
        } finally {
            connection.disconnect()
        }
    }

    private fun message(response: JSONObject): String {
        val detail = response.opt("detail")
        return if (detail is JSONObject) {
            detail.optString(
                "message",
                detail.optString("code", "خطای سرور")
            )
        } else {
            detail?.toString()
                ?.takeIf { it.isNotBlank() }
                ?: response.optString(
                    "message",
                    "خطای ارتباط با سرور"
                )
        }
    }
}
