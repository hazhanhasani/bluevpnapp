package com.v2ray.ang.bluevpn

/**
 * Explicit lifecycle for a user-requested location handover.
 *
 * BlueVPN never rolls back to the previous route after a failed handover.
 * A failed switch terminates in DISCONNECTED so the UI cannot present either
 * the previous route or the failed target as connected.
 */
enum class BlueVpnHandoverPhase {
    IDLE,
    SELECTING,
    SWITCHING,
    CONNECTED,
    FAILED,
    DISCONNECTED,
}

class BlueVpnHandoverState {
    private var phase: BlueVpnHandoverPhase = BlueVpnHandoverPhase.IDLE

    @Synchronized
    fun beginSelection(): BlueVpnHandoverPhase {
        phase = BlueVpnHandoverPhase.SELECTING
        return phase
    }

    @Synchronized
    fun beginSwitch(): BlueVpnHandoverPhase {
        phase = BlueVpnHandoverPhase.SWITCHING
        return phase
    }

    @Synchronized
    fun connected(): BlueVpnHandoverPhase {
        phase = BlueVpnHandoverPhase.CONNECTED
        return phase
    }

    @Synchronized
    fun failed(): BlueVpnHandoverPhase {
        phase = BlueVpnHandoverPhase.FAILED
        return phase
    }

    @Synchronized
    fun disconnected(): BlueVpnHandoverPhase {
        phase = BlueVpnHandoverPhase.DISCONNECTED
        return phase
    }

    @Synchronized
    fun reset(): BlueVpnHandoverPhase {
        phase = BlueVpnHandoverPhase.IDLE
        return phase
    }

    @Synchronized
    fun current(): BlueVpnHandoverPhase = phase

    @Synchronized
    fun isSwitching(): Boolean =
        phase == BlueVpnHandoverPhase.SELECTING ||
            phase == BlueVpnHandoverPhase.SWITCHING
}
