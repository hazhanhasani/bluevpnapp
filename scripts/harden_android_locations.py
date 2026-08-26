#!/usr/bin/env python3
"""Harden BlueVPN Android location/MMKV decoding against corrupt or null rows.

Some OEM/MMKV/import races can expose platform collections containing null/blank
entries even though Kotlin sees them as non-null lists. R8 can then optimize the
iterator path under that contract and surface an obfuscated getClass()/hasNext
NullPointerException. Patch both the canonical overlay and generated upstream
sources before Gradle so every location-pool boundary is defensive.
"""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
LOCATION_MARKER = "// BLUEVPN_NULL_SAFE_LOCATION_POOL_V5103"
ACCOUNT_MARKER = "// BLUEVPN_NULL_SAFE_MMKV_BOUNDARY_V5105"


def _replace_kotlin_calls(text: str, call: str, replacement: str) -> tuple[str, int]:
    """Replace exact executable Kotlin call-sites while skipping comments/strings.

    This deliberately avoids str.replace() over an entire source file. The old
    AccountManager hardener rewrote every textual MMKV occurrence and accidentally
    changed the generic element type seen by Kotlin. We only rewrite real code
    tokens and leave comments/string literals untouched.
    """
    out: list[str] = []
    i = 0
    changed = 0
    n = len(text)
    state = "code"

    while i < n:
        if state == "code":
            if text.startswith("//", i):
                state = "line_comment"
                out.append("//")
                i += 2
                continue
            if text.startswith("/*", i):
                state = "block_comment"
                out.append("/*")
                i += 2
                continue
            if text.startswith('"""', i):
                state = "triple_string"
                out.append('"""')
                i += 3
                continue
            if text[i] == '"':
                state = "string"
                out.append(text[i])
                i += 1
                continue
            if text[i] == "'":
                state = "char"
                out.append(text[i])
                i += 1
                continue
            if text.startswith(call, i):
                out.append(replacement)
                i += len(call)
                changed += 1
                continue
            out.append(text[i])
            i += 1
            continue

        if state == "line_comment":
            out.append(text[i])
            if text[i] == "\n":
                state = "code"
            i += 1
            continue

        if state == "block_comment":
            if text.startswith("*/", i):
                out.append("*/")
                i += 2
                state = "code"
            else:
                out.append(text[i])
                i += 1
            continue

        if state == "triple_string":
            if text.startswith('"""', i):
                out.append('"""')
                i += 3
                state = "code"
            else:
                out.append(text[i])
                i += 1
            continue

        if state in {"string", "char"}:
            ch = text[i]
            out.append(ch)
            i += 1
            if ch == "\\" and i < n:
                out.append(text[i])
                i += 1
                continue
            if (state == "string" and ch == '"') or (state == "char" and ch == "'"):
                state = "code"
            continue

    return "".join(out), changed


def harden_location_util(text: str) -> str:
    if LOCATION_MARKER in text:
        return text

    anchor = "object BlueVpnLocationUtil {"
    if anchor not in text:
        raise RuntimeError("BlueVpnLocationUtil anchor not found")
    text = text.replace(anchor, LOCATION_MARKER + "\n" + anchor, 1)

    old_global = "val allGuids = MmkvManager.decodeAllServerList()"
    new_global = """val allGuids = (MmkvManager.decodeAllServerList() as? Iterable<*>)
            ?.mapNotNull { (it as? String)?.trim()?.takeIf { guid -> guid.isNotEmpty() } }
            ?.distinct()
            .orEmpty()"""
    if old_global not in text:
        raise RuntimeError("global GUID inventory anchor not found")
    text = text.replace(old_global, new_global, 1)

    old_entitlement = "val entitlementGuidList = BlueVpnAccountManager.preferredServerGuids(context)"
    new_entitlement = """val entitlementGuidList = (BlueVpnAccountManager.preferredServerGuids(context) as? Iterable<*>)
            ?.mapNotNull { (it as? String)?.trim()?.takeIf { guid -> guid.isNotEmpty() } }
            ?.distinct()
            .orEmpty()"""
    if old_entitlement not in text:
        raise RuntimeError("entitlement GUID inventory anchor not found")
    text = text.replace(old_entitlement, new_entitlement, 1)

    text = text.replace(
        "MmkvManager.decodeServerConfig(guid)",
        "runCatching { MmkvManager.decodeServerConfig(guid) }.getOrNull()",
    )
    text = text.replace(
        "MmkvManager.decodeServerRaw(guid)",
        "runCatching { MmkvManager.decodeServerRaw(guid) }.getOrNull()",
    )
    return text


def harden_account_manager(text: str) -> str:
    """Sanitize MMKV collections without changing AccountManager's Kotlin types."""
    if ACCOUNT_MARKER in text:
        return text

    anchor = "object BlueVpnAccountManager {"
    if anchor not in text:
        raise RuntimeError("BlueVpnAccountManager anchor not found")

    # v2rayNG 2.3.5 declares decodeSubscriptions(): List<SubscriptionCache>.
    # SubscriptionCache owns both `.guid` and `.subscription`; changing this to
    # SubscriptionItem is exactly what caused the 5.10.5 compile regression.
    cache_import = "import com.v2ray.ang.dto.SubscriptionCache"
    if cache_import not in text:
        import_anchor = "import com.v2ray.ang.dto.SubscriptionItem"
        if import_anchor not in text:
            raise RuntimeError("SubscriptionItem import anchor not found")
        text = text.replace(import_anchor, import_anchor + "\n" + cache_import, 1)

    raw_subscriptions = "MmkvManager.decodeSubscriptions()"
    raw_server_list = "MmkvManager.decodeServerList("

    text, subscription_reads = _replace_kotlin_calls(
        text,
        raw_subscriptions,
        "safeDecodedSubscriptions()",
    )
    text, server_list_reads = _replace_kotlin_calls(
        text,
        raw_server_list,
        "safeDecodedServerGuids(",
    )
    if subscription_reads == 0:
        raise RuntimeError("AccountManager subscription MMKV call-sites not found")
    if server_list_reads == 0:
        raise RuntimeError("AccountManager server-list MMKV call-sites not found")

    helpers = r'''object BlueVpnAccountManager {
    // BLUEVPN_NULL_SAFE_MMKV_BOUNDARY_V5105
    // Preserve the exact upstream generic contract: SubscriptionCache exposes
    // both `guid` and `subscription`, which AccountManager consumers require.
    private fun safeDecodedSubscriptions(): List<SubscriptionCache> {
        val raw = runCatching { MmkvManager.decodeSubscriptions() }.getOrNull()
        return (raw as? Iterable<*>)
            ?.mapNotNull { it as? SubscriptionCache }
            .orEmpty()
    }

    // Pinned v2rayNG 2.3.5 exposes decodeServerList(subscriptionId: String).
    // Keep the signature exact and remove only invalid/null/blank GUID rows.
    private fun safeDecodedServerGuids(subscriptionGuid: String): List<String> {
        val raw = runCatching { MmkvManager.decodeServerList(subscriptionGuid) }.getOrNull()
        return (raw as? Iterable<*>)
            ?.mapNotNull { (it as? String)?.trim()?.takeIf { guid -> guid.isNotEmpty() } }
            .orEmpty()
    }
'''
    text = text.replace(anchor, helpers, 1)

    # The only raw calls left must be the two audited helper boundaries above.
    executable_probe = text.replace(helpers, "", 1)
    if raw_subscriptions in executable_probe:
        raise RuntimeError("unsafe decodeSubscriptions() call survived AccountManager hardening")
    # Comments may legitimately mention the API; verify executable calls by
    # asking the lexical rewriter whether anything remains outside the helper.
    _, leftover_server_calls = _replace_kotlin_calls(
        executable_probe,
        raw_server_list,
        "__UNEXPECTED_SERVER_LIST_CALL__(",
    )
    if leftover_server_calls != 0:
        raise RuntimeError("unsafe decodeServerList() call survived AccountManager hardening")
    return text


def harden_servers_activity(text: str) -> str:
    marker = "// BLUEVPN_LOCATION_STALE_CACHE_FALLBACK_V5103"
    if marker in text:
        return text
    old = """                val loaded = result.getOrDefault(emptyList())
                candidateLoadError = result.exceptionOrNull()?.let {
                    \"دریافت سرورها ناموفق بود؛ تازه‌سازی را بزنید\"
                }.orEmpty()"""
    new = """                // BLUEVPN_LOCATION_STALE_CACHE_FALLBACK_V5103
                // Never blank a previously healthy screen because one MMKV row
                // or one concurrent import snapshot failed to decode.
                val loaded = result.getOrElse {
                    BlueVpnLocationUtil.cachedCandidates(this@BlueVpnServersActivity)
                }
                candidateLoadError = result.exceptionOrNull()?.let {
                    \"دریافت بخشی از سرورها ناموفق بود؛ آخرین فهرست سالم حفظ شد\"
                }.orEmpty()"""
    if old not in text:
        raise RuntimeError("BlueVpnServersActivity result anchor not found")
    return text.replace(old, new, 1)


def patch(path: Path, transform) -> bool:
    if not path.is_file():
        return False
    original = path.read_text(encoding="utf-8")
    updated = transform(original)
    if updated == original:
        return False
    path.write_text(updated, encoding="utf-8")
    print(f"BlueVPN Android hardening patched: {path}")
    return True


def apply() -> None:
    candidates = [
        (ROOT / "android-source" / "BlueVpnLocationUtil.kt", harden_location_util),
        (ROOT / "android-source" / "BlueVpnAccountManager.kt", harden_account_manager),
        (ROOT / "android-source" / "BlueVpnServersActivity.kt", harden_servers_activity),
        (ROOT / "upstream" / "V2rayNG" / "app" / "src" / "main" / "kotlin" / "com" / "v2ray" / "ang" / "bluevpn" / "BlueVpnLocationUtil.kt", harden_location_util),
        (ROOT / "upstream" / "V2rayNG" / "app" / "src" / "main" / "kotlin" / "com" / "v2ray" / "ang" / "bluevpn" / "BlueVpnAccountManager.kt", harden_account_manager),
        (ROOT / "upstream" / "V2rayNG" / "app" / "src" / "main" / "kotlin" / "com" / "v2ray" / "ang" / "ui" / "BlueVpnServersActivity.kt", harden_servers_activity),
    ]
    patched = 0
    for path, transform in candidates:
        patched += int(patch(path, transform))
    print(f"BlueVPN Android location hardening complete ({patched} files changed).")


if __name__ == "__main__":
    apply()
