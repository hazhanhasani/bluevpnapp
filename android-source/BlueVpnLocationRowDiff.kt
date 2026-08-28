package com.v2ray.ang.bluevpn

import androidx.recyclerview.widget.DiffUtil

/**
 * Minimal row diff contract for Locations.
 *
 * Stable identity is separate from visual content so ping/state updates can
 * rebind only the affected server row while preserving scroll position.
 */
object BlueVpnLocationRowDiff : DiffUtil.ItemCallback<BlueVpnLocationListRow>() {
    const val PAYLOAD_LATENCY = "bluevpn.locations.payload.LATENCY"

    override fun areItemsTheSame(
        oldItem: BlueVpnLocationListRow,
        newItem: BlueVpnLocationListRow,
    ): Boolean = oldItem.stableId == newItem.stableId

    override fun areContentsTheSame(
        oldItem: BlueVpnLocationListRow,
        newItem: BlueVpnLocationListRow,
    ): Boolean = oldItem.contentVersion == newItem.contentVersion

    override fun getChangePayload(
        oldItem: BlueVpnLocationListRow,
        newItem: BlueVpnLocationListRow,
    ): Any? {
        if (oldItem !is BlueVpnLocationListRow.Server ||
            newItem !is BlueVpnLocationListRow.Server
        ) return null

        val structuralSame =
            oldItem.guid == newItem.guid &&
                oldItem.locationKey == newItem.locationKey &&
                oldItem.title == newItem.title &&
                oldItem.ordinal == newItem.ordinal &&
                oldItem.active == newItem.active &&
                oldItem.automaticActive == newItem.automaticActive &&
                oldItem.premium == newItem.premium

        val latencyChanged =
            oldItem.latencyPhase != newItem.latencyPhase ||
                oldItem.latencyMs != newItem.latencyMs ||
                oldItem.signalLevel != newItem.signalLevel

        return if (structuralSame && latencyChanged) PAYLOAD_LATENCY else null
    }
}
