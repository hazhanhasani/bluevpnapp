package com.v2ray.ang.bluevpn

import android.content.Context
import android.content.Intent
import android.net.VpnService
import android.os.SystemClock
import com.v2ray.ang.core.CoreServiceManager
import com.v2ray.ang.ui.BlueVpnHomeActivity
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** System-level VPN controls shared by Quick Settings and notification actions. */
object BlueVpnSystemController {
    const val ACTION_TOGGLE = "ir.blluepanel.bluevpn.action.SYSTEM_TOGGLE"
    const val ACTION_START = "ir.blluepanel.bluevpn.action.SYSTEM_START"
    const val ACTION_STOP = "ir.blluepanel.bluevpn.action.SYSTEM_STOP"
    const val ACTION_RESTART = "ir.blluepanel.bluevpn.action.SYSTEM_RESTART"
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    fun isRunning(): Boolean = CoreServiceManager.isRunning() || BlueVpnWarpEngine.isRunning()

    fun toggle(context: Context) {
        if (isRunning()) stop(context) else start(context)
    }

    fun stop(context: Context) {
        val app = context.applicationContext
        CoreServiceManager.stopVService(app)
        BlueVpnWarpKeepAliveService.stop(app)
        scope.launch {
            BlueVpnWarpEngine.stop()
            BlueVpnPreferences.clearConnected(app)
            BlueVpnAccountManager.stopFreeSession(app, expired = false)
            BlueVpnQuickTileService.requestRefresh(app)
        }
    }

    fun predictiveFailover(context: Context) {
        val app = context.applicationContext
        if (!BlueVpnIntelligenceCore.claimPredictiveFailover(app)) return
        scope.launch {
            if (BlueVpnAccountManager.isFreeMode(app) && BlueVpnAccountManager.warpFreeEnabled(app)) {
                restart(app)
                return@launch
            }
            val current = com.v2ray.ang.handler.MmkvManager.getSelectServer().orEmpty()
            val entitlement = BlueVpnEntitlement.resolve(app)
            val candidates = BlueVpnLocationUtil.cachedCandidates(app)
                .filter { it.guid != current && it.guid in entitlement.serverGuids }
                .filter { BlueVpnAccountManager.candidateAllowed(app, it.guid, it.profile.subscriptionId, entitlement.serverGuids.toSet()) }
            val next = BlueVpnSmartSelector.connectionOrderTrusted(app, candidates).firstOrNull()?.candidate
            if (next != null) {
                com.v2ray.ang.handler.MmkvManager.setSelectServer(next.guid)
            }
            restart(app)
        }
    }

    fun restart(context: Context) {
        val app = context.applicationContext
        scope.launch {
            CoreServiceManager.stopVService(app)
            BlueVpnWarpKeepAliveService.stop(app)
            BlueVpnWarpEngine.stop()
            BlueVpnPreferences.clearConnected(app)
            delay(450L)
            start(app)
        }
    }

    fun start(context: Context) {
        val app = context.applicationContext
        // Android requires an Activity to grant VPN consent the first time.
        if (VpnService.prepare(app) != null) {
            openHomeForConsent(app)
            return
        }
        if (BlueVpnAccountManager.isFreeMode(app) && BlueVpnAccountManager.warpFreeEnabled(app)) {
            scope.launch { startFreeWarp(app) }
        } else {
            CoreServiceManager.startVServiceFromToggle(app)
        }
    }

    private suspend fun startFreeWarp(app: Context) {
        runCatching {
            val prepared = BlueVpnWarpEngine.prepareAdaptive(app)
            CoreServiceManager.startVService(app, prepared.guid)
            val deadline = SystemClock.elapsedRealtime() + 10_000L
            while (!CoreServiceManager.isRunning() && SystemClock.elapsedRealtime() < deadline) {
                delay(100L)
            }
            if (!CoreServiceManager.isRunning()) error("Xray bridge did not start")
            BlueVpnWarpKeepAliveService.start(app)
            BlueVpnWarpEngine.markConnected()
            BlueVpnPreferences.markConnected(app, resetTimer = true)
            BlueVpnAccountManager.startFreeSession(app)
        }.onFailure {
            BlueVpnWarpKeepAliveService.stop(app)
            BlueVpnWarpEngine.stop()
        }
        BlueVpnQuickTileService.requestRefresh(app)
    }

    private fun openHomeForConsent(context: Context) {
        context.startActivity(
            Intent(context, BlueVpnHomeActivity::class.java)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP)
        )
    }
}
