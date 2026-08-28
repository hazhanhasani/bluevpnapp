package com.v2ray.ang.ui

import android.content.pm.ActivityInfo
import android.view.View
import androidx.recyclerview.widget.RecyclerView
import androidx.test.core.app.ActivityScenario
import androidx.test.espresso.Espresso.onView
import androidx.test.espresso.action.ViewActions.click
import androidx.test.espresso.action.ViewActions.replaceText
import androidx.test.espresso.assertion.ViewAssertions.matches
import androidx.test.espresso.matcher.ViewMatchers.isAssignableFrom
import androidx.test.espresso.matcher.ViewMatchers.isDisplayed
import androidx.test.espresso.matcher.ViewMatchers.withHint
import androidx.test.espresso.matcher.ViewMatchers.withText
import androidx.test.ext.junit.runners.AndroidJUnit4
import org.hamcrest.Matchers.allOf
import org.junit.Test
import org.junit.Assert.assertEquals
import org.junit.runner.RunWith
import java.io.File
import androidx.test.platform.app.InstrumentationRegistry
import androidx.test.uiautomator.UiDevice

@RunWith(AndroidJUnit4::class)
class BlueVpnLocationsUiTest {

    @Test
    fun locationsLaunchesWithVirtualizedList() {
        ActivityScenario.launch(BlueVpnServersActivity::class.java).use {
            onView(withText("مکان‌ها")).check(matches(isDisplayed()))
            onView(isAssignableFrom(RecyclerView::class.java))
                .check(matches(isDisplayed()))
            onView(withHint("جست‌وجوی کشور یا سرور"))
                .check(matches(isDisplayed()))
        }
    }

    @Test
    fun searchSurvivesActivityRecreation() {
        ActivityScenario.launch(BlueVpnServersActivity::class.java).use { scenario ->
            onView(withHint("جست‌وجوی کشور یا سرور"))
                .perform(replaceText("آلمان"))
            scenario.recreate()
            onView(allOf(
                withHint("جست‌وجوی کشور یا سرور"),
                withText("آلمان"),
            )).check(matches(isDisplayed()))
        }
    }


    @Test
    fun locationsRootIsExplicitRtl() {
        ActivityScenario.launch(BlueVpnServersActivity::class.java).use { scenario ->
            scenario.onActivity { activity ->
                val content = activity.findViewById<android.view.ViewGroup>(android.R.id.content)
                val root = content.getChildAt(0)
                assertEquals(View.LAYOUT_DIRECTION_RTL, root.layoutDirection)
            }
        }
    }

    @Test
    fun captureLightAndDarkRtlSnapshots() {
        val instrumentation = InstrumentationRegistry.getInstrumentation()
        val device = UiDevice.getInstance(instrumentation)
        val target = instrumentation.targetContext
        val qaDir = target.getExternalFilesDir("qa") ?: return

        device.executeShellCommand("cmd uimode night no")
        ActivityScenario.launch(BlueVpnServersActivity::class.java).use { scenario ->
            scenario.recreate()
            device.waitForIdle()
            device.takeScreenshot(File(qaDir, "locations-light-rtl.png"))

            device.executeShellCommand("cmd uimode night yes")
            scenario.recreate()
            device.waitForIdle()
            device.takeScreenshot(File(qaDir, "locations-dark-rtl.png"))
        }
        device.executeShellCommand("cmd uimode night no")
    }

    @Test
    fun searchAndTabSurviveFreshActivityAfterStatePersistence() {
        ActivityScenario.launch(BlueVpnServersActivity::class.java).use {
            onView(withText("اخیر")).perform(click())
            onView(withHint("جست‌وجوی کشور یا سرور"))
                .perform(replaceText("Netherlands"))
        }

        ActivityScenario.launch(BlueVpnServersActivity::class.java).use {
            onView(allOf(
                withHint("جست‌وجوی کشور یا سرور"),
                withText("Netherlands"),
            )).check(matches(isDisplayed()))
            onView(withText("اخیر")).check(matches(isDisplayed()))
        }
    }

    @Test
    fun tabAndSearchSurviveRotationRecreation() {
        ActivityScenario.launch(BlueVpnServersActivity::class.java).use { scenario ->
            onView(withText("علاقه‌مندی")).perform(click())
            onView(withHint("جست‌وجوی کشور یا سرور"))
                .perform(replaceText("Germany"))

            scenario.onActivity {
                it.requestedOrientation = ActivityInfo.SCREEN_ORIENTATION_LANDSCAPE
            }
            scenario.recreate()

            onView(allOf(
                withHint("جست‌وجوی کشور یا سرور"),
                withText("Germany"),
            )).check(matches(isDisplayed()))
            onView(withText("علاقه‌مندی")).check(matches(isDisplayed()))
        }
    }
}
