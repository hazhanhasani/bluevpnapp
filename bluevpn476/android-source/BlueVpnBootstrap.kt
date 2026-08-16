package com.v2ray.ang.bluevpn

import android.content.Context

object BlueVpnBootstrap {
    fun start(context: Context) {
        val app = context.applicationContext
        BlueVpnUiGuard.installCrashLogger(app)
        BlueVpnLiveReporter.start(app)
    }
}
