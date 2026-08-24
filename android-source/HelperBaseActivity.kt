package com.v2ray.ang.ui

import android.os.Bundle
import androidx.appcompat.app.AppCompatActivity
import com.v2ray.ang.handler.AppLocaleManager

/**
 * View-based compatibility host for BlueVPN screens on v2rayNG 2.3.5.
 *
 * Upstream migrated its own screens to HelperBaseComponentActivity, whose
 * abstract Compose ScreenContent contract cannot host BlueVPN's programmatic
 * Android Views. Runtime, service and configuration ownership remain upstream;
 * this class only preserves the Activity/Context surface used by BlueVPN UI.
 */
abstract class HelperBaseActivity : AppCompatActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        AppLocaleManager.onActivityCreated(this)
    }
}
