package com.v2ray.ang.bluevpn

import androidx.recyclerview.widget.DiffUtil

/**
 * Minimal row diff contract for Locations.
 *
 * Stable identity is separate from visual content so ping/state updates can
 * rebind only the affected server row while preserving scroll position.
 */
object BlueVpnLocationRowDiff : DiffUtil.ItemCallback<BlueVpnLocationListRow>() {
    override fun areItemsTheSame(
        oldItem: BlueVpnLocationListRow,
        newItem: BlueVpnLocationListRow,
    ): Boolean = oldItem.stableId == newItem.stableId

    override fun areContentsTheSame(
        oldItem: BlueVpnLocationListRow,
        newItem: BlueVpnLocationListRow,
    ): Boolean = oldItem.contentVersion == newItem.contentVersion
}
