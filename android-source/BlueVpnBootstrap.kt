package com.v2ray.ang.bluevpn

import android.content.Context

object BlueVpnBootstrap {
    fun start(context: Context) {
        BlueVpnLiveReporter.start(context.applicationContext)
    }
}
