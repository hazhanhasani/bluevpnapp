from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def read(path: str) -> str:
    p = ROOT / path
    if not p.is_file():
        raise AssertionError(f"missing Windows client file: {path}")
    return p.read_text(encoding="utf-8")

def require(value: bool, message: str) -> None:
    if not value:
        raise AssertionError(message)

def main() -> None:
    release = json.loads(read("release.json"))
    branding = json.loads(read("branding/app.json"))
    settings = json.loads(read("bluevpn-windows/appsettings.json"))
    project = read("bluevpn-windows/BlueVPN.Windows.csproj")
    manifest = read("bluevpn-windows/app.manifest")
    parser = read("bluevpn-windows/Services/SubscriptionParser.cs")
    xray = read("bluevpn-windows/Services/XrayConfigBuilder.cs")
    v2rayn_tun = read("bluevpn-windows/Services/V2RayNTunConfigBuilder.cs")
    connection = read("bluevpn-windows/Services/ConnectionOrchestrator.cs")
    workflow = read(".github/workflows/build-windows.yml")
    probe = read("bluevpn-windows/Services/ConnectivityProbe.cs")
    verifier = read("bluevpn-windows/Services/SystemTunnelVerifier.cs")
    warp = read("bluevpn-windows/Services/WarpConnectionController.cs")
    warp_config = read("bluevpn-windows/Services/SingBoxWarpConfigBuilder.cs")
    runtime = read("bluevpn-windows/Services/RuntimeLocator.cs")
    runtime_update = read("bluevpn-windows/Services/RuntimeUpdateService.cs")
    app_update = read("bluevpn-windows/Services/AppUpdateService.cs")
    ads = read("bluevpn-windows/Services/AdvertisementService.cs")
    main = read("bluevpn-windows/MainWindow.xaml")
    main_cs = read("bluevpn-windows/MainWindow.xaml.cs")
    installer = read("bluevpn-windows/installer/BlueVPN.iss")

    version = str(release.get("version", "")).strip()
    require(re.fullmatch(r"\d+\.\d+\.\d+", version) is not None, "invalid release version")
    require(version == "4.17.8", "this Windows migration must be release 4.17.8")
    require(str(branding.get("version_name", "")) == version, "branding version drift")
    require(str(release.get("windows_version", "")) == version, "release windows_version mismatch")
    require(str(settings.get("version", "")) == version, "Windows appsettings version mismatch")
    require(f"<Version>{version}</Version>" in project, "Windows csproj Version mismatch")

    require("net10.0-windows" in project and "<UseWPF>true</UseWPF>" in project, "Windows must target .NET 10 WPF")
    require('level="requireAdministrator"' in manifest, "Windows TUN client must request admin")

    # v2rayN baseline and verified packaging.
    require(settings.get("v2rayn_version") == "7.24.4", "v2rayN stable baseline mismatch")
    require("V2RAYN_VERSION: '7.24.4'" in workflow, "workflow v2rayN pin mismatch")
    require("v2rayN-windows-64.zip" in workflow and "v2rayN-windows-arm64.zip" in workflow, "v2rayN architecture packages missing")
    require("2dust/v2rayN" in workflow and "asset.digest" in workflow and "Get-FileHash" in workflow, "v2rayN SHA256 gate missing")
    require("xray.exe" in runtime and "sing-box.exe" in runtime and "wintun.dll" in runtime, "runtime resolver missing v2rayN cores")
    require("Get-PeMachine" in workflow and "0xAA64" in workflow and "0x8664" in workflow, "v2rayN runtime PE architecture gate missing")
    require("xray-local-proxy-smoke.json" in workflow and "singbox-v2rayn-tun-smoke.json" in workflow and "singbox-warp-smoke.json" in workflow and "run -test -config" in workflow and "check -c" in workflow, "runtime TUN config smoke checks missing")
    xray_smoke = json.loads(read("bluevpn-windows/runtime-config/xray-local-proxy-smoke.json"))
    v2rayn_smoke = json.loads(read("bluevpn-windows/runtime-config/singbox-v2rayn-tun-smoke.json"))
    sing_smoke = json.loads(read("bluevpn-windows/runtime-config/singbox-warp-smoke.json"))
    require((xray_smoke.get("inbounds") or [{}])[0].get("protocol") == "socks", "Xray localhost proxy smoke config invalid")
    require((v2rayn_smoke.get("inbounds") or [{}])[0].get("type") == "tun", "v2rayN sing-box TUN smoke config invalid")
    require((sing_smoke.get("inbounds") or [{}])[0].get("type") == "tun", "sing-box WARP TUN smoke config invalid")
    require("third_party/V2RAYN.md" in workflow, "v2rayN license notice not packaged")

    # System-wide connection truth, not process truth.
    require("_verifiedConnected" in connection and "public bool IsConnected => _verifiedConnected" in connection, "CONNECTED must be verified state")
    require("SystemTunnelVerifier.VerifyAsync" in connection, "system route verification missing")
    require("PublicIp" in verifier and "IP سیستم تغییر نکرد" in verifier, "public-IP change gate missing")
    require("Get-NetRoute" in verifier and "NetworkInterface.GetAllNetworkInterfaces" in verifier, "Windows route/adapter evidence missing")
    require("SnapshotAsync" in probe and "CaptureBaselineAsync" in probe and "UseProxy = proxy is not null" in probe, "direct system-stack connectivity snapshot / baseline gate missing")
    require('protocol"] = "socks"' in xray and "LocalSocksPort = 20808" in xray, "premium Xray local proxy missing")
    require('type = "tun"' in v2rayn_tun and "strict_route = true" in v2rayn_tun and 'process_name = new[] { "xray.exe" }' in v2rayn_tun, "premium v2rayN split-core TUN missing")

    # WARP path.
    require(settings.get("warp", {}).get("enabled") is True, "Windows WARP must be enabled")
    require("AETHER_VERSION: 'v1.1.1'" in workflow and "aether-windows-x86_64.zip" in workflow, "official Aether x64 runtime missing")
    require("ARM64-FALLBACK.txt" in workflow and "curated Xray free-pool fallback" in read("third_party/AETHER_WINDOWS.md"), "ARM64 WARP fallback not explicit")
    for flag in ("--masque", "--scan", "turbo", "--noize", "firewall", "--quick-reconnect"):
        require(flag in warp, f"Aether flag missing: {flag}")
    require('process_name = new[] { "aether.exe" }' in warp_config, "Aether process loop exclusion missing")
    require("auto_detect_interface = true" in warp_config and "strict_route = true" in warp_config and "auto_route = true" in warp_config, "sing-box WARP TUN hardening missing")
    require("VerifyAsync(before, _settings.ProbeUrl, true, blocked, ct)" in connection and "BlockedExitCountries" in connection and "RejectIrExit" in connection, "WARP validation / exit guard missing")
    require("_settings.Warp.SocksPort" in warp and "SingBoxWarpConfigBuilder.Build(_settings, socksPort)" in warp, "WARP SOCKS port policy drift")

    # Updates + installer.
    require("AppUpdateService" in main_cs and "RuntimeUpdateService" in main_cs, "Windows update services not wired")
    require("GetWindowsUpdateAsync" in app_update and "VERYSILENT" in app_update, "self-updater must consume panel-selected installer")
    require("v2rayN-windows-arm64.zip" in runtime_update and "v2rayN-windows-64.zip" in runtime_update, "v2rayN runtime updater arch selection missing")
    require(".validated" in runtime_update and "DownloadVerifiedAsync" in app_update and "DownloadVerifiedAsync" in runtime_update, "runtime/app update integrity gate missing")
    require("/wp-json/bluevpn/v1/windows/update" in read("bluevpn-windows/appsettings.json"), "Windows control-plane update endpoint missing")
    require("panel-managed" in read("bluevpn-windows/appsettings.json"), "Windows update channel must be panel-managed")
    github_client = read("bluevpn-windows/Services/GitHubReleaseClient.cs")
    require('digest.StartsWith("sha256:"' in github_client and "SHA256 معتبر ارائه نکرد" in github_client, "secure updater must fail closed without GitHub SHA256")
    require("TimeSpan.FromHours(4)" in main_cs and "MaintenanceTimer_Tick" in main_cs, "periodic Windows auto-update check missing")
    require("[Setup]" in installer and "DefaultDirName={autopf}\\BlueVPN" in installer and "PrivilegesRequired=admin" in installer, "real Windows installer contract missing")
    require("ISCC" in workflow and "BlueVPN-Setup-${VERSION}-win-*.exe" in workflow, "installer build/release workflow missing")
    require("BlueVPN-Setup-${VERSION}-win-x64.exe" in workflow and "BlueVPN-Setup-${VERSION}-win-arm64.exe" in workflow, "both installers must be released")

    # Android-parity UI + first-party ads.
    for token in ("StatusOrb", "EndpointText", "IpValue", "PingValue", "DurationValue", "SpeedValue", "AdCard"):
        require(token in main, f"Windows Android-parity UI missing {token}")
    require("GetMobileConfigAsync" in read("bluevpn-windows/Services/BlueVpnApiClient.cs"), "Windows must consume mobile/config")
    require("advertising" in read("bluevpn-windows/Models/WindowsRuntimeModels.cs") and "free_story_ads" in read("bluevpn-windows/Models/WindowsRuntimeModels.cs"), "ad payload models missing")
    require("ShowFreeStoryAdSafe" in main_cs and "window.Show()" in main_cs, "free story ad must be fail-open/non-blocking")
    require("Tapsell" not in ads, "Windows ads must use first-party control plane, not Android Tapsell SDK")

    require("dotnet build bluevpn-windows/BlueVPN.Windows.csproj" in workflow, "real compile gate missing")
    require("dotnet publish bluevpn-windows/BlueVPN.Windows.csproj" in workflow, "real publish gate missing")
    require("publish-windows-release" in workflow and "bluevpn-windows-v${VERSION}" in workflow, "Windows website release missing")
    require("api.telegram.org" not in workflow and "send_windows_telegram" not in workflow, "Windows binary delivery must not use Telegram transport")
    require("TELEGRAM_BOT_TOKEN: ${{ secrets.TELEGRAM_BOT_TOKEN }}" in workflow, "signed WordPress release metadata push secret missing")

    for scheme in ("vless://", "vmess://", "trojan://", "ss://"):
        require(scheme in parser, f"subscription parser missing {scheme}")
    require("GetPremiumSubscriptionAsync" in connection and "GetFreeSubscriptionAsync" in connection, "Free/Premium isolation missing")
    require("EndpointSelector.RankAsync" in connection, "endpoint ranking missing")

    # 4.17.8 Windows stability gates: Android UI parity, non-blocking media/metrics,
    # panel-driven WARP, and fail-closed updater/connection state.
    media = read("bluevpn-windows/Services/MediaAssetLoader.cs")
    models = read("bluevpn-windows/Models/WindowsRuntimeModels.cs")
    require("PeriodicTimer(TimeSpan.FromSeconds(1))" in main_cs and "Task.Run(NetworkBytes" in main_cs and "_metricsTimer" not in main_cs, "Windows telemetry must not block the WPF dispatcher")
    require("MediaAssetLoader.LoadImageAsync" in main_cs and "Task.Run<BitmapSource?>" in media and "bmp.Freeze()" in media, "ad images must load/decode off the dispatcher")
    require("ResolveUrl" in ads and "_settings.ApiBaseUrl.TrimEnd" in ads, "relative ad assets must resolve against BlueVPN API base")
    require("free_access" in models and "blocked_exit_countries" in models and "LoadMobilePolicySafeAsync" in connection, "Windows WARP must consume panel free_access policy")
    require("CaptureBaselineAsync" in connection and "IP اینترنت قبل از اتصال قابل تأیید نیست" in connection, "false CONNECTED baseline guard missing")
    require("ip_cidr = ipCidrs" in v2rayn_tun and "ResolveEndpointIpsAsync" in read("bluevpn-windows/Services/XrayProcessController.cs"), "endpoint-aware TUN loop guard missing")
    require("if (!candidate.AutoUpdate)" in main_cs and "if (userInitiated)" in main_cs and "_pendingUpdate = candidate" in main_cs, "Windows update channel semantics / deferred install missing")

    # 4.17.8 CI/release hardening: installers must be root-level artifacts and
    # Node.js 20-generation cache/artifact actions must not remain.
    require('dist/BlueVPN-Setup-*.exe' in workflow, "Windows Setup must upload from dist root")
    require('Normalize Windows release payload layout' in workflow, "Windows publish job must normalize artifact layout")
    require('actions/upload-artifact@v7' in workflow, "Windows upload-artifact must use v7")
    require('actions/download-artifact@v8' in workflow, "Windows download-artifact must use v8")

    print(f"BlueVPN Windows validation PASS — {version} / v2rayN + WARP + Installer")

if __name__ == "__main__":
    main()

