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
        device.findObject(By.desc("نمایش مکان‌ها"))?.click()
        device.wait(Until.hasObject(By.clazz("androidx.recyclerview.widget.RecyclerView")), 5_000)

        device.findObject(By.clazz("android.widget.EditText"))?.apply {
            click()
            setText("Germany")
        }
        device.waitForIdle()
    }
}
