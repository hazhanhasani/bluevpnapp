package com.v2ray.ang.bluevpn

import androidx.benchmark.junit4.BenchmarkRule
import androidx.benchmark.junit4.measureRepeated
import org.junit.Rule
import org.junit.Test

class BlueVpnLocationDiffBenchmark {
    @get:Rule
    val benchmarkRule = BenchmarkRule()

    @Test
    fun diffPayloadAcrossThousandServers() = benchmarkRule.measureRepeated {
        val oldRows = (0 until 1000).map { index ->
            BlueVpnLocationListRow.Server(
                guid = "server-$index",
                locationKey = "de",
                title = "آلمان",
                ordinal = index + 1,
                active = index == 0,
                automaticActive = false,
                manualActive = index == 0,
                premium = true,
                latencyPhase = BlueVpnLatencyPhase.FRESH,
                latencyMs = 40L + (index % 150),
                signalLevel = 3,
            )
        }
        val newRows = oldRows.mapIndexed { index, row ->
            if (index % 10 == 0) {
                row.copy(
                    latencyMs = row.latencyMs + 5L,
                    signalLevel = if (row.signalLevel == 3) 4 else 3,
                )
            } else row
        }

        oldRows.indices.forEach { index ->
            BlueVpnLocationRowDiff.areItemsTheSame(oldRows[index], newRows[index])
            BlueVpnLocationRowDiff.areContentsTheSame(oldRows[index], newRows[index])
            BlueVpnLocationRowDiff.getChangePayload(oldRows[index], newRows[index])
        }
    }
}
