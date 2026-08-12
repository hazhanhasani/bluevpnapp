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


def main() -> None:
    home = ROOT / "android-source/BlueVpnHomeActivity.kt"
    account = ROOT / "android-source/BlueVpnAccountManager.kt"
    servers = ROOT / "android-source/BlueVpnServersActivity.kt"
    intel = ROOT / "android-source/BlueVpnSubscriptionIntelligence.kt"
    engine = ROOT / "android-source/BlueVpnEngineManager.kt"
    gate = ROOT / "android-source/BlueVpnRuntimeGate.kt"
    prepare = ROOT / "scripts/prepare_android.py"
    workflow = ROOT / ".github/workflows/build-apk.yml"

    checks = [
        (gate, "beginConnection", "connection/subscription mutex exists"),
        (gate, "beginSubscriptionMutation", "subscription mutation lock exists"),
        (account, "val effectiveForce = force && !BlueVpnRuntimeGate.connectionActive(c)", "connected sync cannot force provider refresh"),
        (account, "if (BlueVpnRuntimeGate.connectionActive(c)) return 0", "inactive pool pruning is blocked while connected"),
        (account, "return@runCatching preferredServerGuids(appContext).size", "entitlement repair is read-only while connected"),
        (account, "premiumLastKnownGoodServerGuids", "account-bound premium LKG exists"),
        (account, "rememberPremiumLastKnownGood", "exact premium pool is snapshotted"),
        (account, "it.isNotBlank() && it !in free", "Premium fallback excludes Free GUIDs"),
        (intel, "BlueVpnRuntimeGate.beginSubscriptionMutation(context)", "subscription importer respects runtime gate"),
        (servers, "force = force,", "locations screen honors requested sync force"),
        (servers, "BlueVpnRuntimeGate.connectionActive(this@BlueVpnServersActivity)", "locations repair is blocked while connected"),
        (home, "mainViewModel.runningServerGuid.value", "Home consumes service-reported exact GUID"),
        (home, "scoredQueue.take(5)", "automatic failover is bounded"),
        (home, "round < 2", "end-to-end verification rounds are bounded"),
        (home, "BlueVpnRuntimeGate.beginConnection(this, timeoutMs = 0L)", "connect freezes subscription pool before candidate selection"),
        (engine, "CoreServiceManager.startVServiceExact(app, targetGuid)", "engine uses exact runtime start"),
        (engine, "The CoreServiceManager\n            // singleton in the UI process cannot authoritatively answer", "engine never trusts UI-process CoreServiceManager running state"),
        (home, "mainViewModel.coreStartError.observe(this)", "daemon start failures fail over immediately"),
        (home, "BlueVpnEngineManager.markIdle()", "engine idle follows daemon state broadcast"),
        (prepare, "intent.putExtra(\"bluevpn_target_guid\", guid)", "exact GUID crosses service Intent"),
        (prepare, "MessageUtil.sendMsg2UI(service, AppConfig.MSG_STATE_START_SUCCESS, guid)", "start success identifies running GUID"),
        (prepare, "MSG_STATE_RUNNING, currentGuid.orEmpty()", "running state identifies running GUID"),
        (prepare, "@Synchronized\n    fun stopCoreLoop(): Boolean", "core stop is serialized"),
        (prepare, "coreController.stopLoop()", "runtime patch performs real core stop"),
        (prepare, "Core stop timeout", "stop timeout is explicit"),
        (prepare, "runningServerGuid by lazy", "MainViewModel tracks runtime GUID"),
        (prepare, "coreStartError by lazy", "MainViewModel exposes daemon start failure"),
        (prepare, "if (setupVpnService())", "VPN core cannot start after failed TUN setup"),
        (prepare, "val coreStopped = CoreServiceManager.stopCoreLoop()", "TUN teardown waits on core stop"),
        (prepare, "BlueVpnRuntimeGate.kt\": ROOT", "runtime gate is injected into upstream build"),
    ]
    for path, needle, label in checks:
        require(path, needle, label)

    # Opening the locations screen is a read-only account snapshot. Only the
    # explicit user refresh button may request force=true.
    text_servers = servers.read_text(encoding="utf-8")
    on_create = text_servers.split("override fun onCreate", 1)[1].split("override fun onResume", 1)[0]
    if "refreshEntitlementState(force = true)" in on_create:
        raise AssertionError("opening Locations still performs forced account/provider sync")

    # AI must not fire the old direct forced /account/sync repair path.
    text_home = home.read_text(encoding="utf-8")
    ai_section = text_home.split("private fun runSmartSelection()", 1)[1].split("private fun", 1)[0]
    if "BlueVpnAccountManager.sync(" in ai_section and "force = true" in ai_section:
        raise AssertionError("BlueAI still triggers direct forced account sync")


    engine_text = engine.read_text(encoding="utf-8")
    if "while (\n                CoreServiceManager.isRunning()" in engine_text:
        raise AssertionError("EngineManager still polls the UI-process CoreServiceManager singleton")

    config = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    if config.get("version_name") != "4.0.33" or config.get("version_code") != 40033:
        raise AssertionError("4.0.33 version metadata is not aligned")

    if "validate_exact_runtime_lifecycle.py" not in workflow.read_text(encoding="utf-8"):
        raise AssertionError("GitHub Actions does not gate exact runtime lifecycle regressions")

    print(f"PASS: exact runtime lifecycle validation ({len(checks) + 5} checks)")


if __name__ == "__main__":
    main()
