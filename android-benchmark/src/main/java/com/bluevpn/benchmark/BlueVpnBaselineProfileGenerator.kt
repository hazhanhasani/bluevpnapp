package com.bluevpn.benchmark

import androidx.benchmark.macro.junit4.BaselineProfileRule
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.filters.LargeTest
import androidx.test.platform.app.InstrumentationRegistry
import androidx.test.uiautomator.By
import androidx.test.uiautomator.UiDevice
import androidx.test.uiautomator.Until
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
@LargeTest
class BlueVpnBaselineProfileGenerator {
    private val packageName = "ir.blluepanel.bluevpn"

    @get:Rule
    val baselineProfileRule = BaselineProfileRule()

    private fun prepareHomeForBenchmark(device: UiDevice) {
        device.executeShellCommand(
            "pm grant $packageName android.permission.POST_NOTIFICATIONS"
        )
        device.waitForIdle()
    }

    private fun openLocationsFromHome(device: UiDevice) {
        val card = device.wait(
            Until.findObject(By.res(packageName, "bluevpn_server_card")),
            10_000,
        ) ?: error("Home did not expose the Locations action")
        card.click()
        check(
            device.wait(
                Until.hasObject(By.clazz("androidx.recyclerview.widget.RecyclerView")),
                10_000,
            )
        ) { "Locations RecyclerView did not open" }
    }

    @Test
    fun criticalUserJourneys() = baselineProfileRule.collect(
        packageName = packageName,
        includeInStartupProfile = true,
    ) {
        pressHome()
        val device = UiDevice.getInstance(InstrumentationRegistry.getInstrumentation())
        prepareHomeForBenchmark(device)
        startActivityAndWait()

        openLocationsFromHome(device)

        val search = device.wait(
            Until.findObject(By.clazz("android.widget.EditText")),
            10_000,
        ) ?: error("Locations search field did not appear")
        search.click()
        search.setText("Germany")
        device.waitForIdle()
    }
}
