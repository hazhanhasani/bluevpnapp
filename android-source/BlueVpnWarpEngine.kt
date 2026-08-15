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
import kotlinx.coroutines.channels.Channel
import kotlinx.coroutines.coroutineScope
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
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
    private val generation = AtomicLong(0)
    @Volatile private var process: Process? = null
    @Volatile private var bridgeGuid = ""
    @Volatile private var activePort = 0
    @Volatile private var activeStrategy: Strategy? = null
    @Volatile private var connectJob: Job? = null
    @Volatile var state: State = State.STOPPED
        private set

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
        stopProcessOnly(wait = true)
        if (!supported(app)) throw Failure(ErrorCode.WARP_UNSUPPORTED_ABI, state, null, "Aether runtime is not executable for this ABI")

        val policy = BlueVpnAccountManager.freeAccessSnapshot(app)
        val shape = networkShape(app)
        val prefs = app.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val strategies = strategyOrder(prefs, shape.signature, policy)
        if (strategies.isEmpty()) throw Failure(ErrorCode.CONFIG_INVALID, state, null, "No WARP strategy is allowed by policy")
        val totalDeadline = started + policy.warpTotalTimeoutSeconds.coerceIn(30, 90) * 1000L
        var last: Failure? = null

        try {
            for ((index, strategy) in strategies.withIndex()) {
                ensureGeneration(myGeneration, strategy)
                if (SystemClock.elapsedRealtime() >= totalDeadline) break
                if (isBackedOff(prefs, shape.signature, strategy)) continue

                if (policy.warpEndpointRacingEnabled && strategy != Strategy.GOOL) {
                    val candidates = edgeCandidates(prefs, shape.signature, strategy, policy.warpEndpointRaceBreadth)
                    if (candidates.isNotEmpty()) {
                        state = if (isFreshCachedEdge(prefs, shape.signature, strategy, candidates.first())) State.TRYING_CACHED_ROUTE else State.RACING_ENDPOINTS
                        try {
                            val win = raceCandidates(app, prefs, shape, strategy, candidates, policy, myGeneration, totalDeadline)
                            promoteWinner(win.attempt, strategy)
                            recordEdgeSuccess(prefs, shape.signature, strategy, win)
                            state = State.SOCKS_READY
                            return@withContext Prepared(ensureBridgeProfile(win.attempt.port), strategy, win.attempt.port, SystemClock.elapsedRealtime() - started)
                        } catch (f: Failure) {
                            last = f
                            stopProcessOnly(wait = true)
                        }
                    }
                    advanceEdgeCursor(prefs, shape.signature, strategy, policy.warpEndpointRaceBreadth)
                }

                ensureGeneration(myGeneration, strategy)
                state = if (index == 0) State.SCANNING else State.SWITCHING_STRATEGY
                val quick = index == 0 && policy.warpQuickReconnect && cachedStrategy(prefs, shape.signature) == strategy
                val budget = if (quick) policy.warpWarmTimeoutSeconds.coerceIn(4, 12) else policy.warpColdTimeoutSeconds.coerceIn(15, 40)
                try {
                    val port = startWithPortRetries(app, strategy, quick, policy, null, shape)
                    val ok = withTimeoutOrNull(min(budget, policy.warpStartTimeoutSeconds.coerceIn(3, 40)) * 1000L) {
                        awaitValidatedDataPlane(myGeneration, process ?: throw Failure(ErrorCode.AETHER_START_FAILED, state, strategy, "Aether process missing"), port, strategy, policy)
                    } ?: false
                    if (!ok) throw Failure(ErrorCode.WARP_START_TIMEOUT, state, strategy, "Strategy exceeded startup budget")
                    prefs.edit().putString("lkg:${shape.signature}", strategy.name).putLong("lkg_at:${shape.signature}", System.currentTimeMillis())
                        .remove("fail:${shape.signature}:${strategy.name}").remove("backoff:${shape.signature}:${strategy.name}").apply()
                    activeStrategy = strategy; activePort = port; state = State.SOCKS_READY
                    return@withContext Prepared(ensureBridgeProfile(port), strategy, port, SystemClock.elapsedRealtime() - started)
                } catch (f: Failure) {
                    last = f; recordFailure(prefs, shape.signature, strategy, f.code); stopProcessOnly(wait = true)
                }
            }
            state = State.FAILED
            throw last ?: Failure(ErrorCode.WARP_RECONNECT_EXHAUSTED, state, null, "All allowed WARP strategies failed")
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
        if (!p.warpAdaptiveEnabled) return listOfNotNull(cached ?: allowed.firstOrNull())
        return buildList { if (p.warpQuickReconnect && cached != null) add(cached); addAll(allowed) }.distinct()
    }

    private fun cachedAllowed(strategy: Strategy, p: BlueVpnFreeAccessSnapshot): Boolean = when (strategy) {
        Strategy.MASQUE_H3 -> p.warpAllowedTransports.contains("h3")
        Strategy.MASQUE_H2 -> p.warpH2Enabled && p.warpAllowedTransports.contains("h2")
        Strategy.MASQUE_H2_FRAGMENT -> p.warpH2Enabled && p.warpFragmentEnabled && p.warpAllowedTransports.contains("h2_fragment")
        Strategy.WIREGUARD -> p.warpWireGuardEnabled && p.warpAllowedTransports.contains("wireguard")
        Strategy.GOOL -> p.warpGoolEnabled && p.warpAllowedTransports.contains("gool")
    }

    private suspend fun raceCandidates(context: Context, prefs: SharedPreferences, shape: NetworkShape, strategy: Strategy, candidates: List<EdgeCandidate>, policy: BlueVpnFreeAccessSnapshot, gen: Long, deadline: Long): ProbeWin = coroutineScope {
        val concurrency = min(4, max(2, candidates.size))
        val ranked = candidates.sortedByDescending { candidateScore(prefs, shape.signature, strategy, it) }
        val channel = Channel<ProbeOutcome>(Channel.BUFFERED)
        val jobs = mutableListOf<Job>()
        var cursor = 0
        var inFlight = 0
        var last: Failure = Failure(ErrorCode.WARP_NO_ENDPOINT, state, strategy, "No eligible Cloudflare endpoint")

        fun launchNext() {
            if (cursor >= ranked.size) return
            val candidate = ranked[cursor++]
            if (isEdgeBackedOff(prefs, shape.signature, strategy, candidate)) return
            inFlight++
            jobs += launch(Dispatchers.IO) {
                val outcome = try {
                    ProbeOutcome.Success(probeCandidate(context, shape, strategy, candidate, policy, gen, deadline))
                } catch (c: CancellationException) {
                    throw c
                } catch (f: Failure) {
                    recordEdgeFailure(prefs, shape.signature, strategy, candidate, f.code)
                    ProbeOutcome.Failed(f)
                } catch (t: Throwable) {
                    val f = Failure(ErrorCode.UNKNOWN, state, strategy, t.message ?: t.javaClass.simpleName)
                    recordEdgeFailure(prefs, shape.signature, strategy, candidate, f.code)
                    ProbeOutcome.Failed(f)
                }
                channel.send(outcome)
            }
        }
        repeat(concurrency) { launchNext() }
        while (inFlight > 0) {
            ensureGeneration(gen, strategy)
            val outcome = channel.receive(); inFlight--
            when (outcome) {
                is ProbeOutcome.Success -> {
                    jobs.forEach { it.cancel() }
                    jobs.forEach { try { it.join() } catch (_: CancellationException) { } }
                    channel.close()
                    return@coroutineScope outcome.win
                }
                is ProbeOutcome.Failed -> last = outcome.failure
            }
            launchNext()
        }
        channel.close()
        throw last
    }

    private suspend fun probeCandidate(context: Context, shape: NetworkShape, strategy: Strategy, candidate: EdgeCandidate, policy: BlueVpnFreeAccessSnapshot, gen: Long, deadline: Long): ProbeWin {
        var owned: Process? = null
        try {
            ensureGeneration(gen, strategy)
            if (SystemClock.elapsedRealtime() + 1200L >= deadline) throw Failure(ErrorCode.WARP_START_TIMEOUT, state, strategy, "Global connect deadline reached")
            val attempt = startIndependentAttempt(context, strategy, candidate, policy, shape)
            owned = attempt.process
            val timeoutMs = policy.warpEndpointProbeSeconds.coerceIn(3, 8) * 1000L
            val validation = withTimeoutOrNull(timeoutMs) { awaitValidation(gen, attempt.process, attempt.port, strategy, policy) }
                ?: throw Failure(ErrorCode.TCP_TIMEOUT, state, strategy, "Peer ${candidate.authority} exceeded direct probe budget")
            if (!validation.ok) throw Failure(ErrorCode.NO_INTERNET, state, strategy, "Peer ${candidate.authority} did not pass tunneled HTTPS validation")
            val win = ProbeWin(attempt, SystemClock.elapsedRealtime() - attempt.started, validation.country)
            owned = null
            return win
        } finally {
            owned?.let { terminateProcess(it, true) }
        }
    }

    private suspend fun startIndependentAttempt(context: Context, strategy: Strategy, candidate: EdgeCandidate, policy: BlueVpnFreeAccessSnapshot, shape: NetworkShape): Attempt {
        var last: Throwable? = null
        repeat(3) {
            val port = reservePort()
            try {
                val started = SystemClock.elapsedRealtime()
                val p = buildProcess(context, strategy, port, quick = false, policy, candidate.authority, shape).start()
                runCatching { p.outputStream.close() }
                delay(100L)
                if (!p.isAlive) { terminateProcess(p, false); last = IllegalStateException("Aether exited before binding loopback port"); return@repeat }
                return Attempt(p, port, candidate, started)
            } catch (t: Throwable) { last = t }
        }
        throw Failure(ErrorCode.PORT_IN_USE, state, strategy, "Could not allocate a loopback port: ${last?.message.orEmpty()}")
    }

    private suspend fun startWithPortRetries(context: Context, strategy: Strategy, quick: Boolean, policy: BlueVpnFreeAccessSnapshot, peer: String?, shape: NetworkShape): Int {
        var last: Throwable? = null
        repeat(3) {
            val port = reservePort()
            try {
                val p = buildProcess(context, strategy, port, quick, policy, peer, shape).start()
                runCatching { p.outputStream.close() }
                delay(100L)
                if (!p.isAlive) { terminateProcess(p, false); last = IllegalStateException("Aether exited before binding loopback port"); return@repeat }
                process = p; activePort = port; activeStrategy = strategy
                return port
            } catch (t: Throwable) { last = t }
        }
        throw Failure(ErrorCode.AETHER_START_FAILED, state, strategy, last?.message ?: "Aether process could not start")
    }

    private fun buildProcess(context: Context, strategy: Strategy, port: Int, quick: Boolean, p: BlueVpnFreeAccessSnapshot, peer: String?, shape: NetworkShape): ProcessBuilder {
        val dataDir = File(context.filesDir, "bluevpn-aether").apply { mkdirs() }
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
        if (peer.isNullOrBlank()) command += listOf("--scan", normalizedScanMode(p.warpScanMode))
        command += listOf("--noize", normalizedNoize(strategy, p.warpNoizeProfile))
        when (effectiveIpMode(p.warpIpMode, shape)) { "v4" -> command += "-4"; else -> command += "--dual" }
        if (strategy in setOf(Strategy.MASQUE_H3, Strategy.MASQUE_H2, Strategy.MASQUE_H2_FRAGMENT)) {
            command += listOf("--startup-secs", p.warpStartTimeoutSeconds.coerceIn(3, 40).toString())
        }
        validateCommand(command, peer)
        rotateLogs(context)
        val log = File(context.cacheDir, "bluevpn-aether.log")
        return ProcessBuilder(command).directory(dataDir).redirectErrorStream(true).redirectOutput(ProcessBuilder.Redirect.appendTo(log)).also {
            it.environment()["HOME"] = dataDir.absolutePath
            it.environment()["TMPDIR"] = context.cacheDir.absolutePath
            it.environment()["AETHER_QUICK_RECONNECT"] = if (quick) "1" else "0"
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
                sawPort = true; state = State.AETHER_DATA_PLANE_VALIDATING
                if (!socksGreetingAndRemoteConnect(port, "www.cloudflare.com", 443, 1200)) throw Failure(ErrorCode.SOCKS_FAILED, state, strategy, "SOCKS5 greeting/remote CONNECT failed")
                return validateViaSocks(port, policy, strategy)
            }
            delay(if (sawPort) 150L else 70L)
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
        val profile = ProfileItem.create(EConfigType.SOCKS).apply { subscriptionId = BRIDGE_SUBSCRIPTION_ID; remarks = "BlueVPN Free • Cloudflare WARP"; description = "Local Aether WARP bridge"; server = SOCKS_HOST; serverPort = port.toString() }
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
    private fun normalizedScanMode(value: String): String = value.takeIf { it in setOf("turbo", "balanced", "thorough", "stealth", "ironclad") } ?: "turbo"
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
    private fun recordEdgeFailure(prefs: SharedPreferences, sig: String, strategy: Strategy, c: EdgeCandidate, code: ErrorCode) { val prefix="edge_stat:$sig:${strategy.name}:${c.authority}"; val fail=min(MAX_HISTORY,prefs.getInt("$prefix:fail",0)+1); val consecutive=prefs.getInt("$prefix:consecutive",0)+1; prefs.edit().putInt("$prefix:fail",fail).putInt("$prefix:consecutive",consecutive).putLong("edge_backoff:$sig:${strategy.name}:${c.authority}",System.currentTimeMillis()+backoffMs(code,consecutive)).apply() }
    private fun advanceEdgeCursor(prefs: SharedPreferences, sig: String, strategy: Strategy, breadth: Int) { val key="edge_cursor:$sig:${strategy.name}"; prefs.edit().putInt(key,(prefs.getInt(key,0)+breadth.coerceIn(2,16))%8192).apply() }
    private fun isBackedOff(prefs: SharedPreferences, sig: String, s: Strategy): Boolean = prefs.getLong("backoff:$sig:${s.name}",0L)>System.currentTimeMillis()
    private fun recordFailure(prefs: SharedPreferences, sig: String, s: Strategy, code: ErrorCode) { val key="fail:$sig:${s.name}"; val count=prefs.getInt(key,0)+1; prefs.edit().putInt(key,count).putLong("backoff:$sig:${s.name}",System.currentTimeMillis()+backoffMs(code,count)).apply() }
}
