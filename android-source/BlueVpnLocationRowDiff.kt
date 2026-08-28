package com.v2ray.ang.bluevpn

import androidx.recyclerview.widget.DiffUtil

/**
 * Minimal row diff contract for Locations.
 *
 * Stable identity is separate from visual content so runtime state can update
 * only the affected rows while preserving scroll position.
 */
object BlueVpnLocationRowDiff : DiffUtil.ItemCallback<BlueVpnLocationListRow>() {
    const val PAYLOAD_LATENCY = "bluevpn.locations.payload.LATENCY"
    const val PAYLOAD_SERVER_STATE = "bluevpn.locations.payload.SERVER_STATE"
    const val PAYLOAD_COUNTRY_ACTIVE = "bluevpn.locations.payload.COUNTRY_ACTIVE"

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
        if (oldItem is BlueVpnLocationListRow.Country &&
            newItem is BlueVpnLocationListRow.Country
        ) {
            val structuralSame =
                oldItem.locationKey == newItem.locationKey &&
                    oldItem.title == newItem.title &&
                    oldItem.flag == newItem.flag &&
                    oldItem.serverCount == newItem.serverCount &&
                    oldItem.expanded == newItem.expanded &&
                    oldItem.favorite == newItem.favorite &&
                    oldItem.availability == newItem.availability
            val activeStateChanged =
                oldItem.active != newItem.active ||
                    oldItem.automaticActive != newItem.automaticActive
            return if (structuralSame && activeStateChanged) {
                PAYLOAD_COUNTRY_ACTIVE
            } else {
                null
            }
        }

        if (oldItem !is BlueVpnLocationListRow.Server ||
            newItem !is BlueVpnLocationListRow.Server
        ) return null

        val baseSame =
            oldItem.guid == newItem.guid &&
                oldItem.locationKey == newItem.locationKey &&
                oldItem.title == newItem.title &&
                oldItem.ordinal == newItem.ordinal &&
                oldItem.premium == newItem.premium

        if (!baseSame) return null

        val stateChanged =
            oldItem.active != newItem.active ||
                oldItem.automaticActive != newItem.automaticActive

        val latencyChanged =
            oldItem.latencyPhase != newItem.latencyPhase ||
                oldItem.latencyMs != newItem.latencyMs ||
                oldItem.signalLevel != newItem.signalLevel

        return when {
            stateChanged -> PAYLOAD_SERVER_STATE
            latencyChanged -> PAYLOAD_LATENCY
            else -> null
        }
    }
}
