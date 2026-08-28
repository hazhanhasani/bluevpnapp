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

        device.findObject(By.desc("نمایش مکان‌ها"))?.click()
        device.wait(Until.hasObject(By.clazz("androidx.recyclerview.widget.RecyclerView")), 5_000)
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
        device.findObject(By.desc("نمایش مکان‌ها"))?.click()
        device.wait(Until.hasObject(By.clazz("android.widget.EditText")), 5_000)

        device.findObject(By.clazz("android.widget.EditText"))?.setText("Germany")
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
