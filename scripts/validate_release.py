from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(rel: str) -> str:
    p = ROOT / rel
    if not p.exists():
        raise AssertionError(f"missing required file: {rel}")
    return p.read_text(encoding="utf-8")


def require(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def between(text: str, start: str, end: str) -> str:
    i = text.find(start)
    require(i >= 0, f"missing block start: {start}")
    j = text.find(end, i + len(start))
    require(j >= 0, f"missing block end after: {start}")
    return text[i:j]


def main() -> None:
    app = json.loads(read("branding/app.json"))
    release = json.loads(read("release.json"))
    home = read("android-source/BlueVpnHomeActivity.kt")
    account = read("android-source/BlueVpnAccountManager.kt")
    location = read("android-source/BlueVpnLocationUtil.kt")
    subscription = read("android-source/BlueVpnSubscriptionIntelligence.kt")
    prepare = read("scripts/prepare_android.py")
    workflow = read(".github/workflows/build-apk.yml")
    profile = read("android-source/BlueVpnProfileManager.kt")
    notice = read("NOTICE.md")
    readme = read("README.md")

    require(app["version_name"] == "4.1.8", "app version must be 4.1.8")
    require(app["version_code"] == 40108, "app versionCode must be 40108")
    require(release["version"] == "4.1.8", "release version mismatch")
    require(release["version_code"] == 40108, "release versionCode mismatch")
    require(app["upstream_ref"] == "2.2.6", "production v2rayNG pin must be 2.2.6")
    require(app["xray_ref"] == "v26.6.27", "Xray pairing must be v26.6.27")
    require("sing_box_ref" not in app, "sing-box pin must be removed")

    # BlueVPN is a UI/control-plane mounted directly on stock v2rayNG runtime.
    require("import com.v2ray.ang.core.CoreServiceManager" in home, "Home must import upstream CoreServiceManager")
    require("CoreServiceManager.startVService(this, guid)" in home, "Home must start exact GUID through upstream v2rayNG")
    require("CoreServiceManager.stopVService" in home, "Home must stop through upstream v2rayNG")
    require("BlueVpnEngineManager" not in home + account, "legacy engine abstraction remains")
    require("coreStartError" not in home, "Home still depends on patched MainViewModel diagnostics")
    require("CoreServiceManager.stopVService(appContext)" in account, "Account must stop stock v2rayNG directly")

    # Match stock MainActivity ordering: receiver/assets are ready before delayed work/ads.
    listen_at = home.find("mainViewModel.startListenBroadcast()")
    assets_at = home.find("mainViewModel.initAssets(assets)")
    pipeline_at = home.find("scheduleStartupPipeline()")
    ads_at = home.find("BlueVpnTapsellManager.warmUp(this)")
    require(min(listen_at, assets_at, pipeline_at, ads_at) >= 0, "startup parity anchors missing")
    require(listen_at < pipeline_at and assets_at < pipeline_at, "v2rayNG receiver/assets are still delayed behind startup pipeline")
    require(listen_at < ads_at and assets_at < ads_at, "ads run before v2rayNG critical startup")

    # Observe real upstream START_FAILURE without changing upstream MainViewModel/service.
    require("AppConfig.MSG_STATE_START_FAILURE" in home, "stock START_FAILURE is not observed")
    require('intent.getStringExtra("content")' in home, "upstream START_FAILURE reason is not preserved")
    require("failCurrentAndTryNext(reason)" in home, "upstream START_FAILURE does not advance hidden-route failover")

    # BlueVPN must not authoritatively pre-validate imported profiles before stock runtime.
    start_block = between(home, "private fun startCurrentCandidate", "private fun startExactCandidateCore")
    exact_block = between(home, "private fun startExactCandidateCore", "private fun scheduleConnectionVerification")
    require("preflightCandidate(" not in start_block, "authoritative DNS/TCP preflight still blocks connect")
    require("validateExactConfig" not in home, "custom config hydration gate still exists")
    require("MmkvManager.setSelectServer(guid)" not in exact_block, "BlueVPN duplicates upstream selected-GUID handoff")
    require("handler.postDelayed(attemptTimeout, 30_000L)" in exact_block, "bounded upstream start safety timeout missing")

    usable_block = between(location, "fun isUsable(", "fun invalidateCache()")
    require("return true" in usable_block, "BlueVPN still rejects decoded profiles before CoreConfigManager")
    require("server.isBlank" not in usable_block and "127.0.0.1" not in usable_block, "BlueVPN endpoint heuristics still reject profiles")
    require("BlueVpnProfileManager.fingerprint" not in location, "candidate catalogue still performs semantic dedupe")

    # Subscription import must be the stock v2rayNG parser/fetch path and UA semantics.
    require("AngConfigManager.updateConfigViaSub(row)" in subscription, "stock subscription update path missing")
    for token in ("Clash.Meta", "compatibilityUserAgents", 'BlueVPN/${BuildConfig.VERSION_NAME}'):
        require(token not in subscription, f"subscription UA/parser compatibility shim remains: {token}")
    require("userAgent = null" in account, "managed subscriptions do not preserve stock v2rayNG UA behavior")

    # No alternate production core/runtime.
    for rel in (
        "android-source/BlueVpnEngineManager.kt",
        "android-source/BlueVpnSingBoxProcess.kt",
        "android-source/BlueVpnSingBoxProfileCompiler.kt",
    ):
        require(not (ROOT / rel).exists(), f"legacy dual-engine file still present: {rel}")
    require("sing-box" not in workflow.lower(), "workflow still builds sing-box")
    require("SING_BOX" not in profile and "SING_BOX_JSON" not in profile, "profile catalogue still routes to sing-box")

    # Upstream source is checked out first and its exact core submodule pairing is never moved.
    require("repository: 2dust/v2rayNG" in workflow, "official v2rayNG checkout missing")
    require("ref: ${{ steps.config.outputs.upstream_ref }}" in workflow, "v2rayNG pin is not used by checkout")
    require("Overlay BlueVPN UI and control plane on v2rayNG" in workflow, "overlay stage missing")
    require("repository: SagerNet/sing-box" not in workflow, "sing-box checkout still exists")
    require("actions/setup-go" not in workflow, "Go/sing-box setup still exists")
    require('git checkout --force "$XRAY_REF"' not in workflow, "CI still moves AndroidLibXrayLite away from the v2rayNG submodule commit")
    require('CURRENT_COMMIT="$(git rev-parse HEAD)"' in workflow, "CI does not read v2rayNG-pinned core submodule commit")
    require('PINNED_COMMIT="$(git rev-list -n 1 "$XRAY_REF"' in workflow, "CI does not verify configured Xray tag against the submodule commit")
    require('if [ "$CURRENT_COMMIT" != "$PINNED_COMMIT" ]; then' in workflow, "CI does not fail on Xray pairing mismatch")

    # Build-time immutable boundary around v2rayNG runtime/parser files.
    require("patch_v2rayng_runtime_lifecycle" not in prepare, "core lifecycle patch remains")
    require("patch_shadowsocks_transport_queries" not in prepare, "protocol parser patch remains")
    require("UPSTREAM_RUNTIME_GUARD" in prepare, "upstream runtime guard missing")
    for protected in (
        "core/CoreServiceManager.kt",
        "core/CoreConfigManager.kt",
        "service/CoreVpnService.kt",
        "viewmodel/MainViewModel.kt",
        "handler/AngConfigManager.kt",
    ):
        require(protected in prepare, f"protected upstream runtime file missing from hash guard: {protected}")
    require("snapshot_upstream_runtime()" in prepare, "upstream runtime snapshot is not taken")
    require("assert_upstream_runtime_unchanged(runtime_snapshot)" in prepare, "upstream runtime hash is not checked after overlay")
    override_block = between(prepare, "plain_overrides = {", "    for target, source in plain_overrides.items()")
    for protected_name in ("CoreServiceManager.kt", "CoreConfigManager.kt", "CoreVpnService.kt", "MainViewModel.kt", "AngConfigManager.kt"):
        require(protected_name not in override_block, f"BlueVPN overlay rewrites protected upstream file: {protected_name}")

    # Hidden AI may score internally, but no dead AI control path may reconnect the VPN.
    require("private fun runSmartSelection()" not in home, "dead AI connection controller still exists")
    require("private fun monitorBlueAiHealth()" not in home, "dead AI health controller still exists")

    # Product requirements retained without owning protocol runtime.
    require("MANUAL_LOCATION" in home, "location-only selection flow missing")
    require("failoverReserveQueue" in home, "AUTO mode can still discard lower-ranked imported GUIDs")
    require("hidden" in readme.lower() and "location" in readme.lower(), "hidden-route architecture not documented")
    require("Free/Premium" in readme or "Free/Premium" in notice, "entitlement isolation documentation missing")
    require("bluevpn-manager" in workflow, "WordPress release integration missing")
    settings_writes = re.findall(r"MmkvManager\.encodeSettings\(", home)
    require(len(settings_writes) == 1 and 'AppConfig.PREF_MODE, "VPN"' in home, "BlueVPN still force-overrides v2rayNG runtime settings")

    # Plugin version stays synchronized.
    plugin = read("bluevpn-manager/bluevpn-manager.php")
    plugin_readme = read("bluevpn-manager/readme.txt")
    require(re.search(r"Version:\s*4\.1\.8", plugin) is not None, "plugin header version mismatch")
    require("BLUEVPN_MANAGER_VERSION" in plugin and "4.1.8" in plugin, "plugin constant version mismatch")
    require(re.search(r"Stable tag:\s*4\.1\.8", plugin_readme) is not None, "plugin stable tag mismatch")

    # Versioning contract: patch series is 0..10.
    _, _, patch = map(int, app["version_name"].split("."))
    require(0 <= patch <= 10, "patch version exceeded BlueVPN short series")

    print("BlueVPN 4.1.8 validation: PASS")
    print("runtime=v2rayNG-2.2.6 xray=v26.6.27 sing-box=removed")
    print("architecture=BlueVPN UI/control-plane -> immutable stock v2rayNG runtime")


if __name__ == "__main__":
    main()
