package com.v2ray.ang.bluevpn

import android.app.Activity
import android.content.Context
import android.view.View
import android.view.ViewGroup

/**
 * Google Play distribution stub.
 *
 * The Play flavor intentionally ships without the third-party Tapsell SDK.
 * BlueVPN first-party advertising remains available through the Manager feed,
 * while every Tapsell surface degrades to "unavailable" without touching VPN
 * state or collecting advertising identifiers.
 */
object BlueVpnTapsellManager {
    fun warmUp(context: Context) = Unit

    fun onVerifiedConnection(
        activity: Activity,
        sessionId: Long,
        onUnavailable: (() -> Unit)? = null,
    ) {
        onUnavailable?.invoke()
    }

    fun onEntitlementChanged(context: Context) = Unit

    fun showRewarded(
        activity: Activity,
        onRewarded: (Int) -> Unit,
        onUnavailable: (() -> Unit)? = null,
    ) {
        onUnavailable?.invoke()
    }

    fun attachStandardBanner(
        activity: Activity,
        host: ViewGroup,
        onShown: (() -> Unit)? = null,
        onUnavailable: (() -> Unit)? = null,
        onCleanup: ((() -> Unit) -> Unit)? = null,
    ) {
        host.visibility = View.GONE
        onUnavailable?.invoke()
    }

    fun attachPlacement(
        activity: Activity,
        host: ViewGroup,
        type: String,
        loadingView: View? = null,
        onUnavailable: (() -> Unit)? = null,
    ) {
        loadingView?.visibility = View.GONE
        host.visibility = View.GONE
        onUnavailable?.invoke()
    }
}
