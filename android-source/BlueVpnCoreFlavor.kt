package com.v2ray.ang.bluevpn

/**
 * Build-time core identity.
 *
 * The placeholder is replaced by scripts/prepare_android.py from BLUEVPN_CORE_MODE.
 * Runtime switching between stock and Mahsa AARs is intentionally not attempted:
 * both export the same libv2ray classes and cannot coexist safely in one APK.
 */
object BlueVpnCoreFlavor {
    const val FAMILY: String = "__BLUEVPN_CORE_FAMILY__"
    const val SOURCE_PIN: String = "__BLUEVPN_CORE_SOURCE_PIN__"

    const val IS_MAHSA_CANARY: Boolean = FAMILY == "mahsa-canary"
    const val IS_STOCK: Boolean = FAMILY == "stock"
}
