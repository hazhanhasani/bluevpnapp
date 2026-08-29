package com.bluevpn.benchmark

import androidx.benchmark.macro.FrameTimingMetric
import androidx.benchmark.macro.StartupMode
import androidx.benchmark.macro.StartupTimingMetric
import androidx.benchmark.macro.junit4.MacrobenchmarkRule
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
class BlueVpnLocationsMacrobenchmark {
    @get:Rule
    val benchmarkRule = MacrobenchmarkRule()

    private val packageName = "ir.blluepanel.bluevpn"

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

            // Home is vertically scrollable on smaller/emulated displays. The
            // server/location card can be below the initial accessibility viewport.
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
    fun coldStartupAndOpenLocations() = benchmarkRule.measureRepeated(
        packageName = packageName,
        metrics = listOf(StartupTimingMetric(), FrameTimingMetric()),
        iterations = 5,
        startupMode = StartupMode.COLD,
        setupBlock = {
            pressHome()
        },
    ) {
        startActivityAndWait()
        val device = UiDevice.getInstance(InstrumentationRegistry.getInstrumentation())
        device.waitForIdle()

        openLocationsFromHome(device)
        device.waitForIdle()
    }

    @Test
    fun locationsSearchAndScroll() = benchmarkRule.measureRepeated(
        packageName = packageName,
        metrics = listOf(FrameTimingMetric()),
        iterations = 5,
        startupMode = StartupMode.WARM,
        setupBlock = {
            pressHome()
            startActivityAndWait()
        },
    ) {
        val device = UiDevice.getInstance(InstrumentationRegistry.getInstrumentation())
        openLocationsFromHome(device)
        val search = device.wait(
            Until.findObject(By.clazz("android.widget.EditText")),
            10_000,
        ) ?: error("Locations search field did not appear")
        search.setText("Germany")
        device.waitForIdle()

        val x = device.displayWidth / 2
        device.swipe(
            x,
            (device.displayHeight * 0.78f).toInt(),
            x,
            (device.displayHeight * 0.28f).toInt(),
            12,
        )
        device.waitForIdle()
    }
}
