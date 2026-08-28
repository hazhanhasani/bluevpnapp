package com.v2ray.ang.ui

import android.content.pm.ActivityInfo
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
import org.junit.runner.RunWith

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
