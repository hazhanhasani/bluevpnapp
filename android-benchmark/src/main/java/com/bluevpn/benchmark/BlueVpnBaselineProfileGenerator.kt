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
    @get:Rule
    val baselineProfileRule = BaselineProfileRule()

    @Test
    fun criticalUserJourneys() = baselineProfileRule.collect(
        packageName = "ir.blluepanel.bluevpn",
        includeInStartupProfile = true,
    ) {
        pressHome()
        startActivityAndWait()

        val device = UiDevice.getInstance(InstrumentationRegistry.getInstrumentation())
        val openLocations = device.wait(
            Until.findObject(By.desc("نمایش مکان‌ها")),
            5_000,
        ) ?: error("Home did not expose the Locations action")
        openLocations.click()
        check(
            device.wait(
                Until.hasObject(By.clazz("androidx.recyclerview.widget.RecyclerView")),
                5_000,
            )
        ) { "Locations RecyclerView did not open" }

        val search = device.wait(
            Until.findObject(By.clazz("android.widget.EditText")),
            5_000,
        ) ?: error("Locations search field did not appear")
        search.click()
        search.setText("Germany")
        device.waitForIdle()
    }
}
