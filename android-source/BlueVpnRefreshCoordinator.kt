package com.v2ray.ang.bluevpn

/**
 * Owns the user-initiated Locations refresh lifecycle.
 *
 * Runtime broadcasts (ping/list updates) are intentionally not allowed to finish
 * this state. Only the account/pool pipeline that owns the returned token may
 * complete it. This prevents ping broadcasts from re-enabling the refresh button
 * while account sync is still running.
 */
class BlueVpnRefreshCoordinator {
    enum class Phase {
        IDLE,
        ACCOUNT_SYNC,
        POOL_RELOAD,
    }

    private var generation: Long = 0L
    private var activeToken: Long = 0L
    private var phase: Phase = Phase.IDLE

    @Synchronized
    fun begin(): Long {
        generation += 1L
        activeToken = generation
        phase = Phase.ACCOUNT_SYNC
        return activeToken
    }

    @Synchronized
    fun beginPoolReload(token: Long): Boolean {
        if (token <= 0L || token != activeToken || phase == Phase.IDLE) return false
        phase = Phase.POOL_RELOAD
        return true
    }

    @Synchronized
    fun finish(token: Long): Boolean {
        if (token <= 0L || token != activeToken || phase == Phase.IDLE) return false
        phase = Phase.IDLE
        return true
    }

    @Synchronized
    fun timeout(token: Long): Boolean = finish(token)

    @Synchronized
    fun isActive(token: Long): Boolean =
        token > 0L && token == activeToken && phase != Phase.IDLE

    @Synchronized
    fun isRefreshing(): Boolean = phase != Phase.IDLE

    @Synchronized
    fun currentPhase(): Phase = phase
}
