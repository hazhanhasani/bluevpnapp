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
    connection = read("bluevpn-windows/Services/ConnectionOrchestrator.cs")
    workflow = read(".github/workflows/build-windows.yml")
    api_client = read("bluevpn-windows/Services/BlueVpnApiClient.cs")
    connectivity_probe = read("bluevpn-windows/Services/ConnectivityProbe.cs")
    telegram_delivery = read("scripts/send_windows_telegram.ps1")

    version = str(release.get("version", "")).strip()
    require(re.fullmatch(r"\d+\.\d+\.\d+", version) is not None, "invalid release version")
    require(str(branding.get("version_name", "")) == version, "branding/app.json Windows version drift")
    require(str(release.get("windows_version", "")) == version, "release windows_version mismatch")
    require(str(settings.get("version", "")) == version, "Windows appsettings version mismatch")
    require(f"<Version>{version}</Version>" in project, "Windows csproj Version mismatch")
    require(f"<InformationalVersion>{version}</InformationalVersion>" in project, "Windows InformationalVersion mismatch")

    require("net10.0-windows" in project and "<UseWPF>true</UseWPF>" in project,
            "Windows client must target .NET 10 WPF")
    require('level="requireAdministrator"' in manifest,
            "Windows TUN client must request administrator privileges")
    require("protocol\"] = \"tun\"" in xray, "Xray Windows TUN inbound missing")
    require("autoSystemRoutingTable" in xray, "Windows automatic TUN routes missing")
    require("autoOutboundsInterface" in xray, "Windows Xray loop protection missing")
    require("wintun.dll" in workflow and "Xray-windows-64.zip" in workflow,
            "GitHub workflow does not bundle official Windows Xray/Wintun")
    require("v26.7.28" in workflow and str(settings.get("xray_version")) == "v26.7.28",
            "Windows Xray pin mismatch")

    require("using System.Net.Http;" in api_client,
            "BlueVpnApiClient must explicitly import System.Net.Http")
    require("using System.Net.Http;" in connectivity_probe,
            "ConnectivityProbe must explicitly import System.Net.Http")
    require("dotnet build bluevpn-windows/BlueVPN.Windows.csproj" in workflow,
            "Windows workflow must perform a real compile gate before packaging")
    require("dotnet publish bluevpn-windows/BlueVPN.Windows.csproj" in workflow,
            "Windows workflow must perform a real publish")
    require("TELEGRAM_BOT_TOKEN" in workflow and "TELEGRAM_CHAT_ID" in workflow,
            "Windows workflow must use the existing Telegram release secrets")
    require("send_windows_telegram.ps1" in workflow,
            "Windows workflow is missing Telegram package delivery")
    require("sendDocument" in telegram_delivery and "TelegramDirectLimitBytes" in telegram_delivery,
            "Windows Telegram delivery helper is incomplete")
    require("SplitPartSizeBytes" in telegram_delivery and "JOIN-BLUEVPN-PARTS.cmd" in telegram_delivery,
            "Windows Telegram large-file split fallback is missing")

    for scheme in ("vless://", "vmess://", "trojan://", "ss://"):
        require(scheme in parser, f"Windows subscription parser missing {scheme}")
    require("GetPremiumSubscriptionAsync" in connection and "GetFreeSubscriptionAsync" in connection,
            "Windows Free/Premium subscription isolation missing")
    require("ConnectivityProbe.VerifyAsync" in connection,
            "Windows must verify tunnel before reporting connected")
    require("EndpointSelector.RankAsync" in connection,
            "Windows bounded endpoint race missing")

    print(f"BlueVPN Windows validation PASS — {version}")


if __name__ == "__main__":
    main()
