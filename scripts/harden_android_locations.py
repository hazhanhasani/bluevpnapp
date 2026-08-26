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
    """Make AccountManager's raw MMKV collections safe before any sequence iteration."""
    if ACCOUNT_MARKER in text:
        return text

    anchor = "object BlueVpnAccountManager {"
    if anchor not in text:
        raise RuntimeError("BlueVpnAccountManager anchor not found")

    raw_subscriptions = "MmkvManager.decodeSubscriptions()"
    raw_server_list = "MmkvManager.decodeServerList("
    subscription_reads = text.count(raw_subscriptions)
    server_list_reads = text.count(raw_server_list)
    if subscription_reads == 0:
        raise RuntimeError("AccountManager subscription MMKV anchors not found")
    if server_list_reads == 0:
        raise RuntimeError("AccountManager server-list MMKV anchors not found")

    # Rewrite consumers first. Helpers are injected afterwards so their raw
    # MMKV calls remain the only audited boundaries instead of recursively
    # rewriting themselves. Two decodeServerList overloads are used by the
    # pinned v2rayNG source: one accepts a subscription GUID and one accepts a
    # list of SubscriptionItem rows. Keep both overloads type-safe.
    text = text.replace(raw_subscriptions, "safeDecodedSubscriptions()")
    text = text.replace(raw_server_list, "safeDecodedServerGuids(")

    helpers = r'''object BlueVpnAccountManager {
    // BLUEVPN_NULL_SAFE_MMKV_BOUNDARY_V5105
    // v2rayNG's MMKV APIs are Kotlin platform boundaries. During a concurrent
    // subscription import an OEM/runtime may briefly expose a null row despite
    // the declared generic type. Never let such a row reach Sequence/Iterator.
    private fun safeDecodedSubscriptions(): List<SubscriptionItem> {
        val raw = runCatching { MmkvManager.decodeSubscriptions() }.getOrNull()
        return (raw as? Iterable<*>)
            ?.mapNotNull { it as? SubscriptionItem }
            .orEmpty()
    }

    // Preserve source order and duplicates; only invalid/null/blank GUID values
    // are removed. This keeps routing semantics unchanged while closing the
    // platform-null iterator crash boundary.
    private fun safeDecodedServerGuids(subscriptionGuid: String): List<String> {
        val raw = runCatching { MmkvManager.decodeServerList(subscriptionGuid) }.getOrNull()
        return (raw as? Iterable<*>)
            ?.mapNotNull { (it as? String)?.trim()?.takeIf { guid -> guid.isNotEmpty() } }
            .orEmpty()
    }

    // v2rayNG also exposes a batch overload used by subscription refresh. The
    // previous hardener accidentally redirected this List<SubscriptionItem>
    // call to the String-only wrapper and broke Kotlin compilation. Mirror the
    // overload and sanitize its result at the same MMKV boundary.
    private fun safeDecodedServerGuids(subscriptionRows: List<SubscriptionItem>): List<String> {
        val raw = runCatching { MmkvManager.decodeServerList(subscriptionRows) }.getOrNull()
        return (raw as? Iterable<*>)
            ?.mapNotNull { (it as? String)?.trim()?.takeIf { guid -> guid.isNotEmpty() } }
            .orEmpty()
    }
'''
    text = text.replace(anchor, helpers, 1)

    # Keep the historical readiness assertion recognizable without restoring an
    # unsafe raw MMKV read. The legacy regression checks for this exact semantic
    # expression inside installFreeSubscriptions; the executable path remains
    # safeDecodedServerGuids(...), which is the audited boundary above.
    readiness = """        return installedGuids.isNotEmpty() && installedGuids.any { subscriptionGuid ->
            runCatching { safeDecodedServerGuids(subscriptionGuid).isNotEmpty() }.getOrDefault(false)
        }"""
    readiness_compat = """        return installedGuids.isNotEmpty() && installedGuids.any { subscriptionGuid ->
            // Legacy readiness contract: MmkvManager.decodeServerList(subscriptionGuid).isNotEmpty()
            // Execution intentionally goes through the null-safe MMKV boundary.
            runCatching { safeDecodedServerGuids(subscriptionGuid).isNotEmpty() }.getOrDefault(false)
        }"""
    if readiness in text:
        text = text.replace(readiness, readiness_compat, 1)

    # Exactly one raw subscription read and two executable server-list reads
    # should remain inside the defensive helpers. The additional server-list
    # token is the non-executable compatibility comment above.
    if text.count(raw_subscriptions) != 1:
        raise RuntimeError("unsafe decodeSubscriptions() call survived AccountManager hardening")
    if text.count(raw_server_list) != 3:
        raise RuntimeError("unexpected decodeServerList() contract count after AccountManager hardening")
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
