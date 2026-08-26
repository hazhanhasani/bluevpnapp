#!/usr/bin/env python3
"""Harden BlueVPN Android location-pool decoding against corrupt/null MMKV rows.

Some OEM/MMKV/import races can expose platform collections containing null/blank
entries even though Kotlin sees them as List<String>. R8 then optimizes iterator
paths under that non-null contract and a null element can surface as an obfuscated
getClass()/hasNext NullPointerException. Patch both the authoritative overlay source
and the generated upstream source before Gradle so release builds are defensive.
"""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MARKER = "// BLUEVPN_NULL_SAFE_LOCATION_POOL_V5103"


def harden_location_util(text: str) -> str:
    if MARKER in text:
        return text

    anchor = "object BlueVpnLocationUtil {"
    if anchor not in text:
        raise RuntimeError("BlueVpnLocationUtil anchor not found")
    text = text.replace(anchor, MARKER + "\n" + anchor, 1)

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

    # A single corrupt serialized row must be skipped instead of terminating the
    # whole location coroutine. These exact calls are safe to wrap everywhere in
    # this file because all callers already treat a missing decode as unusable.
    text = text.replace(
        "MmkvManager.decodeServerConfig(guid)",
        "runCatching { MmkvManager.decodeServerConfig(guid) }.getOrNull()",
    )
    text = text.replace(
        "MmkvManager.decodeServerRaw(guid)",
        "runCatching { MmkvManager.decodeServerRaw(guid) }.getOrNull()",
    )
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
        (ROOT / "android-source" / "BlueVpnServersActivity.kt", harden_servers_activity),
        (ROOT / "upstream" / "V2rayNG" / "app" / "src" / "main" / "kotlin" / "com" / "v2ray" / "ang" / "bluevpn" / "BlueVpnLocationUtil.kt", harden_location_util),
        (ROOT / "upstream" / "V2rayNG" / "app" / "src" / "main" / "kotlin" / "com" / "v2ray" / "ang" / "ui" / "BlueVpnServersActivity.kt", harden_servers_activity),
    ]
    patched = 0
    for path, transform in candidates:
        patched += int(patch(path, transform))
    print(f"BlueVPN Android location hardening complete ({patched} files changed).")


if __name__ == "__main__":
    apply()
