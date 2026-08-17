package com.v2ray.ang.bluevpn

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.net.TrafficStats
import android.os.Build
import android.os.Handler
import android.os.IBinder
import android.os.Looper
import android.os.Process
import android.os.SystemClock
import androidx.core.app.NotificationCompat
import com.v2ray.ang.R
import com.v2ray.ang.ui.BlueVpnHomeActivity
import java.util.Locale
import kotlin.math.max

/**
 * Application-lifecycle foreground owner for Free/WARP.
 *
 * Aether is a child process outside stock v2rayNG ownership, so Free/WARP needs
 * this auxiliary owner. Premium is already owned by CoreVpnService and must not
 * create a second foreground owner that can contend for the visible notification.
 *
 * The service exposes the persistent BlueVPN notification with live traffic and
 * BlueVPN-aware Stop/Restart actions while Free/WARP is active.
 */
class BlueVpnWarpKeepAliveService : Service() {
    companion object {
        private const val CHANNEL = "bluevpn_vpn_status_v2"
        private const val NOTIFICATION_ID = 1
        private const val ACTION_START = "com.v2ray.ang.bluevpn.WARP_KEEPALIVE_START"
        private const val ACTION_STOP = "com.v2ray.ang.bluevpn.WARP_KEEPALIVE_STOP"
        private const val UPDATE_MS = 3_000L

        fun start(context: Context) {
            val i = Intent(context.applicationContext, BlueVpnWarpKeepAliveService::class.java).setAction(ACTION_START)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) context.applicationContext.startForegroundService(i)
            else context.applicationContext.startService(i)
        }

        fun stop(context: Context) {
            val app = context.applicationContext
            runCatching {
                app.startService(Intent(app, BlueVpnWarpKeepAliveService::class.java).setAction(ACTION_STOP))
            }
        }

        @Volatile
        private var lastRecoveryRequestElapsed = 0L

        /**
         * Recover a live Free/WARP session after the default Android network changes.
         * Debounced to avoid restart storms while Wi-Fi/mobile handover produces
         * multiple ConnectivityManager callbacks.
         */
        fun requestNetworkRecovery(context: Context) {
            val app = context.applicationContext
            if (!BlueVpnWarpEngine.isRunning()) return

            val now = SystemClock.elapsedRealtime()
            synchronized(BlueVpnWarpKeepAliveService::class.java) {
                if (now - lastRecoveryRequestElapsed < 2_500L) return
                lastRecoveryRequestElapsed = now
            }

            BlueVpnRuntimeAudit.record(
                app,
                BlueVpnRuntimeAudit.Event.NETWORK_CHANGE,
                "recovery-request"
            )
            BlueVpnSystemController.restart(app)
        }
    }

    private val handler = Handler(Looper.getMainLooper())
    private var startedElapsed = 0L
    private var lastSampleElapsed = 0L
    private var lastRx = 0L
    private var lastTx = 0L

    private val updater = object : Runnable {
        override fun run() {
            if (startedElapsed <= 0L) return
            updateNotification()
            handler.postDelayed(this, UPDATE_MS)
        }
    }

    override fun onCreate() {
        super.onCreate()
        createChannel()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_STOP) {
            BlueVpnRuntimeAudit.record(this, BlueVpnRuntimeAudit.Event.WARP_FOREGROUND_STOP)
            handler.removeCallbacks(updater)
            startedElapsed = 0L
            stopForeground(true)
            stopSelf()
            return START_NOT_STICKY
        }

        if (startedElapsed <= 0L) {
            BlueVpnRuntimeAudit.record(this, BlueVpnRuntimeAudit.Event.WARP_FOREGROUND_START)
            startedElapsed = SystemClock.elapsedRealtime()
            lastSampleElapsed = startedElapsed
            lastRx = safeUidRx()
            lastTx = safeUidTx()
        }

        // Android requires startForeground immediately after startForegroundService.
        startForeground(NOTIFICATION_ID, buildNotification(0L, 0L))
        // CoreVpnService/v2rayNG owns the single visible BlueVPN notification.
        // This service only shares the same foreground notification ID so Android
        // does not render a second card.
        handler.removeCallbacks(updater)
        handler.post(updater)
        return START_STICKY
    }

    private fun createChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val nm = getSystemService(NotificationManager::class.java)
        nm.createNotificationChannel(
            NotificationChannel(
                CHANNEL,
                "وضعیت اتصال BlueVPN",
                NotificationManager.IMPORTANCE_LOW,
            ).apply {
                description = "وضعیت اتصال، سرعت و کنترل‌های BlueVPN"
                setShowBadge(false)
                enableVibration(false)
                setSound(null, null)
            },
        )
    }

    private fun updateNotification() {
        val now = SystemClock.elapsedRealtime()
        val rx = safeUidRx()
        val tx = safeUidTx()
        val elapsed = max(1L, now - lastSampleElapsed)
        val rxPerSec = if (rx >= lastRx) ((rx - lastRx) * 1000L) / elapsed else 0L
        val txPerSec = if (tx >= lastTx) ((tx - lastTx) * 1000L) / elapsed else 0L
        lastRx = rx
        lastTx = tx
        lastSampleElapsed = now

        val nm = getSystemService(NotificationManager::class.java)
        nm.notify(NOTIFICATION_ID, buildNotification(rxPerSec, txPerSec))
    }

    private fun buildNotification(rxPerSec: Long, txPerSec: Long): android.app.Notification {
        val strategy = if (BlueVpnWarpEngine.isRunning()) "اتصال رایگان BlueVPN" else "اتصال BlueVPN"

        val openIntent = Intent(this, BlueVpnHomeActivity::class.java)
            .addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP)
        val openPending = PendingIntent.getActivity(
            this,
            73191,
            openIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or immutableFlag(),
        )

        val stopPending = PendingIntent.getBroadcast(
            this,
            73192,
            Intent(this, BlueVpnSystemActionReceiver::class.java)
                .setAction(BlueVpnSystemController.ACTION_STOP),
            PendingIntent.FLAG_UPDATE_CURRENT or immutableFlag(),
        )
        val restartPending = PendingIntent.getBroadcast(
            this,
            73193,
            Intent(this, BlueVpnSystemActionReceiver::class.java)
                .setAction(BlueVpnSystemController.ACTION_RESTART),
            PendingIntent.FLAG_UPDATE_CURRENT or immutableFlag(),
        )

        val speed = "↓ ${formatRate(rxPerSec)}   ↑ ${formatRate(txPerSec)}"
        return NotificationCompat.Builder(this, CHANNEL)
            .setSmallIcon(R.drawable.ic_stat_name)
            .setContentTitle("BlueVPN • اتصال فعال")
            .setContentText("$strategy   $speed")
            .setStyle(
                NotificationCompat.BigTextStyle()
                    .bigText("$strategy\n$speed\nبرای مدیریت اتصال، BlueVPN را باز کنید."),
            )
            .setContentIntent(openPending)
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setSilent(true)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .setUsesChronometer(true)
            .setWhen(System.currentTimeMillis() - (SystemClock.elapsedRealtime() - startedElapsed))
            .addAction(0, "راه‌اندازی مجدد", restartPending)
            .addAction(0, "توقف", stopPending)
            .build()
    }

    private fun immutableFlag(): Int =
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) PendingIntent.FLAG_IMMUTABLE else 0

    private fun safeUidRx(): Long = TrafficStats.getUidRxBytes(Process.myUid()).coerceAtLeast(0L)
    private fun safeUidTx(): Long = TrafficStats.getUidTxBytes(Process.myUid()).coerceAtLeast(0L)

    private fun formatRate(bytesPerSecond: Long): String {
        val value = bytesPerSecond.coerceAtLeast(0L).toDouble()
        return when {
            value >= 1024.0 * 1024.0 -> String.format(Locale.US, "%.1f MB/s", value / (1024.0 * 1024.0))
            value >= 1024.0 -> String.format(Locale.US, "%.1f KB/s", value / 1024.0)
            else -> "${value.toLong()} B/s"
        }
    }

    override fun onTaskRemoved(rootIntent: Intent?) {
        BlueVpnRuntimeAudit.record(this, BlueVpnRuntimeAudit.Event.TASK_REMOVED)
        // Removing the UI from Recents must not disconnect the VPN.
        super.onTaskRemoved(rootIntent)
    }

    override fun onDestroy() {
        handler.removeCallbacks(updater)
        super.onDestroy()
    }

    override fun onBind(intent: Intent?): IBinder? = null
}
