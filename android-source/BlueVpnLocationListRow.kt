package com.v2ray.ang.bluevpn

/**
 * Immutable flattened row model for the Locations browser.
 *
 * Country and server rows use stable IDs so RecyclerView/ListAdapter can update
 * only changed rows instead of recreating the full ScrollView tree.
 */
sealed class BlueVpnLocationListRow {
    abstract val stableId: String
    abstract val contentVersion: String

    data class Country(
        val locationKey: String,
        val title: String,
        val flag: String,
        val serverCount: Int,
        val expanded: Boolean,
        val favorite: Boolean,
        val active: Boolean,
        val automaticActive: Boolean,
        val availability: String,
    ) : BlueVpnLocationListRow() {
        override val stableId: String = "country:$locationKey"
        override val contentVersion: String = listOf(
            title,
            flag,
            serverCount.toString(),
            expanded.toString(),
            favorite.toString(),
            active.toString(),
            automaticActive.toString(),
            availability,
        ).joinToString("|")
    }

    data class Server(
        val guid: String,
        val locationKey: String,
        val title: String,
        val ordinal: Int,
        val active: Boolean,
        val automaticActive: Boolean,
        val manualActive: Boolean,
        val premium: Boolean,
        val latencyPhase: BlueVpnLatencyPhase,
        val latencyMs: Long,
        val signalLevel: Int,
        val qualityScore: Int,
    ) : BlueVpnLocationListRow() {
        override val stableId: String = "server:$guid"
        override val contentVersion: String = listOf(
            locationKey,
            title,
            ordinal.toString(),
            active.toString(),
            automaticActive.toString(),
            manualActive.toString(),
            premium.toString(),
            latencyPhase.name,
            latencyMs.toString(),
            signalLevel.toString(),
            qualityScore.toString(),
        ).joinToString("|")
    }
}
