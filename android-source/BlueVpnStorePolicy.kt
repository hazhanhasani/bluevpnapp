package com.v2ray.ang.bluevpn

import android.content.Context
import android.content.Intent
import android.net.Uri
import com.v2ray.ang.BuildConfig

/**
 * Compile-time distribution boundary for public app stores.
 *
 * The Google Play flavor is deliberately consumption-only: it can sign in to
 * an existing BlueVPN account and consume an entitlement, but it never creates
 * an account, starts an external digital checkout, requests package-install
 * permission, or installs an APK outside Google Play.
 */
object BlueVpnStorePolicy {
    fun isGooglePlayBuild(): Boolean =
        BuildConfig.DISTRIBUTION.equals("Play Store", ignoreCase = true)

    fun allowAccountCreation(): Boolean = !isGooglePlayBuild()
    fun allowExternalCheckout(): Boolean = !isGooglePlayBuild()
    fun allowPackageInstallerUpdates(): Boolean = !isGooglePlayBuild()
    fun allowThirdPartyAds(): Boolean = !isGooglePlayBuild()

    fun openGooglePlay(context: Context): Boolean {
        val packageName = context.packageName
        val market = Intent(
            Intent.ACTION_VIEW,
            Uri.parse("market://details?id=$packageName"),
        ).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        val web = Intent(
            Intent.ACTION_VIEW,
            Uri.parse("https://play.google.com/store/apps/details?id=$packageName"),
        ).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        return runCatching {
            context.startActivity(market)
            true
        }.getOrElse {
            runCatching {
                context.startActivity(web)
                true
            }.getOrDefault(false)
        }
    }
}
