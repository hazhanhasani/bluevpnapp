package com.v2ray.ang.bluevpn

import android.content.Context
import android.content.Intent
import android.net.Uri
import com.v2ray.ang.ui.BlueVpnSettingsActivity
import com.v2ray.ang.ui.BlueVpnSubscriptionsActivity

/**
 * Strict first-party navigation boundary for advertising CTAs.
 *
 * The control plane never sends arbitrary Android intents/classes. WordPress
 * only selects one of the allow-listed actions below and may attach an
 * optional active plan id plus an HTTPS fallback. This keeps banner/story
 * campaigns remotely configurable without turning the ad payload into a
 * general-purpose intent launcher.
 */
object BlueVpnAdActionRouter {
    const val ACTION_NONE = "none"
    const val ACTION_AUTH = "auth"
    const val ACTION_PLANS = "plans"
    const val ACTION_PURCHASE = "purchase"
    const val ACTION_ACCOUNT = "account"
    const val ACTION_RENEW = "renew"
    const val ACTION_SETTINGS = "settings"
    const val ACTION_EXTERNAL = "external"

    private val allowed = setOf(
        ACTION_NONE,
        ACTION_AUTH,
        ACTION_PLANS,
        ACTION_PURCHASE,
        ACTION_ACCOUNT,
        ACTION_RENEW,
        ACTION_SETTINGS,
        ACTION_EXTERNAL,
    )

    data class Destination(
        val action: String,
        val planId: Int = 0,
        val fallbackUrl: String = "",
    ) {
        fun isActionable(): Boolean = action != ACTION_NONE
    }

    fun destination(action: String, planId: Int = 0, fallbackUrl: String = ""): Destination {
        val normalized = action.trim().lowercase().takeIf { it in allowed }
            ?: if (safeHttpUrl(fallbackUrl).isNotBlank()) ACTION_EXTERNAL else ACTION_NONE
        return Destination(
            action = normalized,
            planId = planId.coerceAtLeast(0),
            fallbackUrl = safeHttpUrl(fallbackUrl),
        )
    }

    fun defaultButtonText(action: String): String = when (action.trim().lowercase()) {
        ACTION_AUTH -> "ورود / ثبت‌نام"
        ACTION_PLANS -> "مشاهده پلن‌ها"
        ACTION_PURCHASE -> "خرید اشتراک"
        ACTION_ACCOUNT -> "حساب کاربری"
        ACTION_RENEW -> "تمدید اشتراک"
        ACTION_SETTINGS -> "تنظیمات"
        ACTION_EXTERNAL -> "مشاهده"
        else -> ""
    }

    fun deepLink(action: String, planId: Int = 0): String {
        val normalized = action.trim().lowercase()
        if (normalized !in allowed || normalized in setOf(ACTION_NONE, ACTION_EXTERNAL)) return ""
        val base = "bluevpn://$normalized"
        return if (normalized == ACTION_PURCHASE && planId > 0) "$base?plan_id=$planId" else base
    }

    fun fromUri(uri: Uri?): Destination? {
        if (uri == null || !uri.scheme.equals("bluevpn", ignoreCase = true)) return null
        val action = uri.host.orEmpty().trim().lowercase()
        if (action !in allowed || action in setOf(ACTION_NONE, ACTION_EXTERNAL)) return null
        return destination(action, uri.getQueryParameter("plan_id")?.toIntOrNull() ?: 0, "")
    }

    fun open(
        context: Context,
        action: String,
        planId: Int = 0,
        fallbackUrl: String = "",
        source: String = "ad",
    ): Boolean {
        val target = destination(action, planId, fallbackUrl)
        val opened = runCatching {
            when (target.action) {
                ACTION_AUTH,
                ACTION_PLANS,
                ACTION_PURCHASE,
                ACTION_ACCOUNT,
                ACTION_RENEW -> {
                    context.startActivity(
                        Intent(context, BlueVpnSubscriptionsActivity::class.java).apply {
                            putExtra(BlueVpnSubscriptionsActivity.EXTRA_ENTRY_ROUTE, target.action)
                            putExtra(BlueVpnSubscriptionsActivity.EXTRA_PLAN_ID, target.planId)
                            putExtra(BlueVpnSubscriptionsActivity.EXTRA_ENTRY_SOURCE, source.take(80))
                            addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP)
                            if (context !is android.app.Activity) addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                        },
                    )
                    true
                }

                ACTION_SETTINGS -> {
                    context.startActivity(
                        Intent(context, BlueVpnSettingsActivity::class.java).apply {
                            addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP)
                            if (context !is android.app.Activity) addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                        },
                    )
                    true
                }

                ACTION_EXTERNAL -> openExternal(context, target.fallbackUrl)
                else -> false
            }
        }.getOrDefault(false)

        if (opened) return true
        return openExternal(context, target.fallbackUrl)
    }

    private fun openExternal(context: Context, value: String): Boolean {
        val safe = safeHttpUrl(value)
        if (safe.isBlank()) return false
        return runCatching {
            context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(safe)).apply {
                if (context !is android.app.Activity) addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            })
            true
        }.getOrDefault(false)
    }

    private fun safeHttpUrl(value: String): String {
        val trimmed = value.trim()
        val uri = runCatching { Uri.parse(trimmed) }.getOrNull() ?: return ""
        val scheme = uri.scheme?.lowercase()
        return if ((scheme == "https" || scheme == "http") && !uri.host.isNullOrBlank()) trimmed else ""
    }
}
