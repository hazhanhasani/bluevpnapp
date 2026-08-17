package com.v2ray.ang.bluevpn

import android.content.Context
import com.v2ray.ang.dto.entities.ProfileItem

/**
 * Single public naming boundary for imported VPN profiles.
 *
 * Provider/channel/bot remarks are intentionally retained inside the imported
 * ProfileItem because BlueVPN uses them as hidden metadata for country detection
 * and diagnostics. They must never be exposed in customer-facing Android UI.
 */
object BlueVpnPublicProfileName {
    private const val BRAND = "BlueVPN"

    fun forProfile(
        context: Context,
        profile: ProfileItem?,
    ): String {
        if (profile == null) return BRAND

        return runCatching {
            val entitlement = BlueVpnEntitlement.resolveUi(context.applicationContext)
            val tierLabel = if (entitlement.isPremium) "ویژه" else "رایگان"
            val location = BlueVpnLocationUtil.detect(
                profile.remarks,
                profile.server,
            )
            val locationLabel = if (location.key == "unknown") {
                "اتصال هوشمند"
            } else {
                "${location.flag} ${location.title}"
            }
            "$BRAND • $tierLabel • $locationLabel"
        }.getOrDefault(BRAND)
    }
}
