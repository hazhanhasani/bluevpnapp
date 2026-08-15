package com.v2ray.ang.bluevpn

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

class BlueVpnSystemActionReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent?) {
        when (intent?.action) {
            BlueVpnSystemController.ACTION_START -> BlueVpnSystemController.start(context)
            BlueVpnSystemController.ACTION_STOP -> BlueVpnSystemController.stop(context)
            BlueVpnSystemController.ACTION_RESTART -> BlueVpnSystemController.restart(context)
            BlueVpnSystemController.ACTION_TOGGLE -> BlueVpnSystemController.toggle(context)
        }
    }
}
