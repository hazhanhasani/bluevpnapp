package com.v2ray.ang.bluevpn

import android.content.Context
import android.os.Build
import android.os.SystemClock
import com.v2ray.ang.dto.entities.ProfileItem
import com.v2ray.ang.enums.EConfigType
import com.v2ray.ang.handler.MmkvManager
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.io.File
import java.net.InetAddress
import java.net.InetSocketAddress
import java.net.Socket

/**
 * Free-tier Cloudflare WARP transport powered by the AGPLv3 Aether core.
 *
 * Aether is deliberately run as a separate native process and exposes only a
 * loopback SOCKS5 endpoint. BlueVPN then hands a local SOCKS ProfileItem to the
 * stock v2rayNG/Xray VpnService. This keeps the Premium Xray subscription
 * runtime untouched while avoiding a second TUN owner.
 *
 * No code from Oblivion is copied here. The Android process wrapper is BlueVPN
 * code; the pinned Aether executable is built from CluvexStudio/Aether source
 * in CI and packaged as libbluevpn_aether.so.
 */
object BlueVpnWarpEngine {
    const val BRIDGE_SUBSCRIPTION_ID = "bluevpn_free_warp_aether"
    private const val SOCKS_HOST = "127.0.0.1"
    private const val SOCKS_PORT = 1819
    private const val NATIVE_NAME = "libbluevpn_aether.so"
    private const val START_TIMEOUT_MS = 6_500L

    private val lock = Any()
    @Volatile
    private var process: Process? = null

    @Volatile
    private var bridgeGuid: String = ""

    fun supported(context: Context): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return false
        val native = nativeExecutable(context)
        return native.isFile && native.canExecute()
    }

    fun isRunning(): Boolean = synchronized(lock) { process?.isAlive == true }

    fun isBridgeGuid(guid: String, profile: ProfileItem? = null): Boolean {
        if (guid.isBlank()) return false
        if (guid == bridgeGuid && bridgeGuid.isNotBlank()) return true
        val resolved = profile ?: MmkvManager.decodeServerConfig(guid) ?: return false
        return resolved.subscriptionId == BRIDGE_SUBSCRIPTION_ID &&
            resolved.configType == EConfigType.SOCKS &&
            resolved.server == SOCKS_HOST &&
            resolved.serverPort == SOCKS_PORT.toString()
    }

    suspend fun prepare(context: Context): Result<String> = withContext(Dispatchers.IO) {
        runCatching {
            val app = context.applicationContext
            require(supported(app)) { "Aether runtime is not packaged for this device ABI" }
            ensureProcess(app)
            require(awaitSocksReady()) { "Aether SOCKS endpoint did not become ready" }
            ensureBridgeProfile()
        }
    }

    fun stop() {
        synchronized(lock) {
            val current = process
            process = null
            if (current != null) {
                runCatching { current.destroy() }
                val deadline = SystemClock.elapsedRealtime() + 900L
                while (current.isAlive && SystemClock.elapsedRealtime() < deadline) {
                    runCatching { Thread.sleep(40L) }
                }
                if (current.isAlive) runCatching { current.destroyForcibly() }
            }
        }
    }

    private fun nativeExecutable(context: Context): File =
        File(context.applicationInfo.nativeLibraryDir, NATIVE_NAME)

    private fun ensureProcess(context: Context) {
        synchronized(lock) {
            if (process?.isAlive == true && canConnect(120)) return

            // Never attach to an unknown local SOCKS service. If the port is
            // occupied while our own process is dead, fail closed so a hostile
            // local process cannot become the Free VPN upstream.
            if (process?.isAlive != true && canConnect(120)) {
                throw IllegalStateException("Aether loopback port is already occupied")
            }

            stop()
            val dataDir = File(context.filesDir, "bluevpn-aether").apply { mkdirs() }
            val logFile = File(context.cacheDir, "bluevpn-aether.log")
            val command = listOf(
                nativeExecutable(context).absolutePath,
                "--masque",
                "-4",
                "--scan",
                "turbo",
                "--noize",
                "firewall",
            )
            val builder = ProcessBuilder(command)
                .directory(dataDir)
                .redirectErrorStream(true)
                .redirectOutput(ProcessBuilder.Redirect.appendTo(logFile))
            builder.environment()["HOME"] = dataDir.absolutePath
            builder.environment()["TMPDIR"] = context.cacheDir.absolutePath
            process = builder.start()
        }
    }

    private fun awaitSocksReady(): Boolean {
        val deadline = SystemClock.elapsedRealtime() + START_TIMEOUT_MS
        while (SystemClock.elapsedRealtime() < deadline) {
            val current = synchronized(lock) { process }
            if (current == null || !current.isAlive) return false
            if (canConnect(180)) return true
            Thread.sleep(90L)
        }
        return false
    }

    private fun canConnect(timeoutMs: Int): Boolean = runCatching {
        Socket().use { socket ->
            socket.connect(
                InetSocketAddress(InetAddress.getByName(SOCKS_HOST), SOCKS_PORT),
                timeoutMs,
            )
        }
        true
    }.getOrDefault(false)

    private fun ensureBridgeProfile(): String {
        val existing = MmkvManager.decodeServerList(BRIDGE_SUBSCRIPTION_ID)
            .firstOrNull { guid -> isBridgeGuid(guid) }
        if (!existing.isNullOrBlank()) {
            bridgeGuid = existing
            return existing
        }

        // Clean stale bridge rows before creating exactly one local transport.
        MmkvManager.removeServerViaSubid(BRIDGE_SUBSCRIPTION_ID)
        val profile = ProfileItem.create(EConfigType.SOCKS).apply {
            subscriptionId = BRIDGE_SUBSCRIPTION_ID
            remarks = "BlueVPN Free • Cloudflare WARP"
            description = "Local Aether WARP bridge"
            server = SOCKS_HOST
            serverPort = SOCKS_PORT.toString()
        }
        val guid = MmkvManager.encodeServerConfig("", profile)
        bridgeGuid = guid
        return guid
    }
}
