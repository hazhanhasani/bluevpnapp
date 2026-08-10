package com.v2ray.ang.bluevpn

import org.json.JSONArray
import org.json.JSONObject
import java.net.URI
import java.net.URLDecoder

/**
 * Builds validation/test profiles for sing-box-only transports such as SSH.
 * Xray remains the owner of Android TUN in the current migration stage; this
 * compiler lets BlueVPN parse and natively validate SSH/sing-box sources now,
 * while keeping the runtime boundary ready for the later TUN hand-off/bridge.
 */
object BlueVpnSingBoxProfileCompiler {
    const val LOCAL_MIXED_PORT = 21080

    data class CompileResult(
        val json: String,
        val protocol: String,
        val displayName: String,
    )

    fun compile(raw: String): Result<CompileResult> = runCatching {
        val input = raw.trim()
        require(input.isNotBlank()) { "empty profile" }
        when {
            input.startsWith("ssh://", ignoreCase = true) -> compileSshUri(input)
            input.startsWith("{") -> compileJson(input)
            else -> error("unsupported sing-box source")
        }
    }

    private fun compileSshUri(raw: String): CompileResult {
        val uri = URI(raw)
        val host = uri.host.orEmpty().trim()
        require(host.isNotBlank()) { "SSH server is missing" }
        val port = uri.port.takeIf { it in 1..65535 } ?: 22
        val userInfo = uri.rawUserInfo.orEmpty()
        val username = decode(userInfo.substringBefore(':')).ifBlank { "root" }
        val password = if (':' in userInfo) decode(userInfo.substringAfter(':')) else ""
        val query = parseQuery(uri.rawQuery.orEmpty())

        val outbound = JSONObject()
            .put("type", "ssh")
            .put("tag", "bluevpn-ssh")
            .put("server", host)
            .put("server_port", port)
            .put("user", username)
        if (password.isNotBlank()) outbound.put("password", password)
        query["private_key"]?.takeIf { it.isNotBlank() }?.let { outbound.put("private_key", it) }
        query["private_key_passphrase"]?.takeIf { it.isNotBlank() }
            ?.let { outbound.put("private_key_passphrase", it) }
        query["host_key"]?.takeIf { it.isNotBlank() }?.let { rawKeys ->
            val keys = JSONArray()
            rawKeys.split('|').map { it.trim() }.filter { it.isNotBlank() }.forEach { keys.put(it) }
            if (keys.length() > 0) outbound.put("host_key", keys)
        }
        query["client_version"]?.takeIf { it.isNotBlank() }
            ?.let { outbound.put("client_version", it) }

        val root = localProxyDocument(outbound)
        return CompileResult(
            json = root.toString(),
            protocol = "ssh",
            displayName = decode(uri.rawFragment.orEmpty()).ifBlank { host },
        )
    }

    private fun compileJson(raw: String): CompileResult {
        val root = JSONObject(raw)
        if (root.has("inbounds") && root.has("outbounds")) {
            return CompileResult(raw, "sing-box-json", "sing-box")
        }

        // Accept a single sing-box outbound object, including {"type":"ssh",...}.
        val type = root.optString("type").trim().lowercase()
        require(type.isNotBlank()) { "sing-box outbound type is missing" }
        val outbound = JSONObject(root.toString())
        if (outbound.optString("tag").isBlank()) outbound.put("tag", "bluevpn-proxy")
        return CompileResult(
            json = localProxyDocument(outbound).toString(),
            protocol = type,
            displayName = outbound.optString("tag", type),
        )
    }

    private fun localProxyDocument(outbound: JSONObject): JSONObject = JSONObject()
        .put(
            "log",
            JSONObject().put("level", "warn").put("timestamp", true),
        )
        .put(
            "inbounds",
            JSONArray().put(
                JSONObject()
                    .put("type", "mixed")
                    .put("tag", "bluevpn-local")
                    .put("listen", "127.0.0.1")
                    .put("listen_port", LOCAL_MIXED_PORT),
            ),
        )
        .put("outbounds", JSONArray().put(outbound))
        .put(
            "route",
            JSONObject().put("final", outbound.optString("tag", "bluevpn-proxy")),
        )

    private fun parseQuery(raw: String): Map<String, String> = raw
        .split('&')
        .mapNotNull { pair ->
            if (pair.isBlank()) return@mapNotNull null
            val key = decode(pair.substringBefore('='))
            val value = if ('=' in pair) decode(pair.substringAfter('=')) else ""
            key to value
        }
        .toMap()

    private fun decode(value: String): String = runCatching {
        URLDecoder.decode(value, "UTF-8")
    }.getOrDefault(value)
}
