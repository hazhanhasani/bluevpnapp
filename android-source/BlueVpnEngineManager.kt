package com.v2ray.ang.bluevpn

import android.content.Context
import android.util.Log
import com.v2ray.ang.core.CoreConfigManager
import com.v2ray.ang.core.CoreServiceManager
import com.v2ray.ang.handler.MmkvManager
import java.util.concurrent.CopyOnWriteArrayList
import java.util.concurrent.Executors
import java.util.concurrent.atomic.AtomicLong
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
    private val commandGeneration = AtomicLong(0L)
    private val commandExecutor = Executors.newSingleThreadExecutor { task ->
        Thread(task, "bluevpn-engine-command").apply { isDaemon = true }
    }
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

    data class ConfigValidation(
        val ready: Boolean,
        val reason: String = "",
    )

    /**
     * Compile the exact hidden route through v2rayNG's own CoreConfigManager
     * before a VPN/TUN start is attempted. A location row is presentation
     * metadata; this proves that the underlying imported profile can become a
     * complete Xray runtime configuration.
     */
    fun validateExactConfig(context: Context, serverGuid: String): ConfigValidation {
        val app = context.applicationContext
        val guid = serverGuid.trim()
        if (guid.isBlank()) return ConfigValidation(false, "شناسه مسیر خالی است")

        val profile = MmkvManager.decodeServerConfig(guid)
            ?: return ConfigValidation(false, "کانفیگ مسیر در حافظه v2rayNG موجود نیست")
        if (!BlueVpnAccountManager.candidateAllowed(app, guid, profile.subscriptionId)) {
            return ConfigValidation(false, "کانفیگ مسیر متعلق به Pool فعال نیست")
        }

        return runCatching { CoreConfigManager.getV2rayConfig(app, guid) }
            .fold(
                onSuccess = { result ->
                    if (result.status && result.guid == guid && result.content.isNotBlank()) {
                        ConfigValidation(true)
                    } else {
                        Log.e(TAG, "v2rayNG config hydration failed guid=$guid: ${result.errorMessage}")
                        ConfigValidation(false, "v2rayNG نتوانست کانفیگ کامل این مسیر را بسازد")
                    }
                },
                onFailure = { error ->
                    Log.e(TAG, "v2rayNG config hydration crashed guid=$guid", error)
                    ConfigValidation(false, "ساخت کانفیگ واقعی Xray برای این مسیر ناموفق بود")
                },
            )
    }

    /**
     * Starts the currently safe runtime.
     *
     * sing-box is enabled only when a native binary and a validated native
     * profile are available. Until the dedicated sing-box VpnService owns the
     * Android TUN path, AUTO fails closed to the proven Xray bridge instead of
     * exposing a false "connected" state.
     */
    fun start(context: Context, serverGuid: String? = null) {
        val app = context.applicationContext
        val requested = preferredMode(app)
        val targetGuid = serverGuid.orEmpty().trim().ifBlank {
            MmkvManager.getSelectServer().orEmpty().trim()
        }
        val generation = commandGeneration.incrementAndGet()
        publish(State.PREPARING, requested, Engine.XRAY)

        // CoreServiceManager owns the proven v2rayNG/Xray compatibility path.
        // BlueVPN only validates entitlement and selects the exact GUID here;
        // it does not reconstruct VLESS/VMess/Trojan/SS profiles. Stop/start
        // ordering is driven by the service-state broadcast in HomeActivity,
        // because CoreVpnService runs in a separate Android process.
        commandExecutor.execute {
            if (generation != commandGeneration.get()) return@execute

            val profile = targetGuid.takeIf { it.isNotBlank() }
                ?.let { MmkvManager.decodeServerConfig(it) }
            if (
                profile == null ||
                !BlueVpnAccountManager.candidateAllowed(
                    app,
                    targetGuid,
                    profile.subscriptionId,
                )
            ) {
                val reason = "target server is outside the active entitlement pool"
                Log.e(TAG, reason)
                if (generation == commandGeneration.get()) {
                    publish(State.FAILED, requested, Engine.XRAY, reason)
                }
                return@execute
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

            // Do not launch a second TUN-capable process beside Xray. During
            // this migration phase sing-box is only staged/validated; Xray
            // remains the sole Android TUN owner until atomic handoff exists.
            if (singBoxReady) {
                Log.d(TAG, "sing-box native runtime/profile validated and staged")
            }

            if (generation != commandGeneration.get()) return@execute
            persistRuntime(app, Engine.XRAY, fallback)
            publish(State.STARTING, requested, Engine.XRAY, fallback)

            runCatching {
                // Use the exact official v2rayNG entry point. Passing the GUID
                // closes the race where BlueVPN changed global MMKV selection
                // before the previous CoreVpnService had actually stopped.
                check(CoreServiceManager.startVServiceExact(app, targetGuid)) {
                    "Xray service rejected the exact candidate start"
                }
            }.onFailure { error ->
                Log.e(TAG, "Xray start failed", error)
                if (generation == commandGeneration.get()) {
                    publish(
                        State.FAILED,
                        requested,
                        Engine.XRAY,
                        error.message ?: "core start failed",
                    )
                }
            }
        }
    }

    fun stop(context: Context) {
        val app = context.applicationContext
        val current = snapshot()
        val generation = commandGeneration.incrementAndGet()
        publish(
            State.STOPPING,
            current.requestedMode,
            current.activeEngine,
            current.fallbackReason,
        )

        commandExecutor.execute {
            runCatching { BlueVpnSingBoxProcess.stop() }
            runCatching {
                CoreServiceManager.stopVService(app)
            }.onFailure { error ->
                Log.e(TAG, "Xray stop request failed", error)
                if (generation == commandGeneration.get()) {
                    publish(
                        State.FAILED,
                        current.requestedMode,
                        current.activeEngine,
                        error.message ?: "Xray stop request failed",
                    )
                }
            }
            // CoreVpnService runs in :RunSoLibV2RayDaemon. The CoreServiceManager
            // singleton in the UI process cannot authoritatively answer
            // isRunning(), so never publish a fake IDLE from this process.
            // MainViewModel receives daemon STOP_SUCCESS/NOT_RUNNING and the
            // Activity calls markIdle() only after that cross-process handshake.
        }
    }

    fun markIdle() = transition(State.IDLE)
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
