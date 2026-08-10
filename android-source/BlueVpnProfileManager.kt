package com.v2ray.ang.bluevpn

import com.v2ray.ang.dto.entities.ProfileItem
import com.v2ray.ang.handler.MmkvManager
import org.json.JSONArray
import org.json.JSONObject
import java.net.URI
import java.security.MessageDigest
import java.util.Locale

/**
 * Unified, engine-neutral profile catalogue for BlueVPN.
 *
 * The upstream importer remains the authority for turning V2Ray share links into
 * ProfileItem objects. This layer deliberately sits *after* parsing and gives
 * BlueVPN stable identity, de-duplication, format detection and engine routing
 * without depending on volatile MMKV GUIDs or display remarks.
 *
 * Design ideas mirrored from mature clients such as v2rayNG/NekoBox and from
 * sing-box's typed outbound model:
 *  - source format and runtime protocol are separate concepts;
 *  - subscription refresh must not change the selected semantic server merely
 *    because a new GUID was generated;
 *  - duplicate nodes from multiple subscriptions are collapsed for selection;
 *  - JSON is classified as Xray or sing-box before runtime validation;
 *  - SSH is a first-class sing-box outbound instead of being forced into Xray.
 */
object BlueVpnProfileManager {
    enum class SourceFormat {
        SHARE_LINK,
        XRAY_JSON,
        SING_BOX_JSON,
        SSH_URI,
        UNKNOWN,
    }

    enum class Protocol {
        VLESS,
        VMESS,
        TROJAN,
        SHADOWSOCKS,
        SOCKS,
        HTTP,
        SSH,
        HYSTERIA2,
        TUIC,
        WIREGUARD,
        ANYTLS,
        CUSTOM_JSON,
        UNKNOWN,
    }

    enum class EngineRoute {
        XRAY,
        SING_BOX,
        XRAY_OR_SING_BOX,
        UNKNOWN,
    }

    data class Descriptor(
        val sourceFormat: SourceFormat,
        val protocol: Protocol,
        val engineRoute: EngineRoute,
        val fingerprint: String,
    )

    // Connection-affecting ProfileItem fields from pinned v2rayNG 2.2.6.
    // Remarks, description, subscriptionId and addedTime are intentionally
    // excluded because they are presentation/ownership metadata, not endpoint
    // identity. Keeping the transport-specific fields prevents false de-dupes.
    private val identityGetters = listOf(
        "getConfigType",
        "getServer",
        "getServerPort",
        "getPassword",
        "getMethod",
        "getFlow",
        "getUsername",
        "getNetwork",
        "getHeaderType",
        "getHost",
        "getPath",
        "getSeed",
        "getKcpMtu",
        "getKcpTti",
        "getQuicSecurity",
        "getQuicKey",
        "getMode",
        "getServiceName",
        "getAuthority",
        "getXhttpMode",
        "getXhttpExtra",
        "getFinalMask",
        "getSecurity",
        "getSni",
        "getAlpn",
        "getFingerPrint",
        "getInsecure",
        "getEchConfigList",
        "getVerifyPeerCertByName",
        "getPinnedCA256",
        "getPublicKey",
        "getShortId",
        "getSpiderX",
        "getMldsa65Verify",
        "getSecretKey",
        "getPreSharedKey",
        "getLocalAddress",
        "getReserved",
        "getMtu",
        "getObfsPassword",
        "getPortHopping",
        "getPortHoppingInterval",
        "getPinSHA256",
        "getBandwidthDown",
        "getBandwidthUp",
    )

    fun describe(profile: ProfileItem, rawConfig: String? = null): Descriptor {
        val raw = rawConfig.orEmpty().trim()
        val format = sourceFormat(raw)
        val protocol = detectProtocol(profile, raw)
        return Descriptor(
            sourceFormat = format,
            protocol = protocol,
            engineRoute = engineFor(protocol, format),
            fingerprint = fingerprint(profile, raw),
        )
    }

    fun sourceFormat(rawConfig: String?): SourceFormat {
        val raw = rawConfig.orEmpty().trim()
        if (raw.isBlank()) return SourceFormat.SHARE_LINK
        val lower = raw.lowercase(Locale.ROOT)
        if (lower.startsWith("ssh://")) return SourceFormat.SSH_URI
        if (
            lower.startsWith("vless://") || lower.startsWith("vmess://") ||
            lower.startsWith("trojan://") || lower.startsWith("ss://") ||
            lower.startsWith("socks://") || lower.startsWith("socks5://") ||
            lower.startsWith("http://") || lower.startsWith("https://") ||
            lower.startsWith("hysteria2://") || lower.startsWith("hy2://") ||
            lower.startsWith("tuic://") || lower.startsWith("wireguard://") ||
            lower.startsWith("anytls://")
        ) {
            return SourceFormat.SHARE_LINK
        }
        if (raw.startsWith("{") || raw.startsWith("[")) {
            return when {
                looksLikeSingBoxJson(raw) -> SourceFormat.SING_BOX_JSON
                looksLikeXrayJson(raw) -> SourceFormat.XRAY_JSON
                else -> SourceFormat.UNKNOWN
            }
        }
        return SourceFormat.UNKNOWN
    }

    fun fingerprint(profile: ProfileItem, rawConfig: String? = null): String {
        val raw = rawConfig.orEmpty().trim()
        val semantic = buildString {
            append("v2|")
            identityGetters.forEach { getter ->
                val value = readGetter(profile, getter)
                if (value.isNotBlank()) {
                    append(getter.removePrefix("get").lowercase(Locale.ROOT))
                    append('=')
                    append(normalizeField(getter, value))
                    append('|')
                }
            }

            // Custom JSON/SSH may carry details that ProfileItem cannot model.
            // Canonicalize before hashing so whitespace/key ordering never creates
            // a duplicate node after a subscription refresh.
            if (raw.isNotBlank()) {
                when (sourceFormat(raw)) {
                    SourceFormat.XRAY_JSON,
                    SourceFormat.SING_BOX_JSON -> {
                        append("rawjson=")
                        append(canonicalizeJson(raw))
                    }
                    SourceFormat.SSH_URI -> {
                        append("ssh=")
                        append(canonicalizeUri(raw))
                    }
                    else -> Unit
                }
            }
        }
        return sha256(semantic).take(40)
    }

    fun fingerprintGuid(guid: String): String? {
        if (guid.isBlank()) return null
        val profile = MmkvManager.decodeServerConfig(guid) ?: return null
        return fingerprint(profile, MmkvManager.decodeServerRaw(guid))
    }

    /** Keep the selected duplicate first, then preserve original subscription order. */
    fun uniqueGuids(guids: List<String>, selectedGuid: String? = null): List<String> {
        if (guids.size < 2) return guids.filter { it.isNotBlank() }
        val ordered = buildList {
            val selected = selectedGuid.orEmpty()
            if (selected.isNotBlank() && selected in guids) add(selected)
            guids.forEach { if (it != selected) add(it) }
        }
        val seen = HashSet<String>(ordered.size)
        return ordered.filter { guid ->
            val key = fingerprintGuid(guid) ?: "guid:$guid"
            seen.add(key)
        }
    }

    fun captureSelectedFingerprint(subscriptionIds: Set<String>): String? {
        if (subscriptionIds.isEmpty()) return null
        val selected = MmkvManager.getSelectServer().orEmpty()
        if (selected.isBlank()) return null
        val profile = MmkvManager.decodeServerConfig(selected) ?: return null
        if (profile.subscriptionId.orEmpty() !in subscriptionIds) return null
        return fingerprint(profile, MmkvManager.decodeServerRaw(selected))
    }

    /**
     * Rebind a selected semantic profile after an upstream subscription refresh.
     * v2rayNG may legitimately generate fresh MMKV GUIDs on every import; the user
     * should still remain on the same endpoint/profile when it still exists.
     */
    fun restoreSelectedFingerprint(
        fingerprint: String?,
        candidateGuids: List<String>,
    ): String? {
        if (fingerprint.isNullOrBlank()) return null
        val match = candidateGuids.firstOrNull { guid ->
            fingerprintGuid(guid) == fingerprint
        } ?: return null
        MmkvManager.setSelectServer(match)
        return match
    }

    private fun detectProtocol(profile: ProfileItem, raw: String): Protocol {
        val lowerRaw = raw.lowercase(Locale.ROOT)
        when {
            lowerRaw.startsWith("ssh://") -> return Protocol.SSH
            lowerRaw.startsWith("vless://") -> return Protocol.VLESS
            lowerRaw.startsWith("vmess://") -> return Protocol.VMESS
            lowerRaw.startsWith("trojan://") -> return Protocol.TROJAN
            lowerRaw.startsWith("ss://") -> return Protocol.SHADOWSOCKS
            lowerRaw.startsWith("socks://") || lowerRaw.startsWith("socks5://") -> return Protocol.SOCKS
            lowerRaw.startsWith("http://") || lowerRaw.startsWith("https://") -> return Protocol.HTTP
            lowerRaw.startsWith("hysteria2://") || lowerRaw.startsWith("hy2://") -> return Protocol.HYSTERIA2
            lowerRaw.startsWith("tuic://") -> return Protocol.TUIC
            lowerRaw.startsWith("wireguard://") -> return Protocol.WIREGUARD
            lowerRaw.startsWith("anytls://") -> return Protocol.ANYTLS
        }

        val configType = readGetter(profile, "getConfigType").uppercase(Locale.ROOT)
        return when {
            "VLESS" in configType -> Protocol.VLESS
            "VMESS" in configType -> Protocol.VMESS
            "TROJAN" in configType -> Protocol.TROJAN
            "SHADOWSOCKS" in configType -> Protocol.SHADOWSOCKS
            "SOCKS" in configType -> Protocol.SOCKS
            "HTTP" in configType -> Protocol.HTTP
            "HYSTERIA" in configType -> Protocol.HYSTERIA2
            "TUIC" in configType -> Protocol.TUIC
            "WIREGUARD" in configType -> Protocol.WIREGUARD
            "ANYTLS" in configType -> Protocol.ANYTLS
            sourceFormat(raw) == SourceFormat.SING_BOX_JSON && jsonHasOutboundType(raw, "ssh") -> Protocol.SSH
            sourceFormat(raw) in setOf(SourceFormat.XRAY_JSON, SourceFormat.SING_BOX_JSON) -> Protocol.CUSTOM_JSON
            else -> Protocol.UNKNOWN
        }
    }

    private fun engineFor(protocol: Protocol, sourceFormat: SourceFormat): EngineRoute = when {
        protocol == Protocol.SSH -> EngineRoute.SING_BOX
        sourceFormat == SourceFormat.SING_BOX_JSON -> EngineRoute.SING_BOX
        sourceFormat == SourceFormat.XRAY_JSON -> EngineRoute.XRAY
        protocol in setOf(
            Protocol.VLESS,
            Protocol.VMESS,
            Protocol.TROJAN,
            Protocol.SHADOWSOCKS,
            Protocol.SOCKS,
            Protocol.HTTP,
            Protocol.HYSTERIA2,
            Protocol.TUIC,
            Protocol.WIREGUARD,
            Protocol.ANYTLS,
        ) -> EngineRoute.XRAY_OR_SING_BOX
        else -> EngineRoute.UNKNOWN
    }

    private fun jsonObjects(raw: String): List<JSONObject> = runCatching {
        when {
            raw.trimStart().startsWith("[") -> {
                val array = JSONArray(raw)
                (0 until array.length()).mapNotNull { array.optJSONObject(it) }
            }
            else -> listOf(JSONObject(raw))
        }
    }.getOrDefault(emptyList())

    private fun outboundObjects(raw: String): List<JSONObject> = jsonObjects(raw).flatMap { root ->
        val outbounds = root.optJSONArray("outbounds")
        when {
            outbounds != null -> (0 until outbounds.length()).mapNotNull { outbounds.optJSONObject(it) }
            root.has("type") || root.has("protocol") -> listOf(root)
            else -> emptyList()
        }
    }

    private fun looksLikeXrayJson(raw: String): Boolean =
        outboundObjects(raw).any { it.has("protocol") }

    private fun looksLikeSingBoxJson(raw: String): Boolean =
        outboundObjects(raw).any { it.has("type") }

    private fun jsonHasOutboundType(raw: String, type: String): Boolean =
        outboundObjects(raw).any { outbound ->
            outbound.optString("type").equals(type, ignoreCase = true)
        }

    private fun readGetter(profile: ProfileItem, name: String): String {
        val method = profile.javaClass.methods.firstOrNull {
            it.name == name && it.parameterTypes.isEmpty()
        } ?: return ""
        return runCatching { method.invoke(profile)?.toString().orEmpty().trim() }
            .getOrDefault("")
    }

    private fun normalizeField(getter: String, value: String): String = when (getter) {
        "getServer", "getConfigType", "getNetwork", "getHeaderType", "getStreamSecurity" ->
            value.trim().lowercase(Locale.ROOT)
        else -> value.trim()
    }

    private fun canonicalizeUri(raw: String): String = runCatching {
        val uri = URI(raw)
        val scheme = uri.scheme.orEmpty().lowercase(Locale.ROOT)
        val host = uri.host.orEmpty().lowercase(Locale.ROOT)
        val port = if (uri.port > 0) ":${uri.port}" else ""
        val authority = if (host.isNotBlank()) {
            val user = uri.rawUserInfo?.let { "$it@" }.orEmpty()
            "$user$host$port"
        } else {
            uri.rawAuthority.orEmpty()
        }
        val query = uri.rawQuery.orEmpty()
            .split('&')
            .filter { it.isNotBlank() }
            .sorted()
            .joinToString("&")
        buildString {
            append(scheme)
            append("://")
            append(authority)
            append(uri.rawPath.orEmpty())
            if (query.isNotBlank()) append('?').append(query)
            // fragment is display-only in common proxy URI formats.
        }
    }.getOrDefault(raw.substringBefore('#').trim())

    private fun canonicalizeJson(raw: String): String = runCatching {
        val trimmed = raw.trimStart()
        if (trimmed.startsWith("[")) canonicalJson(JSONArray(raw))
        else canonicalJson(JSONObject(raw))
    }.getOrDefault(raw.replace(Regex("\\s+"), ""))

    private fun canonicalJson(value: Any?): String = when (value) {
        null, JSONObject.NULL -> "null"
        is JSONObject -> {
            val keys = mutableListOf<String>()
            val iterator = value.keys()
            while (iterator.hasNext()) keys += iterator.next()
            keys.sorted().joinToString(prefix = "{", postfix = "}") { key ->
                JSONObject.quote(key) + ":" + canonicalJson(value.opt(key))
            }
        }
        is JSONArray -> (0 until value.length()).joinToString(prefix = "[", postfix = "]") { index ->
            canonicalJson(value.opt(index))
        }
        is String -> JSONObject.quote(value)
        is Number, is Boolean -> value.toString()
        else -> JSONObject.quote(value.toString())
    }

    private fun sha256(text: String): String = MessageDigest.getInstance("SHA-256")
        .digest(text.toByteArray(Charsets.UTF_8))
        .joinToString("") { "%02x".format(it) }
}
