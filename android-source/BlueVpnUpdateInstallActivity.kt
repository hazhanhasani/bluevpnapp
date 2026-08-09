package com.v2ray.ang.ui

import android.app.Activity
import android.content.Intent
import android.os.Bundle
import com.v2ray.ang.bluevpn.BlueVpnUpdateManager

/**
 * Transparent callback bridge for PackageInstaller session results.
 * It never renders a BlueVPN screen; it only forwards the system status and
 * launches Android's own confirmation UI when user approval is required.
 */
class BlueVpnUpdateInstallActivity : Activity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        BlueVpnUpdateManager.handlePackageInstallerStatus(this, intent)
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        BlueVpnUpdateManager.handlePackageInstallerStatus(this, intent)
    }
}
