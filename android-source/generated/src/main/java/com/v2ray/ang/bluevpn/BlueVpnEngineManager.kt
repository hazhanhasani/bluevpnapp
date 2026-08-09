package com.v2ray.ang.bluevpn

import android.content.Context
import android.util.Log
import com.v2ray.ang.core.CoreServiceManager
import java.util.concurrent.CopyOnWriteArrayList
import java.util.concurrent.atomic.AtomicReference

/**
 * Single runtime entry point for every BlueVPN screen.
 *
 * UI code must not call v2rayNG's CoreServiceManager directly anymore. This
 * boundary lets the legacy Xray bridge be replaced without rewriting screens.
 */
object BlueVpnEngineManager {
    private const val TAG = "BlueVpnEngine"
    private const val PREFS = "bluevpn_engine_runtime"
    private const val KEY_MODE = "preferred_mode"
    private const val KEY_LAST_ENGINE = "last_engine"
    private const val KEY_LAST_FALLBACK = "last_fallback"

    enum class Mode {
        AUTO,
        XRAY,
        SING_BOX,
    }

    enum class Engine {
        XRAY,
        SING_BOX,
    }

    enum class State {
        IDLE,
        PREPARING,
        STARTING,
        VERIFYING,
        CONNECTED,
        SWITCHING,
        STOPPING,
        FAILED,
    }

    data class Snapshot(
        val state: State,
        val requestedMode: Mode,
        val activeEngine: Engine,
        val fallbackReason: String? = null,
        val changedAt: Long = System.currentTimeMillis(),
    )

    fun interface Listener {
        fun onRuntimeChanged(snapshot: Snapshot)
    }

    private val listeners = CopyOnWriteArrayList<Listener>()
    private val snapshotRef = AtomicReference(
        Snapshot(
            state = State.IDLE,
            requestedMode = Mode.AUTO,
            activeEngine = Engine.XRAY,
        )
    )

    fun snapshot(): Snapshot = snapshotRef.get()

    fun addListener(listener: Listener) {
        listeners += listener
        listener.onRuntimeChanged(snapshot())
    }

    fun removeListener(listener: Listener) {
        listeners -= listener
    }

    fun preferredMode(context: Context): Mode {
        val raw = context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .getString(KEY_MODE, Mode.AUTO.name)
        return runCatching { Mode.valueOf(raw.orEmpty()) }.getOrDefault(Mode.AUTO)
    }

    fun setPreferredMode(context: Context, mode: Mode) {
        context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit()
            .putString(KEY_MODE, mode.name)
            .apply()
    }

    /**
     * Starts the currently safe runtime.
     *
     * sing-box is enabled only when a native binary and a validated native
     * profile are available. Until the dedicated sing-box VpnService owns the
     * Android TUN path, AUTO fails closed to the proven Xray bridge instead of
     * exposing a false "connected" state.
     */
    fun start(context: Context) {
        val app = context.applicationContext
        val requested = preferredMode(app)
        publish(State.PREPARING, requested, Engine.XRAY)

        if (!BlueVpnAccountManager.selectedServerAllowed(app)) {
            val reason = "selected server is outside the active entitlement pool"
            Log.e(TAG, reason)
            publish(State.FAILED, requested, Engine.XRAY, reason)
            return
        }

        val singBoxReady = BlueVpnSingBoxProcess.isRuntimeAvailable(app) &&
            BlueVpnSingBoxProcess.hasValidatedProfile(app)

        val fallback = when {
            requested == Mode.SING_BOX && !singBoxReady ->
                "sing-box runtime/profile is not ready; Xray safety fallback"
            requested == Mode.SING_BOX ->
                "sing-box native core is staged; Android TUN bridge still uses Xray"
            else -> null
        }

        // Do not launch a second TUN-capable process beside Xray. During this
        // migration phase sing-box is packaged and used for native profile
        // validation only. A dedicated BlueVpnService will transfer TUN
        // ownership atomically in phase 2.
        if (singBoxReady) {
            Log.d(TAG, "sing-box native runtime/profile validated and staged")
        }

        persistRuntime(app, Engine.XRAY, fallback)
        publish(State.STARTING, requested, Engine.XRAY, fallback)
        CoreServiceManager.startVService(app)
    }

    fun stop(context: Context) {
        val app = context.applicationContext
        val current = snapshot()
        publish(State.STOPPING, current.requestedMode, current.activeEngine, current.fallbackReason)
        runCatching { BlueVpnSingBoxProcess.stop() }
        CoreServiceManager.stopVService(app)
        publish(State.IDLE, current.requestedMode, current.activeEngine, current.fallbackReason)
    }

    fun markVerifying() = transition(State.VERIFYING)
    fun markConnected() = transition(State.CONNECTED)
    fun markSwitching() = transition(State.SWITCHING)
    fun markFailed(reason: String? = null) {
        val current = snapshot()
        publish(State.FAILED, current.requestedMode, current.activeEngine, reason ?: current.fallbackReason)
    }

    private fun transition(state: State) {
        val current = snapshot()
        publish(state, current.requestedMode, current.activeEngine, current.fallbackReason)
    }

    private fun persistRuntime(context: Context, engine: Engine, fallback: String?) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit()
            .putString(KEY_LAST_ENGINE, engine.name)
            .putString(KEY_LAST_FALLBACK, fallback)
            .apply()
    }

    private fun publish(
        state: State,
        requestedMode: Mode,
        activeEngine: Engine,
        fallbackReason: String? = null,
    ) {
        val next = Snapshot(
            state = state,
            requestedMode = requestedMode,
            activeEngine = activeEngine,
            fallbackReason = fallbackReason,
        )
        snapshotRef.set(next)
        listeners.forEach { listener ->
            runCatching { listener.onRuntimeChanged(next) }
        }
    }
}
