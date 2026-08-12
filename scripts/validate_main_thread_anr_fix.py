from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def require(path: Path, needle: str, label: str) -> None:
    text = path.read_text(encoding="utf-8")
    if needle not in text:
        raise AssertionError(f"{label}: missing {needle!r} in {path.relative_to(ROOT)}")


def forbid(path: Path, needle: str, label: str) -> None:
    text = path.read_text(encoding="utf-8")
    if needle in text:
        raise AssertionError(f"{label}: forbidden {needle!r} in {path.relative_to(ROOT)}")


def section(text: str, start: str, end: str) -> str:
    if start not in text or end not in text.split(start, 1)[1]:
        raise AssertionError(f"cannot isolate section {start!r} -> {end!r}")
    return text.split(start, 1)[1].split(end, 1)[0]


def main() -> None:
    entitlement = ROOT / "android-source/BlueVpnEntitlement.kt"
    selector = ROOT / "android-source/BlueVpnSmartSelector.kt"
    experience = ROOT / "android-source/BlueVpnExperience.kt"
    locations = ROOT / "android-source/BlueVpnLocationUtil.kt"
    servers = ROOT / "android-source/BlueVpnServersActivity.kt"
    home = ROOT / "android-source/BlueVpnHomeActivity.kt"
    tapsell = ROOT / "android-source/BlueVpnTapsellManager.kt"
    workflow = ROOT / ".github/workflows/build-apk.yml"

    checks = [
        (entitlement, "Looper.myLooper() == Looper.getMainLooper()", "deep entitlement has a main-thread circuit breaker"),
        (entitlement, "fun resolveUi(context: Context)", "fast UI entitlement snapshot exists"),
        (selector, "private fun scoreKnownAllowed", "selector has non-reenumerating scoring primitive"),
        (selector, "val entitlement = BlueVpnEntitlement.resolve(context)", "selector resolves entitlement once per batch"),
        (selector, "fun scoreTrusted", "catalogue scoring skips entitlement re-enumeration"),
        (experience, "BlueVpnSmartSelector.scoreTrusted", "visible health scoring uses trusted candidates"),
        (locations, "Do not invoke the\n        // full SmartSelector here", "location cards use lightweight health scoring"),
        (servers, "val uiEntitlement = BlueVpnEntitlement.resolveUi(this)", "locations render uses UI entitlement snapshot"),
        (servers, "createLocationSection(group, active, manualSelectionAllowed)", "manual entitlement is computed once per render"),
        (servers, "lifecycleScope.launch(Dispatchers.Default)", "automatic selection ranking is off main"),
        (home, "private var connectionEntitlementGuids: Set<String> = emptySet()", "connection owns a frozen entitlement GUID set"),
        (home, "statusCaption.text = \"در حال آماده‌سازی سریع Pool اتصال\"", "connection preparation is explicitly workerized"),
        (home, "lifecycleScope.launch(Dispatchers.Default)", "connection candidate preparation runs off main"),
        (home, "guid !in connectionEntitlementGuids", "failover checks frozen entitlement without rescanning subscriptions"),
        (home, "BlueVpnSmartSelector.scoreTrusted(this@BlueVpnHomeActivity, exact)", "manual server scoring avoids a second deep entitlement scan"),
        (tapsell, "BlueVpnEntitlement.resolveUi", "ad eligibility cannot enumerate MMKV on main"),
    ]
    for path, needle, label in checks:
        require(path, needle, label)

    ent = entitlement.read_text(encoding="utf-8")
    ui = section(ent, "fun resolveUi(context: Context)", "/**\n     * Reconcile")
    if "preferredServerGuids" in ui or "decodeSubscriptions" in ui or "decodeServer" in ui:
        raise AssertionError("resolveUi still touches subscription/server MMKV")

    srv = servers.read_text(encoding="utf-8")
    render = section(srv, "private fun renderLocationsNow", "private fun createLocationSection")
    if "BlueVpnEntitlement.resolve(" in render or "BlueVpnEntitlement.reconcile(" in render:
        raise AssertionError("locations render still performs deep entitlement resolution")
    card = section(srv, "private fun createLocationSection", "private fun createServerEntry")
    if "BlueVpnEntitlement." in card:
        raise AssertionError("each location card still resolves entitlement independently")
    if "instantCandidates(this, group.location.key)" in srv:
        raise AssertionError("manual location selection still rebuilds candidate catalogue on UI thread")

    home_text = home.read_text(encoding="utf-8")
    begin = section(home_text, "private fun beginSmartConnection()", "private fun startSmartConnectionWithCandidates")
    if "preferredServerGuids(this)" in begin or "BlueVpnEntitlement.reconcile(this)" in begin:
        raise AssertionError("beginSmartConnection still enumerates exact entitlement on main")
    prepared = section(home_text, "private fun startSmartConnectionWithCandidates", "private fun applyPreparedConnectionQueue")
    if "Dispatchers.Default" not in prepared or "preferredServerGuids" not in prepared:
        raise AssertionError("exact entitlement preparation was not moved into the background worker")

    loc = locations.read_text(encoding="utf-8")
    health = section(loc, "fun locationHealthScore", "data class CandidatePreflight")
    if "BlueVpnExperience.healthScore" in health or "BlueVpnSmartSelector" in health:
        raise AssertionError("locationHealthScore still executes deep scoring on main")

    cfg = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    if cfg.get("version_name") != "4.0.35" or cfg.get("version_code") != 40035:
        raise AssertionError("4.0.35 version metadata is not aligned")

    if "validate_main_thread_anr_fix.py" not in workflow.read_text(encoding="utf-8"):
        raise AssertionError("GitHub Actions does not gate main-thread ANR regressions")

    print(f"PASS: main-thread ANR isolation validation ({len(checks) + 7} checks)")


if __name__ == "__main__":
    main()
