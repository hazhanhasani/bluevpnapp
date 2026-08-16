package com.v2ray.ang.bluevpn

import android.content.Context
import android.content.SharedPreferences
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.os.Build
import android.os.SystemClock
import android.telephony.TelephonyManager
import com.v2ray.ang.dto.entities.ProfileItem
import com.v2ray.ang.enums.EConfigType
import com.v2ray.ang.handler.MmkvManager
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.cancelAndJoin
import kotlinx.coroutines.delay
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import kotlinx.coroutines.withTimeoutOrNull
import java.io.BufferedInputStream
import java.io.BufferedOutputStream
import java.io.File
import java.net.HttpURLConnection
import java.net.InetSocketAddress
import java.net.Proxy
import java.net.ServerSocket
import java.net.Socket
import java.net.URL
import java.security.MessageDigest
import java.util.Locale
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicLong
import kotlin.math.max
import kotlin.math.min

/**
 * Application-lifecycle supervisor for the Free Aether/WARP process.
 *
 * Long-running I/O never executes while [stateMutex] is held. Every connect has
 * a generation token, so a new connect/disconnect immediately invalidates old
 * endpoint races, SOCKS waits and validation work.
 */
object BlueVpnWarpEngine {
    const val BRIDGE_SUBSCRIPTION_ID = "bluevpn_free_warp_aether"
    private const val SOCKS_HOST = "127.0.0.1"
    private const val NATIVE_NAME = "libbluevpn_aether.so"
    private const val PREFS = "bluevpn_warp_runtime_v3"
    private const val PORT_MIN = 1819
    private const val PORT_MAX = 1849
    private const val IR_POISON_DISTINCT_STRATEGIES = 3
    private const val IR_IDENTITY_ROTATION_COOLDOWN_MS = 6 * 60 * 60_000L
    private const val IR_POISON_TTL_MS = 24 * 60 * 60_000L
    private const val AETHER_MIGRATION_MARKER = "bluevpn-aether-migrated-v1"
    private const val MAX_QUARANTINED_IDENTITIES = 2
        private const val MAX_HISTORY = 24

    enum class State { STOPPED, PREPARING, TRYING_CACHED_ROUTE, RACING_ENDPOINTS, SCANNING, AETHER_DATA_PLANE_VALIDATING, SOCKS_READY, STARTING_XRAY_BRIDGE, VERIFYING_TUNNEL, CONNECTED, RECONNECTING, SWITCHING_STRATEGY, FALLING_BACK_TO_POOL, STOPPING, FAILED }
    enum class Strategy { MASQUE_H3, MASQUE_H2, MASQUE_H2_FRAGMENT, WIREGUARD, GOOL }
    enum class ErrorCode {
        CONFIG_INVALID, DNS_FAILED, TCP_TIMEOUT, UDP_BLOCKED, TLS_FAILED, MASQUE_FAILED, WIREGUARD_FAILED,
        EXIT_IRAN, EXIT_VALIDATION_FAILED, SOCKS_FAILED, AETHER_START_FAILED, AETHER_CRASHED, XRAY_BRIDGE_FAILED,
        TUN_FAILED, PORT_IN_USE, NETWORK_CHANGED, NO_INTERNET, STOP_TIMEOUT, FREE_POOL_FAILED, BACKEND_UNAVAILABLE,
        WARP_BINARY_MISSING, WARP_UNSUPPORTED_ABI, WARP_PORT_OCCUPIED, WARP_PROCESS_EXITED, WARP_INTERACTIVE_STALL,
        WARP_START_TIMEOUT, WARP_NO_ENDPOINT, WARP_SOCKS_HANDSHAKE_FAILED, WARP_DATA_PLANE_FAILED,
        WARP_EXIT_COUNTRY_BLOCKED, WARP_EXIT_TRACE_UNAVAILABLE, WARP_BRIDGE_CORE_FAILED, WARP_POST_BRIDGE_VERIFY_FAILED,
        WARP_NETWORK_CHANGED, WARP_RECONNECT_EXHAUSTED, WARP_FALLBACK_STARTED, WARP_CANCELLED, UNKNOWN, WARP_UNKNOWN
    }

    data class Failure(val code: ErrorCode, val stage: State, val strategy: Strategy?, val detail: String) : RuntimeException("${code.name}: $detail")
    data class Prepared(val guid: String, val strategy: Strategy, val port: Int, val startupMs: Long)
    private data class EdgeCandidate(val host: String, val port: Int) { val authority: String get() = "$host:$port" }
    private data class NetworkShape(val signature: String, val ipv4: Boolean, val ipv6: Boolean)
    private data class Attempt(val process: Process, val port: Int, val candidate: EdgeCandidate, val started: Long)
    private data class ProbeWin(val attempt: Attempt, val latencyMs: Long, val country: String?)
    private sealed class ProbeOutcome { data class Success(val win: ProbeWin): ProbeOutcome(); data class Failed(val failure: Failure): ProbeOutcome() }
    private data class Validation(val ok: Boolean, val country: String?, val traceSeen: Boolean, val warp: Boolean)

    private val MASQUE_UDP_PORTS = intArrayOf(443, 500, 1701, 4500, 4443, 8443, 8095)
    private val WIREGUARD_UDP_PORTS = intArrayOf(2408, 500, 1701, 4500)

    private val stateMutex = Mutex()
    private val launchMutex = Mutex()
    private val generation = AtomicLong(0)
    @Volatile private var process: Process? = null
    @Volatile private var bridgeGuid = ""
    @Volatile private var activePort = 0
    @Volatile private var activeStrategy: Strategy? = null
    @Volatile private var connectJob: Job? = null
    @Volatile var state: State = State.STOPPED
        private set
    @Volatile private var lastFailure: Failure? = null
    @Volatile private var lastAttemptStartedAt: Long = 0L

    fun lastFailure(): Failure? = lastFailure
    fun diagnosticSummary(): String {
        val f = lastFailure ?: return "WARP runtime has no recorded failure"
        val strategy = f.strategy?.name ?: "NONE"
        return "${f.code.name} • ${f.stage.name} • $strategy • ${sanitizeDiagnostic(f.detail)}"
    }

    fun supported(context: Context): Boolean = Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && nativeExecutable(context).let { it.isFile && it.canExecute() }
    fun isRunning(): Boolean = process?.isAlive == true
    fun currentStrategy(): Strategy? = activeStrategy

    fun isBridgeGuid(guid: String, profile: ProfileItem? = null): Boolean {
        if (guid.isBlank()) return false
        val resolved = profile ?: MmkvManager.decodeServerConfig(guid) ?: return false
        return resolved.subscriptionId == BRIDGE_SUBSCRIPTION_ID && resolved.configType == EConfigType.SOCKS &&
            resolved.server == SOCKS_HOST && resolved.serverPort?.toIntOrNull()?.let { it in PORT_MIN..PORT_MAX } == true
    }

    suspend fun prepare(context: Context): Result<String> = runCatching { prepareAdaptive(context).guid }

    suspend fun prepareAdaptive(context: Context): Prepared = withContext(Dispatchers.IO) {
        val app = context.applicationContext
        val myGeneration = generation.incrementAndGet()
        connectJob = kotlin.coroutines.coroutineContext[Job]
        val started = SystemClock.elapsedRealtime()
        state = State.PREPARING
        lastFailure = null
        lastAttemptStartedAt = SystemClock.elapsedRealtime()
        stopProcessOnly(wait = true)
        if (!supported(app)) throw Failure(ErrorCode.WARP_UNSUPPORTED_ABI, state, null, "Aether runtime is not executable for this ABI")

        val policy = BlueVpnAccountManager.freeAccessSnapshot(app)
        val shape = networkShape(app)
        val prefs = app.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        recoverPoisonedIdentityIfNeeded(app, prefs, shape.signature)
        val strategies = strategyOrder(prefs, shape.signature, policy)
        if (strategies.isEmpty()) throw Failure(ErrorCode.CONFIG_INVALID, state, null, "No WARP strategy is allowed by policy")
        val totalDeadline = started + policy.warpTotalTimeoutSeconds.coerceIn(30, 90) * 1000L
        var last: Failure? = null
        var attemptedStrategies = 0
        val skippedStrategies = mutableListOf<Strategy>()
        val allStrategiesBackedOff =
            strategies.isNotEmpty() &&
            strategies.all { isBackedOff(prefs, shape.signature, it) }

        // Never allow persisted cooldown state to starve an explicit user
        // connection attempt. If every allowed strategy is backed off, this
        // attempt gets one bounded recovery pass across ALL allowed strategies.
        // Backoff remains persisted for later automatic/background work.
        val recoveryProbeAll = allStrategiesBackedOff

        try {
            for ((index, strategy) in strategies.withIndex()) {
                ensureGeneration(myGeneration, strategy)
                if (SystemClock.elapsedRealtime() >= totalDeadline) break

                // Aether v1.6 already performs endpoint discovery, cooldown, multi-port probing,
                // data-plane validation and quick-reconnect internally. Running several Aether
                // processes in parallel against one persistent WARP identity creates avoidable
                // registration/socket contention and can make restricted networks less reliable.
                // Keep exactly one Aether process alive per attempt and delegate the actual race
                // to Aether's native scanner.
                val quick = policy.warpQuickReconnect && cachedStrategy(prefs, shape.signature) == strategy && isLkgFresh(prefs, shape.signature)
                if (isBackedOff(prefs, shape.signature, strategy) && !quick && !recoveryProbeAll) {
                    skippedStrategies += strategy
                    continue
                }

                attemptedStrategies += 1
                ensureGeneration(myGeneration, strategy)
                state = when {
                    quick -> State.TRYING_CACHED_ROUTE
                    policy.warpEndpointRacingEnabled -> State.RACING_ENDPOINTS
                    index == 0 -> State.SCANNING
                    else -> State.SWITCHING_STRATEGY
                }
                val scanPlan = freshScanPlan(policy.warpScanMode)
                val passes = buildList<Pair<Boolean, String?>> {
                    if (quick) add(true to null)
                    scanPlan.forEach { add(false to it) }
                }
                for ((passIndex, pass) in passes.withIndex()) {
                    val quickPass = pass.first
                    val scanMode = pass.second
                    ensureGeneration(myGeneration, strategy)
                    if (SystemClock.elapsedRealtime() >= totalDeadline) break
                    try {
                        if (!quickPass && quick) {
                            state = State.SCANNING
                            stopProcessOnly(wait = true)
                        }
                        val remainingMs =
                            (totalDeadline - SystemClock.elapsedRealtime()).coerceAtLeast(0L)
                        val remainingStrategies =
                            (strategies.size - index).coerceAtLeast(1)
                        val fairShareSeconds =
                            (remainingMs / remainingStrategies / 1000L)
                                .toInt()
                                .coerceIn(6, 20)
                        val configuredBudget = if (quickPass) {
                            policy.warpWarmTimeoutSeconds.coerceIn(4, 12)
                        } else {
                            policy.warpColdTimeoutSeconds.coerceIn(8, 40)
                        }
                        val passBudget =
                            min(configuredBudget, fairShareSeconds).coerceAtLeast(4)
                        val port = startWithPortRetries(
                            app,
                            strategy,
                            quickPass,
                            policy,
                            null,
                            shape,
                            scanMode,
                        )
                        val ok = withTimeoutOrNull(
                            min(passBudget, policy.warpStartTimeoutSeconds.coerceIn(3, 40)) * 1000L
                        ) {
                            awaitValidatedDataPlane(
                                myGeneration,
                                process ?: throw Failure(ErrorCode.AETHER_START_FAILED, state, strategy, "Aether process missing"),
                                port,
                                strategy,
                                policy,
                            )
                        } ?: false
                        if (!ok) {
                            throw Failure(
                                ErrorCode.WARP_START_TIMEOUT,
                                state,
                                strategy,
                                if (quickPass) {
                                    "Cached route exceeded startup budget"
                                } else {
                                    "Fresh scan ${scanMode ?: "auto"} exceeded startup budget"
                                },
                            )
                        }
                        val startupLatency = SystemClock.elapsedRealtime() - started
                        recordStrategySuccess(prefs, shape.signature, strategy, startupLatency)
                        BlueVpnIntelligenceCore.recordRouteOutcome(
                            context = app,
                            guid = "warp:${strategy.name}",
                            success = true,
                            latencyMs = startupLatency,
                        )
                        clearIranPoisonState(prefs, shape.signature)
                        activeStrategy = strategy
                        activePort = port
                        state = State.SOCKS_READY
                        return@withContext Prepared(
                            ensureBridgeProfile(port),
                            strategy,
                            port,
                            SystemClock.elapsedRealtime() - started,
                        )
                    } catch (f: Failure) {
                        last = f
                        lastFailure = f
                        // One bad cached endpoint or one bad native-scan winner
                        // must not terminate a protocol. Retry with a fresh scan,
                        // then an alternate scan profile, before condemning the
                        // strategy. The global deadline still bounds the attempt.
                        val hasAnotherPass = passIndex < passes.lastIndex
                        val retryFresh =
                            hasAnotherPass &&
                            (quickPass || retryableFreshScanFailure(f.code))
                        if (retryFresh) {
                            val next = passes.getOrNull(passIndex + 1)
                            persistDiagnostic(
                                app,
                                Failure(
                                    f.code,
                                    f.stage,
                                    strategy,
                                    "Route candidate failed; retrying strategy=${strategy.name} next_scan=${next?.second ?: "fresh"}: ${f.detail}",
                                ),
                                SystemClock.elapsedRealtime() - lastAttemptStartedAt,
                            )
                            stopProcessOnly(wait = true)
                            continue
                        }
                        recordFailure(prefs, shape.signature, strategy, f.code)
                        BlueVpnIntelligenceCore.recordRouteOutcome(
                            context = app,
                            guid = "warp:${strategy.name}",
                            success = false,
                            reason = "${f.code.name}:${f.detail}",
                            exitCountry = if (f.code == ErrorCode.EXIT_IRAN) "IR" else "",
                        )
                        persistDiagnostic(app, f, SystemClock.elapsedRealtime() - lastAttemptStartedAt)
                        stopProcessOnly(wait = true)
                    }
                }
            }
            state = State.FAILED
            val terminal = last ?: Failure(
                ErrorCode.WARP_RECONNECT_EXHAUSTED,
                state,
                null,
                "No WARP strategy completed; attempted=$attemptedStrategies skipped=${skippedStrategies.joinToString(",") { it.name }} recovery_all=$recoveryProbeAll",
            )
            lastFailure = terminal
            persistDiagnostic(app, terminal, SystemClock.elapsedRealtime() - lastAttemptStartedAt)
            throw terminal
        } finally {
            if (connectJob === kotlin.coroutines.coroutineContext[Job]) connectJob = null
        }
    }

    suspend fun stopAsync() = withContext(Dispatchers.IO) {
        generation.incrementAndGet()
        connectJob?.cancelAndJoin()
        stateMutex.withLock {
            state = State.STOPPING
            stopProcessOnly(wait = true)
            activePort = 0; activeStrategy = null; state = State.STOPPED
        }
    }

    fun stop() { generation.incrementAndGet(); connectJob?.cancel(); state = State.STOPPING; stopProcessOnly(wait = false); activePort = 0; activeStrategy = null; state = State.STOPPED }
    fun markBridgeStarting() { state = State.STARTING_XRAY_BRIDGE }
    fun markTunnelVerifying() { state = State.VERIFYING_TUNNEL }
    fun markConnected() { state = State.CONNECTED }
    fun markFallback() { state = State.FALLING_BACK_TO_POOL }

    private fun strategyOrder(prefs: SharedPreferences, sig: String, p: BlueVpnFreeAccessSnapshot): List<Strategy> {
        val cached = cachedStrategy(prefs, sig)?.takeIf { cachedAllowed(it, p) && isLkgFresh(prefs, sig) }
        val allowed = buildList {
            if (p.warpAllowedTransports.contains("h3")) add(Strategy.MASQUE_H3)
            if (p.warpH2Enabled && p.warpAllowedTransports.contains("h2")) add(Strategy.MASQUE_H2)
            if (p.warpH2Enabled && p.warpFragmentEnabled && p.warpAllowedTransports.contains("h2_fragment")) add(Strategy.MASQUE_H2_FRAGMENT)
            if (p.warpWireGuardEnabled && p.warpAllowedTransports.contains("wireguard")) add(Strategy.WIREGUARD)
            if (p.warpGoolEnabled && p.warpAllowedTransports.contains("gool")) add(Strategy.GOOL)
        }
        // Adaptive=false disables historical re-ordering, not safety fallback.
        // A broken first transport must never terminate the whole WARP attempt
        // while other explicitly-enabled transports are still available.
        val ranked = if (p.warpAdaptiveEnabled) {
            allowed.sortedByDescending { strategyScore(prefs, sig, it) }
        } else {
            allowed
        }
        return buildList {
            if (p.warpQuickReconnect && cached != null) add(cached)
            addAll(ranked)
        }.distinct()
    }

    private fun cachedAllowed(strategy: Strategy, p: BlueVpnFreeAccessSnapshot): Boolean = when (strategy) {
        Strategy.MASQUE_H3 -> p.warpAllowedTransports.contains("h3")
        Strategy.MASQUE_H2 -> p.warpH2Enabled && p.warpAllowedTransports.contains("h2")
        Strategy.MASQUE_H2_FRAGMENT -> p.warpH2Enabled && p.warpFragmentEnabled && p.warpAllowedTransports.contains("h2_fragment")
        Strategy.WIREGUARD -> p.warpWireGuardEnabled && p.warpAllowedTransports.contains("wireguard")
        Strategy.GOOL -> p.warpGoolEnabled && p.warpAllowedTransports.contains("gool")
    }

    private suspend fun startWithPortRetries(context: Context, strategy: Strategy, quick: Boolean, policy: BlueVpnFreeAccessSnapshot, peer: String?, shape: NetworkShape, scanModeOverride: String? = null): Int {
        var last: Throwable? = null
        repeat(3) {
            val port = reservePort()
            try {
                val p = launchMutex.withLock {
                    process?.takeIf { it.isAlive }?.let { stale -> terminateProcess(stale, true) }
                    buildProcess(context, strategy, port, quick, policy, peer, shape, scanModeOverride).start().also { started ->
                        process = started
                        activePort = port
                        activeStrategy = strategy
                    }
                }
                runCatching { p.outputStream.close() }
                delay(100L)
                if (!p.isAlive) {
                    terminateProcess(p, false)
                    if (process === p) process = null
                    last = IllegalStateException("Aether exited before binding loopback port")
                    return@repeat
                }
                return port
            } catch (t: Throwable) { last = t }
        }
        throw Failure(ErrorCode.AETHER_START_FAILED, state, strategy, last?.message ?: "Aether process could not start")
    }

    private fun buildProcess(context: Context, strategy: Strategy, port: Int, quick: Boolean, p: BlueVpnFreeAccessSnapshot, peer: String?, shape: NetworkShape, scanModeOverride: String? = null): ProcessBuilder {
        val dataDir = persistentAetherDataDir(context)
        val config = File(dataDir, "aether.toml")
        val command = mutableListOf(nativeExecutable(context).absolutePath, "--bind", "$SOCKS_HOST:$port", "--config", config.absolutePath)
        when (strategy) {
            Strategy.MASQUE_H3 -> command += "--masque"
            Strategy.MASQUE_H2 -> command += listOf("--masque", "--h2")
            Strategy.MASQUE_H2_FRAGMENT -> command += listOf("--masque", "--h2", "--fragment", "--fragment-size", p.warpFragmentSize, "--fragment-delay", p.warpFragmentDelay)
            Strategy.WIREGUARD -> command += "--wg"
            Strategy.GOOL -> command += "--gool"
        }
        if (!peer.isNullOrBlank()) command += listOf("--peer", peer)
        command += if (quick && peer.isNullOrBlank()) "--quick-reconnect" else "--no-quick-reconnect"
        if (peer.isNullOrBlank()) {
            command += listOf("--scan", normalizedScanMode(scanModeOverride ?: p.warpScanMode))
        }
        command += listOf("--noize", normalizedNoize(strategy, p.warpNoizeProfile))
        when (effectiveIpMode(p.warpIpMode, shape)) { "v4" -> command += "-4"; else -> command += "--dual" }
        if (strategy in setOf(Strategy.MASQUE_H3, Strategy.MASQUE_H2, Strategy.MASQUE_H2_FRAGMENT)) {
            command += listOf("--startup-secs", p.warpStartTimeoutSeconds.coerceIn(3, 40).toString())
        }
        command += listOf("--log-level", "info", "--perf", "medium")
        validateCommand(command, peer)
        rotateLogs(context)
        val log = File(context.cacheDir, "bluevpn-aether.log")
        return ProcessBuilder(command).directory(dataDir).redirectErrorStream(true).redirectOutput(ProcessBuilder.Redirect.appendTo(log)).also {
            it.environment()["HOME"] = dataDir.absolutePath
            it.environment()["XDG_CONFIG_HOME"] = dataDir.absolutePath
            it.environment()["XDG_DATA_HOME"] = dataDir.absolutePath
            it.environment()["TMPDIR"] = context.cacheDir.absolutePath
            it.environment()["AETHER_QUICK_RECONNECT"] = if (quick) "1" else "0"
            it.environment()["BLUEVPN_SCAN_MODE"] = normalizedScanMode(scanModeOverride ?: p.warpScanMode)
            it.environment()["RUST_LOG"] = "info"
        }
    }

    private fun validateCommand(command: List<String>, peer: String?) {
        if ("--masque" in command && "--wg" in command) throw Failure(ErrorCode.CONFIG_INVALID, state, activeStrategy, "MASQUE and WireGuard flags cannot be combined")
        if ("--gool" in command && ("--masque" in command || "--wg" in command)) throw Failure(ErrorCode.CONFIG_INVALID, state, activeStrategy, "GOOL cannot be combined with another transport")
        if ("--fragment" in command && "--h2" !in command) throw Failure(ErrorCode.CONFIG_INVALID, state, activeStrategy, "Fragment requires H2")
        if (!peer.isNullOrBlank() && "--scan" in command) throw Failure(ErrorCode.CONFIG_INVALID, state, activeStrategy, "Direct peer fast path must not run native scan")
    }

    private suspend fun awaitValidatedDataPlane(gen: Long, p: Process, port: Int, strategy: Strategy, policy: BlueVpnFreeAccessSnapshot): Boolean = awaitValidation(gen, p, port, strategy, policy).ok

    private suspend fun awaitValidation(gen: Long, p: Process, port: Int, strategy: Strategy, policy: BlueVpnFreeAccessSnapshot): Validation {
        var sawPort = false
        while (generation.get() == gen) {
            if (!p.isAlive) throw Failure(ErrorCode.AETHER_CRASHED, state, strategy, "Aether exited with ${runCatching { p.exitValue() }.getOrDefault(-1)}")
            if (canTcpConnect(port, 120)) {
                sawPort = true
                state = State.AETHER_DATA_PLANE_VALIDATING
                // Binding the TCP socket can happen before the embedded SOCKS
                // data plane is fully ready. Treat a transient greeting/CONNECT
                // failure as "not ready yet" and keep polling inside the caller's
                // bounded startup timeout instead of killing the whole strategy.
                if (socksGreetingAndRemoteConnect(port, "www.cloudflare.com", 443, 1200)) {
                    return validateViaSocks(port, policy, strategy)
                }
            }
            delay(if (sawPort) 180L else 70L)
        }
        throw Failure(ErrorCode.WARP_CANCELLED, state, strategy, "Connection generation changed")
    }

    private fun validateViaSocks(port: Int, policy: BlueVpnFreeAccessSnapshot, strategy: Strategy): Validation {
        val proxy = Proxy(Proxy.Type.SOCKS, InetSocketAddress(SOCKS_HOST, port))
        var traceSeen = false; var traceWarp = false; var country: String? = null
        val traces = listOf("https://www.cloudflare.com/cdn-cgi/trace", "https://cloudflare.com/cdn-cgi/trace")
        for (raw in traces) {
            val body = fetchText(proxy, raw, 1800, 4096) ?: continue
            traceSeen = true
            traceWarp = body.lineSequence().any { it == "warp=on" || it == "warp=plus" }
            country = body.lineSequence().firstOrNull { it.startsWith("loc=") }?.substringAfter("loc=")?.trim()?.uppercase(Locale.US)?.takeIf { it.matches(Regex("[A-Z]{2}")) }
            if (country != null) break
        }
        // Exit-country blocking is entirely policy-driven. IR is allowed when the
        // administrator removes IR from blocked_exit_countries.
        if (country != null && country in policy.warpBlockedExitCountries) {
            throw Failure(if (country == "IR") ErrorCode.EXIT_IRAN else ErrorCode.WARP_EXIT_COUNTRY_BLOCKED, state, strategy, "Blocked WARP exit country: $country")
        }
        if (policy.warpRequireExitTrace && (!traceSeen || country == null)) throw Failure(ErrorCode.EXIT_VALIDATION_FAILED, state, strategy, "Exit country could not be validated")
        if (policy.warpRequireExitTrace && !traceWarp) throw Failure(ErrorCode.EXIT_VALIDATION_FAILED, state, strategy, "Cloudflare trace did not confirm WARP")

        val internet = listOf("https://cp.cloudflare.com/generate_204", "https://www.google.com/generate_204", "https://www.gstatic.com/generate_204")
            .any { httpOk(proxy, it, 1600) }
        if (!internet) throw Failure(ErrorCode.NO_INTERNET, state, strategy, "No tunneled HTTPS probe succeeded")
        return Validation(true, country, traceSeen, traceWarp)
    }

    private fun fetchText(proxy: Proxy, raw: String, timeout: Int, maxBytes: Int): String? = runCatching {
        val c = URL(raw).openConnection(proxy) as HttpURLConnection
        try { c.connectTimeout = timeout; c.readTimeout = timeout; c.instanceFollowRedirects = false; c.setRequestProperty("User-Agent", "BlueVPN-WARP-Exit-Probe/3")
            if (c.responseCode !in 200..299) null else c.inputStream.bufferedReader().use { it.readText().take(maxBytes) }
        } finally { c.disconnect() }
    }.getOrNull()

    private fun httpOk(proxy: Proxy, raw: String, timeout: Int): Boolean = runCatching {
        val c = URL(raw).openConnection(proxy) as HttpURLConnection
        try { c.connectTimeout = timeout; c.readTimeout = timeout; c.instanceFollowRedirects = false; c.setRequestProperty("User-Agent", "BlueVPN-WARP-Probe/3"); c.responseCode in 200..399 }
        finally { c.disconnect() }
    }.getOrDefault(false)

    private fun socksGreetingAndRemoteConnect(port: Int, host: String, remotePort: Int, timeoutMs: Int): Boolean = runCatching {
        Socket().use { socket ->
            socket.soTimeout = timeoutMs; socket.connect(InetSocketAddress(SOCKS_HOST, port), timeoutMs)
            val out = BufferedOutputStream(socket.getOutputStream()); val input = BufferedInputStream(socket.getInputStream())
            out.write(byteArrayOf(0x05, 0x01, 0x00)); out.flush(); if (input.read() != 0x05 || input.read() != 0x00) return@runCatching false
            val hb = host.toByteArray(Charsets.US_ASCII); out.write(byteArrayOf(0x05, 0x01, 0x00, 0x03, hb.size.toByte())); out.write(hb); out.write(byteArrayOf((remotePort ushr 8).toByte(), remotePort.toByte())); out.flush()
            if (input.read() != 0x05 || input.read() != 0x00) return@runCatching false
            val rsv = input.read(); val atyp = input.read(); if (rsv < 0 || atyp < 0) return@runCatching false
            val skip = when (atyp) { 1 -> 4; 3 -> input.read(); 4 -> 16; else -> -1 }; if (skip < 0) return@runCatching false
            repeat(skip + 2) { if (input.read() < 0) return@runCatching false }; true
        }
    }.getOrDefault(false)

    private fun reservePort(): Int {
        val offset = ((SystemClock.elapsedRealtime() xor generation.get()) % (PORT_MAX - PORT_MIN + 1)).toInt()
        repeat(PORT_MAX - PORT_MIN + 1) { idx ->
            val port = PORT_MIN + (offset + idx).floorMod(PORT_MAX - PORT_MIN + 1)
            val free = runCatching { ServerSocket().use { it.reuseAddress = false; it.bind(InetSocketAddress(SOCKS_HOST, port)) } }.isSuccess
            if (free) return port
        }
        throw Failure(ErrorCode.PORT_IN_USE, state, activeStrategy, "No free loopback port in $PORT_MIN..$PORT_MAX")
    }
    private fun Int.floorMod(divisor: Int): Int = ((this % divisor) + divisor) % divisor
    private fun canTcpConnect(port: Int, timeoutMs: Int): Boolean = runCatching { Socket().use { it.connect(InetSocketAddress(SOCKS_HOST, port), timeoutMs) }; true }.getOrDefault(false)

    private fun ensureBridgeProfile(port: Int): String {
        MmkvManager.decodeServerList(BRIDGE_SUBSCRIPTION_ID).forEach { guid -> val p = MmkvManager.decodeServerConfig(guid); if (p?.serverPort == port.toString() && isBridgeGuid(guid, p)) { bridgeGuid = guid; return guid } }
        MmkvManager.removeServerViaSubid(BRIDGE_SUBSCRIPTION_ID)
        val profile = ProfileItem.create(EConfigType.SOCKS).apply { subscriptionId = BRIDGE_SUBSCRIPTION_ID; remarks = "BlueVPN Free"; description = "BlueVPN Free local bridge"; server = SOCKS_HOST; serverPort = port.toString() }
        return MmkvManager.encodeServerConfig("", profile).also { bridgeGuid = it }
    }

    private fun promoteWinner(attempt: Attempt, strategy: Strategy) { stopProcessOnly(wait = true); process = attempt.process; activePort = attempt.port; activeStrategy = strategy }
    private fun stopProcessOnly(wait: Boolean) { val p = process; process = null; if (p != null) terminateProcess(p, wait) }
    private fun terminateProcess(p: Process, wait: Boolean) {
        runCatching { p.outputStream.close() }; runCatching { p.inputStream.close() }; runCatching { p.errorStream.close() }
        if (!p.isAlive) return
        runCatching { p.destroy() }
        if (wait) runCatching { if (!p.waitFor(350, TimeUnit.MILLISECONDS)) p.destroyForcibly() }
        else if (p.isAlive) runCatching { p.destroyForcibly() }
        if (wait && p.isAlive) runCatching { p.waitFor(250, TimeUnit.MILLISECONDS) }
    }

    private fun persistentAetherDataDir(context: Context): File {
        val target = File(context.noBackupFilesDir, "bluevpn-aether-v1")
        val migrationMarker = File(context.noBackupFilesDir, AETHER_MIGRATION_MARKER)
        if (!target.exists() && !migrationMarker.exists()) {
            val legacy = File(context.filesDir, "bluevpn-aether")
            if (legacy.isDirectory) runCatching { legacy.copyRecursively(target, overwrite = false) }
            runCatching { migrationMarker.createNewFile() }
        }
        target.mkdirs()
        return target
    }

    private fun recoverPoisonedIdentityIfNeeded(context: Context, prefs: SharedPreferences, sig: String) {
        val mask = prefs.getInt("ir_mask:$sig", 0)
        val poisonedAt = prefs.getLong("ir_poisoned_at:$sig", 0L)
        val now = System.currentTimeMillis()
        if (poisonedAt > 0L && now - poisonedAt > IR_POISON_TTL_MS) {
            clearIranPoisonState(prefs, sig)
            return
        }
        if (Integer.bitCount(mask) < IR_POISON_DISTINCT_STRATEGIES) return

        val lastRotation = prefs.getLong("ir_identity_rotated_at:$sig", 0L)
        if (lastRotation > 0L && now - lastRotation < IR_IDENTITY_ROTATION_COOLDOWN_MS) {
            // A fresh identity has already been attempted recently. Do not create
            // registration storms; let the caller continue toward the configured
            // non-Iran Free Pool fallback or fail closed.
            return
        }

        stopProcessOnly(wait = true)
        val current = File(context.noBackupFilesDir, "bluevpn-aether-v1")
        if (current.exists()) {
            val quarantine = File(context.noBackupFilesDir, "bluevpn-aether-ir-quarantine-$now")
            runCatching {
                if (!current.renameTo(quarantine)) {
                    current.copyRecursively(quarantine, overwrite = false)
                    current.deleteRecursively()
                }
            }
        }
        cleanupQuarantinedIdentities(context)
        File(context.noBackupFilesDir, AETHER_MIGRATION_MARKER).runCatching { createNewFile() }
        persistentAetherDataDir(context)

        val edit = prefs.edit()
            .putLong("ir_identity_rotated_at:$sig", now)
            .remove("ir_mask:$sig")
            .remove("ir_poisoned_at:$sig")
            .remove("lkg:$sig")
            .remove("lkg_at:$sig")
        Strategy.values().forEach { strategy ->
            edit.remove("backoff:$sig:${strategy.name}")
                .remove("fail:$sig:${strategy.name}")
                .remove("edge:$sig:${strategy.name}")
                .remove("edge_at:$sig:${strategy.name}")
        }
        edit.apply()
    }

    private fun cleanupQuarantinedIdentities(context: Context) {
        context.noBackupFilesDir.listFiles()
            ?.filter { it.isDirectory && it.name.startsWith("bluevpn-aether-ir-quarantine-") }
            ?.sortedByDescending { it.lastModified() }
            ?.drop(MAX_QUARANTINED_IDENTITIES)
            ?.forEach { runCatching { it.deleteRecursively() } }
    }

    private fun recordIranExit(prefs: SharedPreferences, sig: String, strategy: Strategy) {
        val bit = 1 shl strategy.ordinal
        val mask = prefs.getInt("ir_mask:$sig", 0) or bit
        val edit = prefs.edit().putInt("ir_mask:$sig", mask)
        if (Integer.bitCount(mask) >= IR_POISON_DISTINCT_STRATEGIES) {
            edit.putLong("ir_poisoned_at:$sig", System.currentTimeMillis())
        }
        edit.apply()
    }

    private fun clearIranPoisonState(prefs: SharedPreferences, sig: String) {
        prefs.edit().remove("ir_mask:$sig").remove("ir_poisoned_at:$sig").apply()
    }

    private fun strategyScore(prefs: SharedPreferences, sig: String, strategy: Strategy): Double {
        val prefix = "strategy_stat:$sig:${strategy.name}"
        val ok = prefs.getInt("$prefix:ok", 0)
        val fail = prefs.getInt("$prefix:fail", 0)
        val latency = prefs.getLong("$prefix:latency", 8000L).coerceAtLeast(1L)
        val base = when (strategy) {
            Strategy.MASQUE_H3 -> 100.0
            Strategy.MASQUE_H2_FRAGMENT -> 96.0
            Strategy.MASQUE_H2 -> 92.0
            Strategy.WIREGUARD -> 84.0
            Strategy.GOOL -> 60.0
        }
        return base + ok.coerceAtMost(8) * 12.0 - fail.coerceAtMost(8) * 15.0 - min(30.0, latency / 500.0)
    }

    private fun recordStrategySuccess(prefs: SharedPreferences, sig: String, strategy: Strategy, latencyMs: Long) {
        val prefix = "strategy_stat:$sig:${strategy.name}"
        prefs.edit()
            .putString("lkg:$sig", strategy.name)
            .putLong("lkg_at:$sig", System.currentTimeMillis())
            .putInt("$prefix:ok", min(24, prefs.getInt("$prefix:ok", 0) + 1))
            .putInt("$prefix:fail", max(0, prefs.getInt("$prefix:fail", 0) - 1))
            .putLong("$prefix:latency", latencyMs.coerceAtLeast(1L))
            .remove("fail:$sig:${strategy.name}")
            .remove("backoff:$sig:${strategy.name}")
            .apply()
    }

    private fun nativeExecutable(context: Context) = File(context.applicationInfo.nativeLibraryDir, NATIVE_NAME)
    private fun rotateLogs(context: Context) { val current = File(context.cacheDir, "bluevpn-aether.log"); val old = File(context.cacheDir, "bluevpn-aether.log.1"); if (current.exists() && current.length() >= 512L * 1024L) { runCatching { old.delete() }; runCatching { current.renameTo(old) } } }
    private fun ensureGeneration(gen: Long, strategy: Strategy?) { if (generation.get() != gen) throw Failure(ErrorCode.WARP_CANCELLED, state, strategy, "Connection generation changed") }

    private fun networkShape(context: Context): NetworkShape {
        val cm = context.getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
        val n = cm.activeNetwork; val caps = n?.let { cm.getNetworkCapabilities(it) }; val lp = n?.let { cm.getLinkProperties(it) }
        val kind = when { caps?.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) == true -> "wifi"; caps?.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR) == true -> "cell"; caps?.hasTransport(NetworkCapabilities.TRANSPORT_ETHERNET) == true -> "eth"; else -> "other" }
        val has4 = lp?.linkAddresses?.any { it.address.address.size == 4 } == true; val has6 = lp?.linkAddresses?.any { it.address.address.size == 16 } == true
        val validated = caps?.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED) == true; val metered = !((caps?.hasCapability(NetworkCapabilities.NET_CAPABILITY_NOT_METERED)) == true)
        val operator = if (kind == "cell") runCatching { (context.getSystemService(Context.TELEPHONY_SERVICE) as TelephonyManager).networkOperator.take(6) }.getOrDefault("") else ""
        val operatorHash = if (operator.isBlank()) "none" else sha256(operator).take(10)
        val capabilityBits = listOf(NetworkCapabilities.NET_CAPABILITY_INTERNET, NetworkCapabilities.NET_CAPABILITY_VALIDATED, NetworkCapabilities.NET_CAPABILITY_NOT_METERED, NetworkCapabilities.NET_CAPABILITY_NOT_ROAMING).joinToString("") { if (caps?.hasCapability(it) == true) "1" else "0" }
        return NetworkShape("$kind:${if (has4) 4 else 0}${if (has6) 6 else 0}:v${if (validated) 1 else 0}:m${if (metered) 1 else 0}:c$capabilityBits:o$operatorHash", has4, has6)
    }
    private fun sha256(value: String): String = MessageDigest.getInstance("SHA-256").digest(value.toByteArray()).joinToString("") { "%02x".format(it) }
    private fun effectiveIpMode(mode: String, shape: NetworkShape): String = BlueVpnWarpPolicy.effectiveIpMode(mode, shape.ipv4, shape.ipv6)
    private fun normalizedScanMode(value: String): String =
        value.takeIf { it in setOf("turbo", "balanced", "thorough", "stealth", "ironclad") } ?: "turbo"

    private fun freshScanPlan(configured: String): List<String> {
        val primary = normalizedScanMode(configured)
        val fallback = when (primary) {
            "turbo" -> "ironclad"
            "balanced" -> "ironclad"
            "thorough" -> "stealth"
            "stealth" -> "ironclad"
            "ironclad" -> "turbo"
            else -> "ironclad"
        }
        return listOf(primary, fallback).distinct()
    }

    private fun retryableFreshScanFailure(code: ErrorCode): Boolean = code in setOf(
        ErrorCode.WARP_START_TIMEOUT,
        ErrorCode.TCP_TIMEOUT,
        ErrorCode.UDP_BLOCKED,
        ErrorCode.DNS_FAILED,
        ErrorCode.SOCKS_FAILED,
        ErrorCode.AETHER_START_FAILED,
        ErrorCode.AETHER_CRASHED,
        ErrorCode.EXIT_IRAN,
        ErrorCode.WARP_EXIT_COUNTRY_BLOCKED,
        ErrorCode.EXIT_VALIDATION_FAILED,
        ErrorCode.NO_INTERNET,
    )
    private fun normalizedNoize(strategy: Strategy, value: String): String = if (strategy in setOf(Strategy.WIREGUARD, Strategy.GOOL)) value.takeIf { it in setOf("aggressive", "balanced", "light", "off") } ?: "balanced" else value.takeIf { it in setOf("gfw", "firewall", "light", "off") } ?: "firewall"

    private fun edgeCandidates(prefs: SharedPreferences, sig: String, strategy: Strategy, breadth: Int): List<EdgeCandidate> {
        val limit = breadth.coerceIn(2, 16); val cached = prefs.getString("edge:$sig:${strategy.name}", "").orEmpty().let(::parseEdgeCandidate)
        val prefixes = when (strategy) { Strategy.WIREGUARD -> listOf("162.159.192", "162.159.193"); Strategy.MASQUE_H3, Strategy.MASQUE_H2, Strategy.MASQUE_H2_FRAGMENT -> listOf("162.159.192", "162.159.197"); Strategy.GOOL -> emptyList() }
        val ports = when (strategy) { Strategy.WIREGUARD -> WIREGUARD_UDP_PORTS.toList(); Strategy.MASQUE_H2, Strategy.MASQUE_H2_FRAGMENT -> listOf(443); Strategy.MASQUE_H3 -> MASQUE_UDP_PORTS.toList(); Strategy.GOOL -> emptyList() }
        if (prefixes.isEmpty() || ports.isEmpty()) return listOfNotNull(cached)
        val cursor = prefs.getInt("edge_cursor:$sig:${strategy.name}", 0).coerceAtLeast(0); val seed = ((sig.hashCode().toLong() * 1103515245L + strategy.ordinal * 12345L) and 0x7fffffffL).toInt()
        val octets = buildList { addAll(listOf(1,3,4,5)); var v=(seed%251)+1; repeat(28){ add(v.coerceIn(1,254)); v=((v+37-1)%254)+1 } }.distinct()
        val matrix = ArrayList<EdgeCandidate>(); ports.forEachIndexed { pi, port -> prefixes.forEachIndexed { xi, prefix -> val rotate=(cursor+pi*7+xi*13)%octets.size; octets.indices.forEach { matrix += EdgeCandidate("$prefix.${octets[(it+rotate)%octets.size]}", port) } } }
        return buildList { if (cached != null && !isEdgeBackedOff(prefs,sig,strategy,cached)) add(cached); matrix.asSequence().filter { it != cached && !isEdgeBackedOff(prefs,sig,strategy,it) }.take(limit-size).forEach(::add) }
    }
    private fun parseEdgeCandidate(raw: String): EdgeCandidate? { val host=raw.substringBeforeLast(':',"").trim(); val port=raw.substringAfterLast(':',"").toIntOrNull()?:return null; if(!host.matches(Regex("""162\.159\.(192|193|197)\.(?:[1-9]|[1-9]\d|1\d\d|2[0-4]\d|25[0-4])"""))) return null; if(port !in setOf(443,500,1701,2408,4443,4500,8095,8443)) return null; return EdgeCandidate(host,port) }
    private fun sanitizeDiagnostic(value: String): String =
        value.replace(Regex("(?i)(token|authorization|password|secret|otp|license)\\s*[:=]\\s*[^\\s,;]+")) { "${it.groupValues[1]}=<redacted>" }
            .replace(Regex("https?://[^\\s]+"), "<url>")
            .take(240)

    private fun persistDiagnostic(context: Context, failure: Failure, durationMs: Long) {
        context.getSharedPreferences("bluevpn_warp_diagnostics_v1", Context.MODE_PRIVATE).edit()
            .putLong("at", System.currentTimeMillis())
            .putString("code", failure.code.name)
            .putString("stage", failure.stage.name)
            .putString("strategy", failure.strategy?.name.orEmpty())
            .putString("detail", sanitizeDiagnostic(failure.detail))
            .putLong("duration_ms", durationMs.coerceAtLeast(0L))
            .apply()
    }

    private fun cachedStrategy(prefs: SharedPreferences, sig: String): Strategy? = prefs.getString("lkg:$sig", "")?.let { runCatching { Strategy.valueOf(it) }.getOrNull() }
    private fun isLkgFresh(prefs: SharedPreferences, sig: String): Boolean = BlueVpnWarpPolicy.lkgFresh(System.currentTimeMillis(), prefs.getLong("lkg_at:$sig", 0L))
    private fun isFreshCachedEdge(prefs: SharedPreferences, sig: String, strategy: Strategy, c: EdgeCandidate): Boolean = prefs.getString("edge:$sig:${strategy.name}", "") == c.authority && BlueVpnWarpPolicy.lkgFresh(System.currentTimeMillis(), prefs.getLong("edge_at:$sig:${strategy.name}", 0L))
    private fun isEdgeBackedOff(prefs: SharedPreferences, sig: String, strategy: Strategy, c: EdgeCandidate): Boolean = prefs.getLong("edge_backoff:$sig:${strategy.name}:${c.authority}", 0L) > System.currentTimeMillis()
    private fun candidateScore(prefs: SharedPreferences, sig: String, strategy: Strategy, c: EdgeCandidate): Double {
        val prefix = "edge_stat:$sig:${strategy.name}:${c.authority}"
        return BlueVpnWarpPolicy.candidateScore(
            prefs.getInt("$prefix:ok", 0),
            prefs.getInt("$prefix:fail", 0),
            prefs.getInt("$prefix:consecutive", 0),
            prefs.getLong("$prefix:latency", 2500L),
            isFreshCachedEdge(prefs, sig, strategy, c),
        )
    }
    private fun recordEdgeSuccess(prefs: SharedPreferences, sig: String, strategy: Strategy, win: ProbeWin) {
        val c=win.attempt.candidate; val prefix="edge_stat:$sig:${strategy.name}:${c.authority}"; val ok=prefs.getInt("$prefix:ok",0)+1
        prefs.edit().putString("lkg:$sig",strategy.name).putLong("lkg_at:$sig",System.currentTimeMillis()).putString("edge:$sig:${strategy.name}",c.authority).putLong("edge_at:$sig:${strategy.name}",System.currentTimeMillis()).putInt("$prefix:ok",min(MAX_HISTORY,ok)).putInt("$prefix:consecutive",0).putLong("$prefix:latency",win.latencyMs).putString("$prefix:country",win.country.orEmpty()).remove("edge_backoff:$sig:${strategy.name}:${c.authority}").remove("backoff:$sig:${strategy.name}").apply()
    }
    private fun backoffMs(code: ErrorCode, count: Int): Long = BlueVpnWarpPolicy.backoffMs(code.name, count)
    private fun recordEdgeFailure(prefs: SharedPreferences, sig: String, strategy: Strategy, c: EdgeCandidate, code: ErrorCode) {
        val prefix="edge_stat:$sig:${strategy.name}:${c.authority}"
        val fail=min(MAX_HISTORY,prefs.getInt("$prefix:fail",0)+1)
        val consecutive=prefs.getInt("$prefix:consecutive",0)+1
        val edit=prefs.edit().putInt("$prefix:fail",fail).putInt("$prefix:consecutive",consecutive)
            .putLong("edge_backoff:$sig:${strategy.name}:${c.authority}",System.currentTimeMillis()+backoffMs(code,consecutive))
        if (prefs.getString("edge:$sig:${strategy.name}", "") == c.authority) {
            edit.remove("edge:$sig:${strategy.name}").remove("edge_at:$sig:${strategy.name}")
            // Keep strategy history, but force the next connect to race rather than trust stale LKG.
            if (cachedStrategy(prefs, sig) == strategy) edit.remove("lkg_at:$sig")
        }
        edit.apply()
    }
    private fun advanceEdgeCursor(prefs: SharedPreferences, sig: String, strategy: Strategy, breadth: Int) { val key="edge_cursor:$sig:${strategy.name}"; prefs.edit().putInt(key,(prefs.getInt(key,0)+breadth.coerceIn(2,16))%8192).apply() }
    private fun strategyBackoffUntil(prefs: SharedPreferences, sig: String, s: Strategy): Long =
        prefs.getLong("backoff:$sig:${s.name}", 0L)

    private fun isBackedOff(prefs: SharedPreferences, sig: String, s: Strategy): Boolean =
        strategyBackoffUntil(prefs, sig, s) > System.currentTimeMillis()

    private fun recordFailure(prefs: SharedPreferences, sig: String, s: Strategy, code: ErrorCode) {
        val key="fail:$sig:${s.name}"; val count=min(8,prefs.getInt(key,0)+1); val prefix="strategy_stat:$sig:${s.name}"
        prefs.edit().putInt(key,count).putInt("$prefix:fail",min(24,prefs.getInt("$prefix:fail",0)+1))
            .putLong("backoff:$sig:${s.name}",System.currentTimeMillis()+backoffMs(code,count)).apply()
        if (code == ErrorCode.EXIT_IRAN) recordIranExit(prefs, sig, s)
    }
}
