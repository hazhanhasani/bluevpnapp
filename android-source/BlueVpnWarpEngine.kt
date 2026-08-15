package com.v2ray.ang.bluevpn

import android.content.Context
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.os.Build
import android.os.SystemClock
import android.telephony.TelephonyManager
import com.v2ray.ang.dto.entities.ProfileItem
import com.v2ray.ang.enums.EConfigType
import com.v2ray.ang.handler.MmkvManager
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import kotlinx.coroutines.withTimeoutOrNull
import java.io.BufferedInputStream
import java.io.BufferedOutputStream
import java.io.File
import java.net.HttpURLConnection
import java.net.InetAddress
import java.net.InetSocketAddress
import java.net.Proxy
import java.net.ServerSocket
import java.net.Socket
import java.net.URL
import java.util.concurrent.atomic.AtomicLong

/** Application-lifecycle supervisor for the Free Aether/WARP process. */
object BlueVpnWarpEngine {
    const val BRIDGE_SUBSCRIPTION_ID = "bluevpn_free_warp_aether"
    private const val SOCKS_HOST = "127.0.0.1"
    private const val NATIVE_NAME = "libbluevpn_aether.so"
    private const val PREFS = "bluevpn_warp_runtime_v2"
    private const val PORT_MIN = 1819
    private const val PORT_MAX = 1829

    enum class State { STOPPED, PREPARING, TRYING_CACHED_ROUTE, SCANNING, AETHER_DATA_PLANE_VALIDATING, SOCKS_READY, STARTING_XRAY_BRIDGE, VERIFYING_TUNNEL, CONNECTED, RECONNECTING, SWITCHING_STRATEGY, FALLING_BACK_TO_POOL, STOPPING, FAILED }
    enum class Strategy { MASQUE_H3, MASQUE_H2, MASQUE_H2_FRAGMENT, WIREGUARD, GOOL }
    enum class ErrorCode { WARP_BINARY_MISSING, WARP_UNSUPPORTED_ABI, WARP_PORT_OCCUPIED, WARP_PROCESS_EXITED, WARP_INTERACTIVE_STALL, WARP_START_TIMEOUT, WARP_NO_ENDPOINT, WARP_SOCKS_HANDSHAKE_FAILED, WARP_DATA_PLANE_FAILED, WARP_BRIDGE_CORE_FAILED, WARP_POST_BRIDGE_VERIFY_FAILED, WARP_NETWORK_CHANGED, WARP_RECONNECT_EXHAUSTED, WARP_FALLBACK_STARTED, WARP_CANCELLED, WARP_UNKNOWN }

    data class Failure(val code: ErrorCode, val stage: State, val strategy: Strategy?, val detail: String) : RuntimeException("${code.name}: $detail")
    data class Prepared(val guid: String, val strategy: Strategy, val port: Int, val startupMs: Long)

    private val mutex = Mutex()
    private val generation = AtomicLong(0)
    @Volatile private var process: Process? = null
    @Volatile private var bridgeGuid = ""
    @Volatile private var activePort = 0
    @Volatile private var activeStrategy: Strategy? = null
    @Volatile var state: State = State.STOPPED
        private set

    fun supported(context: Context): Boolean = Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && nativeExecutable(context).let { it.isFile && it.canExecute() }
    fun isRunning(): Boolean = process?.isAlive == true
    fun currentStrategy(): Strategy? = activeStrategy

    fun isBridgeGuid(guid: String, profile: ProfileItem? = null): Boolean {
        if (guid.isBlank()) return false
        val resolved = profile ?: MmkvManager.decodeServerConfig(guid) ?: return false
        return resolved.subscriptionId == BRIDGE_SUBSCRIPTION_ID && resolved.configType == EConfigType.SOCKS &&
            resolved.server == SOCKS_HOST && resolved.serverPort.toIntOrNull() in PORT_MIN..PORT_MAX
    }

    suspend fun prepare(context: Context): Result<String> = runCatching { prepareAdaptive(context).guid }

    suspend fun prepareAdaptive(context: Context): Prepared = withContext(Dispatchers.IO) {
        mutex.withLock {
            val app = context.applicationContext
            val myGeneration = generation.incrementAndGet()
            val started = SystemClock.elapsedRealtime()
            state = State.PREPARING
            if (!supported(app)) throw Failure(ErrorCode.WARP_UNSUPPORTED_ABI, state, null, "Aether runtime is not executable for this ABI")
            stopLocked()
            val policy = BlueVpnAccountManager.freeAccessSnapshot(app)
            val signature = networkSignature(app)
            val prefs = app.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            val cached = prefs.getString("lkg:$signature", "")?.let { runCatching { Strategy.valueOf(it) }.getOrNull() }
            val strategies = buildList {
                if (policy.warpQuickReconnect && cached != null && cachedAllowed(cached, policy)) add(cached)
                if (policy.warpAllowedTransports.contains("h3")) add(Strategy.MASQUE_H3)
                if (policy.warpH2Enabled && policy.warpAllowedTransports.contains("h2")) add(Strategy.MASQUE_H2)
                if (policy.warpH2Enabled && policy.warpFragmentEnabled && policy.warpAllowedTransports.contains("h2_fragment")) add(Strategy.MASQUE_H2_FRAGMENT)
                if (policy.warpWireGuardEnabled && policy.warpAllowedTransports.contains("wireguard")) add(Strategy.WIREGUARD)
                if (policy.warpGoolEnabled && policy.warpAllowedTransports.contains("gool")) add(Strategy.GOOL)
            }.distinct()
            if (strategies.isEmpty()) throw Failure(ErrorCode.WARP_NO_ENDPOINT, state, null, "No WARP strategy is allowed by policy")

            val totalDeadline = started + policy.warpTotalTimeoutSeconds.coerceIn(30, 90) * 1000L
            var last: Failure? = null
            for ((index, strategy) in strategies.withIndex()) {
                if (generation.get() != myGeneration) throw Failure(ErrorCode.WARP_CANCELLED, state, strategy, "Connection generation changed")
                if (SystemClock.elapsedRealtime() >= totalDeadline) break
                if (isBackedOff(prefs, signature, strategy)) continue
                state = if (index == 0 && cached == strategy) State.TRYING_CACHED_ROUTE else if (index == 0) State.SCANNING else State.SWITCHING_STRATEGY
                val quick = cached == strategy && index == 0 && policy.warpQuickReconnect
                val budget = if (quick) policy.warpWarmTimeoutSeconds.coerceIn(4, 12) else policy.warpColdTimeoutSeconds.coerceIn(15, 40)
                try {
                    val port = reservePort()
                    startProcess(app, strategy, port, quick, policy)
                    val ok = withTimeoutOrNull(budget * 1000L) {
                        awaitValidatedDataPlane(myGeneration, port, strategy)
                    } ?: false
                    if (!ok) throw Failure(ErrorCode.WARP_START_TIMEOUT, state, strategy, "Strategy exceeded ${budget}s startup budget")
                    prefs.edit().putString("lkg:$signature", strategy.name).remove("fail:$signature:${strategy.name}").apply()
                    activeStrategy = strategy
                    activePort = port
                    state = State.SOCKS_READY
                    val guid = ensureBridgeProfile(port)
                    return@withLock Prepared(guid, strategy, port, SystemClock.elapsedRealtime() - started)
                } catch (f: Failure) {
                    last = f
                    recordFailure(prefs, signature, strategy)
                    stopProcessOnly()
                } catch (t: Throwable) {
                    last = Failure(ErrorCode.WARP_UNKNOWN, state, strategy, t.message ?: t.javaClass.simpleName)
                    recordFailure(prefs, signature, strategy)
                    stopProcessOnly()
                }
            }
            state = State.FAILED
            throw last ?: Failure(ErrorCode.WARP_RECONNECT_EXHAUSTED, state, null, "All allowed strategies failed")
        }
    }

    suspend fun stopAsync() = withContext(Dispatchers.IO) { mutex.withLock { generation.incrementAndGet(); stopLocked() } }
    fun stop() { generation.incrementAndGet(); stopProcessOnly(); state = State.STOPPED }
    fun markBridgeStarting() { state = State.STARTING_XRAY_BRIDGE }
    fun markTunnelVerifying() { state = State.VERIFYING_TUNNEL }
    fun markConnected() { state = State.CONNECTED }
    fun markFallback() { state = State.FALLING_BACK_TO_POOL }

    private fun cachedAllowed(strategy: Strategy, p: BlueVpnFreeAccessSnapshot): Boolean = when (strategy) {
        Strategy.MASQUE_H3 -> p.warpAllowedTransports.contains("h3")
        Strategy.MASQUE_H2 -> p.warpH2Enabled && p.warpAllowedTransports.contains("h2")
        Strategy.MASQUE_H2_FRAGMENT -> p.warpH2Enabled && p.warpFragmentEnabled && p.warpAllowedTransports.contains("h2_fragment")
        Strategy.WIREGUARD -> p.warpWireGuardEnabled && p.warpAllowedTransports.contains("wireguard")
        Strategy.GOOL -> p.warpGoolEnabled && p.warpAllowedTransports.contains("gool")
    }

    private fun nativeExecutable(context: Context) = File(context.applicationInfo.nativeLibraryDir, NATIVE_NAME)

    private fun startProcess(context: Context, strategy: Strategy, port: Int, quick: Boolean, p: BlueVpnFreeAccessSnapshot) {
        if (canTcpConnect(port, 100)) throw Failure(ErrorCode.WARP_PORT_OCCUPIED, state, strategy, "Loopback port $port is already occupied")
        val dataDir = File(context.filesDir, "bluevpn-aether").apply { mkdirs() }
        val config = File(dataDir, "aether.toml")
        val command = mutableListOf(nativeExecutable(context).absolutePath, "--bind", "$SOCKS_HOST:$port", "--config", config.absolutePath)
        when (strategy) {
            Strategy.MASQUE_H3 -> command += listOf("--masque")
            Strategy.MASQUE_H2 -> command += listOf("--masque", "--h2")
            Strategy.MASQUE_H2_FRAGMENT -> command += listOf("--masque", "--h2", "--fragment", "--fragment-size", p.warpFragmentSize, "--fragment-delay", p.warpFragmentDelay)
            Strategy.WIREGUARD -> command += listOf("--wg")
            Strategy.GOOL -> command += listOf("--gool")
        }
        command += if (quick) listOf("--quick-reconnect") else listOf("--no-quick-reconnect")
        val scanMode = if (p.warpScanMode == "ironclad") "ironclad" else p.warpScanMode
        val noize = when (strategy) {
            Strategy.WIREGUARD, Strategy.GOOL -> when (p.warpNoizeProfile) {
                "aggressive", "balanced", "light", "off" -> p.warpNoizeProfile
                else -> "balanced"
            }
            else -> when (p.warpNoizeProfile) {
                "gfw", "firewall", "light", "off" -> p.warpNoizeProfile
                else -> "firewall"
            }
        }
        command += listOf("--scan", scanMode, "--noize", noize)
        // Always pin the IP selection to suppress Aether's interactive prompt.
        when (p.warpIpMode) { "v4" -> command += "-4"; else -> command += "--dual" }
        if (strategy == Strategy.MASQUE_H3 || strategy == Strategy.MASQUE_H2 || strategy == Strategy.MASQUE_H2_FRAGMENT) {
            val startup = if (quick) p.warpWarmTimeoutSeconds.coerceIn(4, 12) else p.warpColdTimeoutSeconds.coerceIn(15, 40)
            command += listOf("--startup-secs", startup.toString())
        }
        rotateLogs(context)
        val log = File(context.cacheDir, "bluevpn-aether.log")
        val builder = ProcessBuilder(command).directory(dataDir).redirectErrorStream(true).redirectOutput(ProcessBuilder.Redirect.appendTo(log))
        builder.environment()["HOME"] = dataDir.absolutePath
        builder.environment()["TMPDIR"] = context.cacheDir.absolutePath
        builder.environment()["AETHER_QUICK_RECONNECT"] = if (quick) "1" else "0"
        builder.environment()["RUST_LOG"] = "info"
        process = builder.start().also { runCatching { it.outputStream.close() } }
        activePort = port
        activeStrategy = strategy
    }

    private suspend fun awaitValidatedDataPlane(gen: Long, port: Int, strategy: Strategy): Boolean {
        var sawPort = false
        while (generation.get() == gen) {
            val p = process ?: throw Failure(ErrorCode.WARP_PROCESS_EXITED, state, strategy, "Aether process missing")
            if (!p.isAlive) throw Failure(ErrorCode.WARP_PROCESS_EXITED, state, strategy, "Aether exited with ${runCatching { p.exitValue() }.getOrDefault(-1)}")
            if (canTcpConnect(port, 140)) {
                sawPort = true
                state = State.AETHER_DATA_PLANE_VALIDATING
                if (!socksGreetingAndRemoteConnect(port, "www.cloudflare.com", 443, 1200)) throw Failure(ErrorCode.WARP_SOCKS_HANDSHAKE_FAILED, state, strategy, "SOCKS5 greeting/remote CONNECT failed")
                val proof = validateViaSocks(port)
                if (proof) return true
            }
            delay(if (sawPort) 180L else 90L)
        }
        throw Failure(ErrorCode.WARP_CANCELLED, state, strategy, "Cancelled")
    }

    private fun validateViaSocks(port: Int): Boolean {
        val proxy = Proxy(Proxy.Type.SOCKS, InetSocketAddress(SOCKS_HOST, port))
        val endpoints = listOf("https://www.cloudflare.com/cdn-cgi/trace", "https://cp.cloudflare.com/generate_204", "https://www.google.com/generate_204")
        var successes = 0
        var traceWarp = false
        endpoints.forEach { raw ->
            runCatching {
                val c = URL(raw).openConnection(proxy) as HttpURLConnection
                c.connectTimeout = 1800; c.readTimeout = 1800; c.instanceFollowRedirects = false; c.setRequestProperty("User-Agent", "BlueVPN-WARP-Probe/1")
                val code = c.responseCode
                val body = if (raw.contains("cdn-cgi/trace") && code in 200..299) c.inputStream.bufferedReader().use { it.readText().take(4096) } else ""
                if (code in 200..399) successes++
                if (body.lineSequence().any { it == "warp=on" || it == "warp=plus" }) traceWarp = true
                c.disconnect()
            }
        }
        return traceWarp || successes >= 2
    }

    private fun socksGreetingAndRemoteConnect(port: Int, host: String, remotePort: Int, timeoutMs: Int): Boolean = runCatching {
        Socket().use { socket ->
            socket.soTimeout = timeoutMs
            socket.connect(InetSocketAddress(SOCKS_HOST, port), timeoutMs)
            val out = BufferedOutputStream(socket.getOutputStream()); val input = BufferedInputStream(socket.getInputStream())
            out.write(byteArrayOf(0x05, 0x01, 0x00)); out.flush()
            if (input.read() != 0x05 || input.read() != 0x00) return@runCatching false
            val hb = host.toByteArray(Charsets.US_ASCII)
            out.write(byteArrayOf(0x05, 0x01, 0x00, 0x03, hb.size.toByte())); out.write(hb); out.write(byteArrayOf((remotePort ushr 8).toByte(), remotePort.toByte())); out.flush()
            if (input.read() != 0x05 || input.read() != 0x00) return@runCatching false
            val rsv = input.read(); val atyp = input.read(); if (rsv < 0 || atyp < 0) return@runCatching false
            val skip = when (atyp) { 1 -> 4; 3 -> input.read(); 4 -> 16; else -> -1 }
            if (skip < 0) return@runCatching false
            repeat(skip + 2) { if (input.read() < 0) return@runCatching false }
            true
        }
    }.getOrDefault(false)

    private fun reservePort(): Int {
        for (port in PORT_MIN..PORT_MAX) {
            if (canTcpConnect(port, 60)) continue
            val free = runCatching { ServerSocket().use { it.reuseAddress = false; it.bind(InetSocketAddress(InetAddress.getByName(SOCKS_HOST), port)) } }.isSuccess
            if (free) return port
        }
        throw Failure(ErrorCode.WARP_PORT_OCCUPIED, state, activeStrategy, "No free loopback port in $PORT_MIN..$PORT_MAX")
    }

    private fun canTcpConnect(port: Int, timeoutMs: Int): Boolean = runCatching { Socket().use { it.connect(InetSocketAddress(SOCKS_HOST, port), timeoutMs) }; true }.getOrDefault(false)

    private fun ensureBridgeProfile(port: Int): String {
        MmkvManager.decodeServerList(BRIDGE_SUBSCRIPTION_ID).forEach { guid ->
            val p = MmkvManager.decodeServerConfig(guid)
            if (p?.serverPort == port.toString() && isBridgeGuid(guid, p)) { bridgeGuid = guid; return guid }
        }
        MmkvManager.removeServerViaSubid(BRIDGE_SUBSCRIPTION_ID)
        val profile = ProfileItem.create(EConfigType.SOCKS).apply { subscriptionId = BRIDGE_SUBSCRIPTION_ID; remarks = "BlueVPN Free • Cloudflare WARP"; description = "Local Aether WARP bridge"; server = SOCKS_HOST; serverPort = port.toString() }
        return MmkvManager.encodeServerConfig("", profile).also { bridgeGuid = it }
    }

    private fun stopLocked() { state = State.STOPPING; stopProcessOnly(); activePort = 0; activeStrategy = null; state = State.STOPPED }
    private fun stopProcessOnly() {
        val p = process
        process = null
        if (p != null) {
            runCatching { p.destroy() }
            if (p.isAlive) runCatching { p.destroyForcibly() }
        }
    }

    private fun rotateLogs(context: Context) {
        val current = File(context.cacheDir, "bluevpn-aether.log"); val old = File(context.cacheDir, "bluevpn-aether.log.1")
        if (current.exists() && current.length() >= 512L * 1024L) { runCatching { old.delete() }; runCatching { current.renameTo(old) } }
    }

    private fun networkSignature(context: Context): String {
        val cm = context.getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
        val n = cm.activeNetwork; val caps = n?.let { cm.getNetworkCapabilities(it) }; val lp = n?.let { cm.getLinkProperties(it) }
        val kind = when { caps?.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) == true -> "wifi"; caps?.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR) == true -> "cell"; else -> "other" }
        val has4 = lp?.linkAddresses?.any { it.address.address.size == 4 } == true
        val has6 = lp?.linkAddresses?.any { it.address.address.size == 16 } == true
        val mccmnc = if (kind == "cell") runCatching { (context.getSystemService(Context.TELEPHONY_SERVICE) as TelephonyManager).networkOperator.take(6) }.getOrDefault("") else ""
        return "$kind:${if (has4) 4 else 0}${if (has6) 6 else 0}:$mccmnc"
    }

    private fun isBackedOff(prefs: android.content.SharedPreferences, sig: String, s: Strategy): Boolean = prefs.getLong("backoff:$sig:${s.name}", 0L) > System.currentTimeMillis()
    private fun recordFailure(prefs: android.content.SharedPreferences, sig: String, s: Strategy) {
        val key = "fail:$sig:${s.name}"; val count = prefs.getInt(key, 0) + 1
        val delayMs = when { count >= 5 -> 15 * 60_000L; count >= 3 -> 5 * 60_000L; else -> 60_000L }
        prefs.edit().putInt(key, count).putLong("backoff:$sig:${s.name}", System.currentTimeMillis() + delayMs).apply()
    }
}
