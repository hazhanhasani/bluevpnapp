package com.v2ray.ang.bluevpn

import android.content.ComponentName
import android.content.Context
import android.graphics.drawable.Icon
import android.os.Build
import android.content.Intent
import android.service.quicksettings.Tile
import android.service.quicksettings.TileService
import com.v2ray.ang.R
import com.v2ray.ang.core.CoreServiceManager

/** BlueVPN-aware Quick Settings tile. Handles both Premium/Xray and Free/WARP. */
class BlueVpnQuickTileService : TileService() {
    companion object {
        fun requestRefresh(context: Context) {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.N) {
                TileService.requestListeningState(
                    context.applicationContext,
                    ComponentName(context, BlueVpnQuickTileService::class.java),
                )
            }
        }
    }

    override fun onStartListening() {
        super.onStartListening()
        refresh()
    }

    override fun onClick() {
        super.onClick()
        val running = CoreServiceManager.isRunning()
        val action = if (running) BlueVpnSystemController.ACTION_STOP else BlueVpnSystemController.ACTION_START
        sendBroadcast(
            Intent(this, BlueVpnSystemActionReceiver::class.java).setAction(action)
        )
        // Keep the current state until CoreVpnService broadcasts its real transition.
        refresh()
    }

    private fun refresh() {
        val tile = qsTile ?: return
        val running = CoreServiceManager.isRunning()
        tile.icon = Icon.createWithResource(this, R.drawable.ic_stat_name)
        tile.state = if (running) Tile.STATE_ACTIVE else Tile.STATE_INACTIVE
        val server = CoreServiceManager.getRunningServerName().trim()
        tile.label = if (running && server.isNotBlank()) server else getString(R.string.app_name)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            tile.subtitle = if (running) "متصل" else "قطع"
        }
        tile.updateTile()
    }
}
