package com.v2ray.ang.bluevpn

import android.app.Activity
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.os.Build
import com.google.android.gms.auth.api.phone.SmsRetriever
import com.google.android.gms.common.api.CommonStatusCodes
import com.google.android.gms.common.api.Status

/**
 * Permissionless OTP helper for BlueVPN.
 *
 * Primary flow uses SMS User Consent so existing provider templates continue to
 * work without READ_SMS. Once the user approves the one-time message, BlueVPN
 * extracts the six digit code and the caller can verify it immediately.
 *
 * The UI also exposes Android's SMS OTP autofill hint, so keyboards/system
 * autofill can fill a code even when Google Play services consent is not
 * available.
 */
class BlueVpnSmsOtpAutoFill(
    private val activity: Activity,
    private val onCode: (String) -> Unit,
    private val onStatus: (String) -> Unit = {},
) {
    companion object {
        const val REQUEST_USER_CONSENT = 7814
        private val OTP_REGEX = Regex("(?<!\\d)([0-9۰-۹]{6})(?!\\d)")
    }

    private var receiverRegistered = false
    private var active = false

    private val receiver = object : BroadcastReceiver() {
        override fun onReceive(context: Context?, intent: Intent?) {
            if (intent?.action != SmsRetriever.SMS_RETRIEVED_ACTION || !active) return
            val status = intent.extras?.get(SmsRetriever.EXTRA_STATUS) as? Status ?: return
            when (status.statusCode) {
                CommonStatusCodes.SUCCESS -> {
                    @Suppress("DEPRECATION")
                    val consentIntent = intent.extras?.get(SmsRetriever.EXTRA_CONSENT_INTENT) as? Intent
                    if (consentIntent != null) {
                        try {
                            activity.startActivityForResult(consentIntent, REQUEST_USER_CONSENT)
                        } catch (_: Throwable) {
                            onStatus("دریافت خودکار کد در این دستگاه در دسترس نیست")
                        }
                    }
                }
                CommonStatusCodes.TIMEOUT -> onStatus("مهلت دریافت خودکار پیامک تمام شد؛ کد را دستی وارد کنید")
            }
        }
    }

    fun start() {
        active = true
        registerReceiverIfNeeded()
        try {
            SmsRetriever.getClient(activity).startSmsUserConsent(null)
                .addOnFailureListener {
                    onStatus("دریافت خودکار پیامک فعال نشد؛ ورود دستی کد همچنان در دسترس است")
                }
        } catch (_: Throwable) {
            onStatus("دریافت خودکار پیامک در این دستگاه پشتیبانی نمی‌شود")
        }
    }

    fun stop() {
        active = false
        if (receiverRegistered) {
            try { activity.unregisterReceiver(receiver) } catch (_: Throwable) {}
            receiverRegistered = false
        }
    }

    fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?): Boolean {
        if (requestCode != REQUEST_USER_CONSENT) return false
        if (resultCode == Activity.RESULT_OK) {
            val message = data?.getStringExtra(SmsRetriever.EXTRA_SMS_MESSAGE).orEmpty()
            val code = extractCode(message)
            if (code != null) onCode(code) else onStatus("کد ۶ رقمی داخل پیامک پیدا نشد")
        }
        return true
    }

    private fun registerReceiverIfNeeded() {
        if (receiverRegistered) return
        val filter = IntentFilter(SmsRetriever.SMS_RETRIEVED_ACTION)
        try {
            if (Build.VERSION.SDK_INT >= 33) {
                activity.registerReceiver(
                    receiver,
                    filter,
                    SmsRetriever.SEND_PERMISSION,
                    null,
                    Context.RECEIVER_EXPORTED,
                )
            } else {
                @Suppress("DEPRECATION")
                activity.registerReceiver(receiver, filter, SmsRetriever.SEND_PERMISSION, null)
            }
            receiverRegistered = true
        } catch (_: Throwable) {
            receiverRegistered = false
        }
    }

    private fun extractCode(message: String): String? {
        val raw = OTP_REGEX.find(message)?.groupValues?.getOrNull(1) ?: return null
        return raw.map { c ->
            when (c) {
                '۰' -> '0'; '۱' -> '1'; '۲' -> '2'; '۳' -> '3'; '۴' -> '4'
                '۵' -> '5'; '۶' -> '6'; '۷' -> '7'; '۸' -> '8'; '۹' -> '9'
                else -> c
            }
        }.joinToString("")
    }
}
