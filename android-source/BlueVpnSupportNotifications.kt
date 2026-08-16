package com.v2ray.ang.bluevpn

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequest
import androidx.work.Worker
import androidx.work.WorkerParameters
import androidx.work.WorkManager
import com.v2ray.ang.R
import com.v2ray.ang.ui.BlueVpnSupportActivity
import java.util.concurrent.TimeUnit

object BlueVpnSupportNotifications {
    private const val WORK = "bluevpn-support-unread"
    private const val CHANNEL = "bluevpn_support_messages"

    fun schedule(context: Context) {
        val app = context.applicationContext
        if (!BlueVpnAccountManager.hasSession(app)) {
            WorkManager.getInstance(app).cancelUniqueWork(WORK)
            return
        }
        val constraints = Constraints.Builder()
            .setRequiredNetworkType(NetworkType.CONNECTED)
            .build()
        val request = PeriodicWorkRequest.Builder(
            BlueVpnSupportNotificationWorker::class.java,
            15,
            TimeUnit.MINUTES,
        )
            .setConstraints(constraints)
            .build()
        WorkManager.getInstance(app).enqueueUniquePeriodicWork(
            WORK,
            ExistingPeriodicWorkPolicy.UPDATE,
            request,
        )
    }

    internal fun notify(context: Context, conversationId: Int, messageId: Int, body: String) {
        if (messageId <= 0) return
        val prefs = context.getSharedPreferences("bluevpn_support_notify", Context.MODE_PRIVATE)
        if (messageId <= prefs.getInt("last_message_id", 0)) return

        val manager = context.getSystemService(NotificationManager::class.java)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            manager.createNotificationChannel(
                NotificationChannel(
                    CHANNEL,
                    "پیام‌های پشتیبانی BlueVPN",
                    NotificationManager.IMPORTANCE_DEFAULT,
                )
            )
        }
        val intent = Intent(context, BlueVpnSupportActivity::class.java).apply {
            putExtra("conversation_id", conversationId)
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP)
        }
        val pending = PendingIntent.getActivity(
            context,
            conversationId.coerceAtLeast(1),
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        val notification = NotificationCompat.Builder(context, CHANNEL)
            .setSmallIcon(R.drawable.ic_stat_name)
            .setContentTitle("پشتیبانی BlueVPN")
            .setContentText(body.ifBlank { "پاسخ جدید از پشتیبانی دریافت شد" }.take(120))
            .setStyle(
                NotificationCompat.BigTextStyle()
                    .bigText(body.ifBlank { "پاسخ جدید از پشتیبانی دریافت شد" }.take(500))
            )
            .setAutoCancel(true)
            .setContentIntent(pending)
            .build()
        manager.notify(8800 + (conversationId % 500), notification)
        prefs.edit().putInt("last_message_id", messageId).apply()
    }
}

class BlueVpnSupportNotificationWorker(
    context: Context,
    params: WorkerParameters,
) : Worker(context, params) {
    override fun doWork(): Result {
        if (!BlueVpnAccountManager.hasSession(applicationContext)) {
            return Result.success()
        }
        val response = BlueVpnAccountManager.supportRequest(
            applicationContext,
            "GET",
            "/api/v1/support/unread",
        ).getOrNull() ?: return Result.retry()

        val latest = response.optJSONObject("latest") ?: return Result.success()
        BlueVpnSupportNotifications.notify(
            applicationContext,
            latest.optInt("conversation_id"),
            latest.optInt("message_id"),
            latest.optString("body"),
        )
        return Result.success()
    }
}
