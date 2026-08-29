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

    private fun openLocationsFromHome(device: UiDevice) {
        val selector = By.res(packageName, "bluevpn_server_card")
        repeat(5) { attempt ->
            val card = device.wait(
                Until.findObject(selector),
                if (attempt == 0) 3_000 else 1_000,
            )
            if (card != null) {
                card.click()
                check(
                    device.wait(
                        Until.hasObject(By.clazz("androidx.recyclerview.widget.RecyclerView")),
                        10_000,
                    )
                ) { "Locations RecyclerView did not open" }
                return
            }

            val x = device.displayWidth / 2
            device.swipe(
                x,
                (device.displayHeight * 0.80f).toInt(),
                x,
                (device.displayHeight * 0.30f).toInt(),
                14,
            )
            device.waitForIdle()
        }
        error("Home did not expose the Locations action after scrolling")
    }

    @Test
    fun criticalUserJourneys() = baselineProfileRule.collect(
        packageName = packageName,
        includeInStartupProfile = true,
    ) {
        pressHome()
        startActivityAndWait()

        val device = UiDevice.getInstance(InstrumentationRegistry.getInstrumentation())
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
