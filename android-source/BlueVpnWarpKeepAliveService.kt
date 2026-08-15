package com.v2ray.ang.bluevpn

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.Service
import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.IBinder
import androidx.core.app.NotificationCompat
import com.v2ray.ang.R

/**
 * Keeps the application process (and its child Aether process) foreground-owned
 * while a Free/WARP tunnel is active. UI Activity lifecycle must not own Aether.
 */
class BlueVpnWarpKeepAliveService : Service() {
    companion object {
        private const val CHANNEL = "bluevpn_warp_keepalive"
        private const val NOTIFICATION_ID = 7319
        private const val ACTION_START = "com.v2ray.ang.bluevpn.WARP_KEEPALIVE_START"
        private const val ACTION_STOP = "com.v2ray.ang.bluevpn.WARP_KEEPALIVE_STOP"

        fun start(context: Context) {
            val i = Intent(context.applicationContext, BlueVpnWarpKeepAliveService::class.java).setAction(ACTION_START)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) context.applicationContext.startForegroundService(i)
            else context.applicationContext.startService(i)
        }

        fun stop(context: Context) {
            context.applicationContext.startService(Intent(context.applicationContext, BlueVpnWarpKeepAliveService::class.java).setAction(ACTION_STOP))
        }
    }

    override fun onCreate() {
        super.onCreate()
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val nm = getSystemService(NotificationManager::class.java)
            nm.createNotificationChannel(NotificationChannel(CHANNEL, "BlueVPN WARP", NotificationManager.IMPORTANCE_LOW).apply {
                description = "Keeps the free VPN tunnel active in background"
                setShowBadge(false)
            })
        }
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_STOP) {
            stopForeground(true)
            stopSelf()
            return START_NOT_STICKY
        }
        val notification = NotificationCompat.Builder(this, CHANNEL)
            .setSmallIcon(R.mipmap.ic_launcher)
            .setContentTitle("BlueVPN")
            .setContentText("اتصال رایگان WARP فعال است")
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .build()
        startForeground(NOTIFICATION_ID, notification)
        return START_STICKY
    }

    override fun onTaskRemoved(rootIntent: Intent?) {
        // Do not stop the tunnel when the UI task is removed.
        super.onTaskRemoved(rootIntent)
    }

    override fun onDestroy() {
        // Never tear Aether down because the UI/service host is being recreated.
        // Explicit disconnect owns the engine teardown path.
        super.onDestroy()
    }

    override fun onBind(intent: Intent?): IBinder? = null
}
