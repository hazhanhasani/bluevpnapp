package com.v2ray.ang.ui

import android.content.Context
import android.content.pm.ActivityInfo
import android.view.View
import android.view.ViewGroup
import android.widget.EditText
import android.widget.TextView
import androidx.recyclerview.widget.RecyclerView
import androidx.test.core.app.ActivityScenario
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import androidx.test.uiautomator.UiDevice
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class BlueVpnLocationsUiTest {

    @Before
    fun resetLocationsUiState() {
        val instrumentation = InstrumentationRegistry.getInstrumentation()
        instrumentation.targetContext
            .getSharedPreferences("bluevpn_locations_ui", Context.MODE_PRIVATE)
            .edit()
            .clear()
            .commit()

        UiDevice.getInstance(instrumentation).apply {
            executeShellCommand("cmd uimode night no")
            executeShellCommand("wm user-rotation free")
            waitForIdle()
        }
    }

    @After
    fun restoreLightMode() {
        UiDevice.getInstance(InstrumentationRegistry.getInstrumentation()).apply {
            executeShellCommand("cmd uimode night no")
            executeShellCommand("wm user-rotation free")
            waitForIdle()
        }
    }

    @Test
    fun locationsLaunchesWithVirtualizedList() {
        ActivityScenario.launch(BlueVpnServersActivity::class.java).use { scenario ->
            scenario.onActivity { activity ->
                assertTrue(findText(activity, "مکان‌ها")?.isShown == true)
                assertTrue(findRecycler(activity)?.isShown == true)
                assertTrue(findSearch(activity)?.isShown == true)
            }
        }
    }

    @Test
    fun searchSurvivesActivityRecreation() {
        ActivityScenario.launch(BlueVpnServersActivity::class.java).use { scenario ->
            scenario.onActivity { activity ->
                val search = requireNotNull(findSearch(activity))
                search.setText("آلمان")
                search.setSelection(search.text.length)
            }

            scenario.recreate()

            scenario.onActivity { activity ->
                val search = requireNotNull(findSearch(activity))
                assertEquals("آلمان", search.text.toString())
                assertTrue(search.isShown)
            }
        }
    }

    @Test
    fun locationsRootIsExplicitRtl() {
        ActivityScenario.launch(BlueVpnServersActivity::class.java).use { scenario ->
            scenario.onActivity { activity ->
                val content = activity.findViewById<ViewGroup>(android.R.id.content)
                val root = content.getChildAt(0)
                assertEquals(View.LAYOUT_DIRECTION_RTL, root.layoutDirection)
            }
        }
    }

    @Test
    fun captureLightAndDarkRtlSnapshots() {
        val instrumentation = InstrumentationRegistry.getInstrumentation()
        val device = UiDevice.getInstance(instrumentation)
        val qaDir = resolveQaDir()

        device.executeShellCommand("cmd uimode night no")
        ActivityScenario.launch(BlueVpnServersActivity::class.java).use { scenario ->
            scenario.onActivity { activity ->
                assertTrue(findRecycler(activity)?.isShown == true)
            }
            device.waitForIdle()
            device.executeShellCommand(
                "screencap -p $qaDir/locations-light-rtl.png"
            )

            device.executeShellCommand("cmd uimode night yes")
            scenario.recreate()
            scenario.onActivity { activity ->
                assertTrue(findRecycler(activity)?.isShown == true)
            }
            device.waitForIdle()
            device.executeShellCommand(
                "screencap -p $qaDir/locations-dark-rtl.png"
            )
        }
        device.executeShellCommand("cmd uimode night no")
    }

    private fun resolveQaDir(): String {
        // The host-side emulator QA script owns storage discovery/creation.
        // Do not re-probe writability through UiAutomation: Android 15 can deny
        // shell-style test -w checks from instrumentation even when adb/screencap
        // can write the already-prepared directory.
        val requested = InstrumentationRegistry.getArguments()
            .getString("bluevpnQaDir")
            .orEmpty()
            .trim()
        if (requested.startsWith("/") && !requested.contains("..")) {
            return requested
        }

        // Local/manual instrumentation fallback. CI always supplies bluevpnQaDir.
        return "/data/local/tmp/bluevpn-qa"
    }

    @Test
    fun searchAndTabSurviveFreshActivityAfterStatePersistence() {
        ActivityScenario.launch(BlueVpnServersActivity::class.java).use { scenario ->
            scenario.onActivity { activity ->
                val recent = requireNotNull(findText(activity, "اخیر"))
                assertTrue(recent.performClick())

                val search = requireNotNull(findSearch(activity))
                search.setText("Netherlands")
                search.setSelection(search.text.length)
            }
        }

        ActivityScenario.launch(BlueVpnServersActivity::class.java).use { scenario ->
            scenario.onActivity { activity ->
                val search = requireNotNull(findSearch(activity))
                assertEquals("Netherlands", search.text.toString())
                assertTrue(search.isShown)
                assertTrue(findText(activity, "اخیر")?.isShown == true)
            }
        }
    }

    @Test
    fun tabAndSearchSurviveRotationRecreation() {
        ActivityScenario.launch(BlueVpnServersActivity::class.java).use { scenario ->
            scenario.onActivity { activity ->
                val favorites = requireNotNull(findText(activity, "علاقه‌مندی"))
                assertTrue(favorites.performClick())

                val search = requireNotNull(findSearch(activity))
                search.setText("Germany")
                search.setSelection(search.text.length)

                activity.requestedOrientation = ActivityInfo.SCREEN_ORIENTATION_LANDSCAPE
            }

            scenario.recreate()

            scenario.onActivity { activity ->
                val search = requireNotNull(findSearch(activity))
                assertEquals("Germany", search.text.toString())
                assertTrue(search.isShown)
                assertTrue(findText(activity, "علاقه‌مندی")?.isShown == true)
            }
        }
    }

    private fun findSearch(activity: BlueVpnServersActivity): EditText? =
        findView(activity) { view ->
            view is EditText && view.hint?.toString() == "جست‌وجوی کشور یا سرور"
        } as? EditText

    private fun findRecycler(activity: BlueVpnServersActivity): RecyclerView? =
        findView(activity) { it is RecyclerView } as? RecyclerView

    private fun findText(activity: BlueVpnServersActivity, expected: String): TextView? =
        findView(activity) { view ->
            view is TextView && view.text?.toString() == expected
        } as? TextView

    private fun findView(
        activity: BlueVpnServersActivity,
        predicate: (View) -> Boolean,
    ): View? {
        val content = activity.findViewById<ViewGroup>(android.R.id.content)
        return findView(content, predicate)
    }

    private fun findView(
        root: View,
        predicate: (View) -> Boolean,
    ): View? {
        if (predicate(root)) return root
        if (root !is ViewGroup) return null

        for (index in 0 until root.childCount) {
            val match = findView(root.getChildAt(index), predicate)
            if (match != null) return match
        }
        return null
    }
}
