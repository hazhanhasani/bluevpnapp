package com.v2ray.ang.bluevpn

import android.content.Context
import android.os.Build
import android.util.Log
import org.json.JSONObject
import java.io.File
import java.util.concurrent.atomic.AtomicReference

/**
 * Isolated native sing-box process.
 *
 * We intentionally do not package libbox.aar next to libv2ray.aar: both are
 * gomobile artifacts and would ship duplicate go.Seq/libgojni classes. A PIE
 * executable stored as an extracted Android native library keeps both runtimes
 * isolated and allows a later dedicated VpnService to take ownership safely.
 */
object BlueVpnSingBoxProcess {
    private const val TAG = "BlueVpnSingBox"
    private const val BINARY_NAME = "libbluevpn_singbox.so"
    private const val PROFILE_NAME = "sing-box.json"
    private const val VERIFIED_MARKER = "sing-box.verified"
    private val processRef = AtomicReference<Process?>(null)

    fun binary(context: Context): File =
        File(context.applicationInfo.nativeLibraryDir, BINARY_NAME)

    fun profile(context: Context): File =
        File(File(context.filesDir, "bluevpn-runtime").apply { mkdirs() }, PROFILE_NAME)

    fun isRuntimeAvailable(context: Context): Boolean {
        val file = binary(context)
        return Build.VERSION.SDK_INT >= Build.VERSION_CODES.N && file.isFile && file.canExecute()
    }

    fun hasValidatedProfile(context: Context): Boolean {
        val profile = profile(context)
        val marker = File(profile.parentFile, VERIFIED_MARKER)
        return profile.isFile && profile.length() > 2L && marker.isFile &&
            marker.lastModified() >= profile.lastModified()
    }

    /** Stores only full sing-box JSON documents and validates them natively. */
    fun installProfile(context: Context, json: String): Result<Unit> = runCatching {
        val root = JSONObject(json)
        require(root.has("inbounds")) { "sing-box profile has no inbounds" }
        require(root.has("outbounds")) { "sing-box profile has no outbounds" }
        val target = profile(context)
        val temp = File(target.parentFile, "$PROFILE_NAME.tmp")
        temp.writeText(root.toString(), Charsets.UTF_8)
        validate(context, temp).getOrThrow()
        if (target.exists() && !target.delete()) error("cannot replace sing-box profile")
        if (!temp.renameTo(target)) error("cannot commit sing-box profile")
        File(target.parentFile, VERIFIED_MARKER).writeText(System.currentTimeMillis().toString())
    }

    /** Parse a managed SSH/sing-box source, validate it with the pinned native
     * runtime, and only then atomically promote it to the active profile. */
    fun installManagedSource(
        context: Context,
        raw: String,
    ): Result<BlueVpnSingBoxProfileCompiler.CompileResult> =
        BlueVpnSingBoxProfileCompiler.compile(raw).mapCatching { compiled ->
            installProfile(context, compiled.json).getOrThrow()
            compiled
        }

    fun validate(context: Context, candidate: File = profile(context)): Result<Unit> = runCatching {
        check(isRuntimeAvailable(context)) { "sing-box native runtime is unavailable" }
        check(candidate.isFile) { "sing-box profile is missing" }
        val result = runCommand(context, listOf("check", "-c", candidate.absolutePath), 20)
        check(result.exitCode == 0) { result.output.ifBlank { "sing-box rejected the profile" } }
    }

    @Synchronized
    fun start(context: Context): Result<Unit> = runCatching {
        stop()
        validate(context).getOrThrow()
        val logFile = File(profile(context).parentFile, "sing-box.log")
        val process = ProcessBuilder(
            binary(context).absolutePath,
            "run",
            "-c",
            profile(context).absolutePath,
        )
            .directory(profile(context).parentFile)
            .redirectErrorStream(true)
            .start()
        drainOutput(process, logFile)
        Thread.sleep(180L)
        check(isAlive(process)) { "sing-box stopped during startup" }
        processRef.set(process)
    }

    @Synchronized
    fun stop() {
        val process = processRef.getAndSet(null) ?: return
        runCatching { process.destroy() }
        waitUntilStopped(process, 800L)
        if (isAlive(process)) {
            // Process.destroyForcibly()/Process.isAlive are API 26. Repeating
            // destroy keeps this runtime compatible with the app's API 24 floor.
            runCatching { process.destroy() }
        }
    }

    fun version(context: Context): String? = runCatching {
        runCommand(context, listOf("version"), 8).output.lineSequence().firstOrNull()
    }.getOrNull()

    private data class CommandResult(val exitCode: Int, val output: String)

    private fun runCommand(
        context: Context,
        arguments: List<String>,
        timeoutSeconds: Long,
    ): CommandResult {
        val process = ProcessBuilder(listOf(binary(context).absolutePath) + arguments)
            .directory(profile(context).parentFile)
            .redirectErrorStream(true)
            .start()
        val output = StringBuilder()
        val reader = Thread({
            process.inputStream.bufferedReader().useLines { lines ->
                lines.forEach { line ->
                    if (output.length < 16_384) {
                        output.appendLine(line)
                    }
                }
            }
        }, "bluevpn-sing-box-command-output").apply {
            isDaemon = true
            start()
        }
        if (!waitUntilStopped(process, timeoutSeconds * 1_000L)) {
            runCatching { process.destroy() }
            error("sing-box command timed out")
        }
        runCatching { reader.join(250L) }
        val result = CommandResult(process.exitValue(), output.toString().trim())
        Log.d(TAG, "command=${arguments.firstOrNull()} exit=${result.exitCode}")
        return result
    }

    private fun drainOutput(process: Process, logFile: File) {
        Thread({
            runCatching {
                logFile.parentFile?.mkdirs()
                logFile.outputStream().buffered().use { output ->
                    process.inputStream.use { input -> input.copyTo(output, 8 * 1024) }
                }
            }.onFailure { Log.w(TAG, "Could not persist sing-box output", it) }
        }, "bluevpn-sing-box-log").apply {
            isDaemon = true
            start()
        }
    }

    private fun waitUntilStopped(process: Process, timeoutMillis: Long): Boolean {
        val deadline = System.currentTimeMillis() + timeoutMillis.coerceAtLeast(0L)
        while (isAlive(process) && System.currentTimeMillis() < deadline) {
            try {
                Thread.sleep(25L)
            } catch (_: InterruptedException) {
                Thread.currentThread().interrupt()
                break
            }
        }
        return !isAlive(process)
    }

    private fun isAlive(process: Process): Boolean = try {
        process.exitValue()
        false
    } catch (_: IllegalThreadStateException) {
        true
    }
}
