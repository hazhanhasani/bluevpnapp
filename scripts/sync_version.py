from __future__ import annotations

import argparse
import json
import re
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
VERSION_FILE = ROOT / "version.json"


def load_contract() -> tuple[str, int]:
    data = json.loads(VERSION_FILE.read_text(encoding="utf-8"))
    version = str(data.get("version", "")).strip()
    match = re.fullmatch(r"(\d+)\.(\d+)\.(\d+)", version)
    if match is None:
        raise SystemExit(f"invalid version.json version: {version!r}")
    major, minor, patch = map(int, match.groups())
    expected_code = major * 10000 + minor * 100 + patch
    version_code = int(data.get("version_code", -1))
    if version_code != expected_code:
        raise SystemExit(
            f"version.json version_code mismatch: {version_code} != {expected_code}"
        )
    components = data.get("components") or {}
    drift = [name for name, value in components.items() if str(value) != version]
    if drift:
        raise SystemExit(f"version.json component drift: {', '.join(drift)}")
    return version, version_code


def json_file(path: str, fields: dict[str, str | int]) -> str:
    target = ROOT / path
    data = json.loads(target.read_text(encoding="utf-8"))
    for key, value in fields.items():
        data[key] = value
    return json.dumps(data, ensure_ascii=False, indent=2) + "\n"


def runtime_config(path: str, version: str) -> str:
    target = ROOT / path
    data = json.loads(target.read_text(encoding="utf-8"))
    data.pop("version", None)
    outbounds = data.get("outbounds") or []
    if not outbounds or not isinstance(outbounds[0], dict):
        raise SystemExit(f"versioned outbound missing in {path}")
    outbounds[0]["version"] = version
    return json.dumps(data, ensure_ascii=False, indent=2) + "\n"


def replace(path: str, substitutions: list[tuple[str, str]]) -> str:
    target = ROOT / path
    text = target.read_text(encoding="utf-8")
    for pattern, replacement in substitutions:
        updated, count = re.subn(pattern, replacement, text, flags=re.MULTILINE)
        if count == 0:
            raise SystemExit(f"version marker not found in {path}: {pattern}")
        text = updated
    return text


def expected_files(version: str, version_code: int) -> dict[str, str]:
    four_part = f"{version}.0"
    files = {
        "release.json": json_file(
            "release.json",
            {
                "version": version,
                "version_code": version_code,
                "build_id": f"20260824-v{version}-version-contract",
                "description": f"BlueVPN {version}: centralized version contract and release-gate synchronization.",
                "android_version": version,
                "android_version_code": version_code,
                "manager_version": version,
                "site_version": version,
                "theme_version": version,
                "windows_version": version,
                "windows_version_code": version_code,
            },
        ),
        "branding/app.json": json_file(
            "branding/app.json", {"version_name": version, "version_code": version_code}
        ),
        "bluevpn-windows/appsettings.json": json_file(
            "bluevpn-windows/appsettings.json", {"version": version}
        ),
        "bluevpn-windows/BlueVPN.Windows.csproj": replace(
            "bluevpn-windows/BlueVPN.Windows.csproj",
            [
                (r"<Version>[^<]+</Version>", f"<Version>{version}</Version>"),
                (r"<AssemblyVersion>[^<]+</AssemblyVersion>", f"<AssemblyVersion>{four_part}</AssemblyVersion>"),
                (r"<FileVersion>[^<]+</FileVersion>", f"<FileVersion>{four_part}</FileVersion>"),
                (r"<InformationalVersion>[^<]+</InformationalVersion>", f"<InformationalVersion>{version}</InformationalVersion>"),
            ],
        ),
        "bluevpn-manager/bluevpn-manager.php": replace(
            "bluevpn-manager/bluevpn-manager.php",
            [
                (r"(?m)^ \* Version: [^\r\n]+", f" * Version: {version}"),
                (r"define\('BLUEVPN_MANAGER_VERSION',\s*'[^']+'\);", f"define('BLUEVPN_MANAGER_VERSION', '{version}');"),
            ],
        ),
        "bluevpn-manager/readme.txt": replace(
            "bluevpn-manager/readme.txt",
            [
                (r"(?m)^Version: .+$", f"Version: {version}"),
                (r"(?m)^Stable tag: .+$", f"Stable tag: {version}"),
            ],
        ),
        "bluevpn-site/functions.php": replace(
            "bluevpn-site/functions.php",
            [(r"define\('BLUEVPN_SITE_VERSION',\s*'[^']+'\);", f"define('BLUEVPN_SITE_VERSION', '{version}');")],
        ),
        "bluevpn-site/style.css": replace(
            "bluevpn-site/style.css", [(r"(?m)^Version: .+$", f"Version: {version}")]
        ),
        "bluevpn-windows/installer/BlueVPN.iss": replace(
            "bluevpn-windows/installer/BlueVPN.iss",
            [(r'(?m)^\s*#define MyVersion "[^"]+"', f'  #define MyVersion "{version}"')],
        ),
        "bluevpn-windows/MainWindow.xaml": replace(
            "bluevpn-windows/MainWindow.xaml",
            [(r'(x:Name="(?:VersionText|MenuVersionText)"\s+Text=")[^"]+(?=")', rf"\g<1>{version}")],
        ),
        "bluevpn-windows/Services/ConnectivityProbe.cs": replace(
            "bluevpn-windows/Services/ConnectivityProbe.cs",
            [(r"BlueVPN-Windows-Probe/[^\"]+", f"BlueVPN-Windows-Probe/{version}")],
        ),
        "bluevpn-windows/Services/MediaAssetLoader.cs": replace(
            "bluevpn-windows/Services/MediaAssetLoader.cs",
            [(r"BlueVPN-Windows-Media/[^\"]+", f"BlueVPN-Windows-Media/{version}")],
        ),
        "bluevpn-ios/project.yml": replace(
            "bluevpn-ios/project.yml",
            [
                (r"(?m)^    MARKETING_VERSION: .+$", f"    MARKETING_VERSION: {version}"),
                (r"(?m)^    CURRENT_PROJECT_VERSION: .+$", f"    CURRENT_PROJECT_VERSION: {version_code}"),
            ],
        ),
        "bluevpn-ios/BlueVPNApp/APIClient.swift": replace(
            "bluevpn-ios/BlueVPNApp/APIClient.swift",
            [(r'BlueVPN-iOS/[^"\s]+', f"BlueVPN-iOS/{version}")],
        ),
        "bluevpn-ios/BlueVPNApp/HomeView.swift": replace(
            "bluevpn-ios/BlueVPNApp/HomeView.swift",
            [(r'Text\("\d+\.\d+\.\d+"\)', f'Text("{version}")')],
        ),
        "bluevpn-ios/BlueVPNApp/SecondaryViews.swift": replace(
            "bluevpn-ios/BlueVPNApp/SecondaryViews.swift",
            [(r'LabeledContent\("نسخه",\s*value:\s*"[^"]+"\)', f'LabeledContent("نسخه", value: "{version}")')],
        ),
        "bluevpn-gateway/agent.py": replace(
            "bluevpn-gateway/agent.py",
            [(r'(?m)^AGENT_VERSION = "[^"]+"', f'AGENT_VERSION = "{version}"')],
        ),
        "bluevpn-gateway/one-click-install.sh": replace(
            "bluevpn-gateway/one-click-install.sh",
            [(r'(?m)^AGENT_VERSION="[^"]+"', f'AGENT_VERSION="{version}"')],
        ),
        "bluevpn-manager/assets/gateway/agent.py": replace(
            "bluevpn-manager/assets/gateway/agent.py",
            [(r'(?m)^AGENT_VERSION = "[^"]+"', f'AGENT_VERSION = "{version}"')],
        ),
        "bluevpn-manager/assets/gateway/one-click-install.sh": replace(
            "bluevpn-manager/assets/gateway/one-click-install.sh",
            [(r'(?m)^AGENT_VERSION="[^"]+"', f'AGENT_VERSION="{version}"')],
        ),
        "bluevpn-windows/runtime-config/singbox-v2rayn-tun-smoke.json": runtime_config(
            "bluevpn-windows/runtime-config/singbox-v2rayn-tun-smoke.json", version
        ),
        "bluevpn-windows/runtime-config/singbox-warp-smoke.json": runtime_config(
            "bluevpn-windows/runtime-config/singbox-warp-smoke.json", version
        ),
    }
    return files


def content_matches(path: str, expected: str) -> bool:
    actual = (ROOT / path).read_text(encoding="utf-8")
    if path.endswith(".json"):
        try:
            return json.loads(actual) == json.loads(expected)
        except json.JSONDecodeError:
            return False
    return actual == expected


def main() -> None:
    parser = argparse.ArgumentParser(description="Synchronize BlueVPN release versions")
    parser.add_argument("--check", action="store_true", help="fail instead of writing drifted files")
    args = parser.parse_args()
    version, version_code = load_contract()
    expected = expected_files(version, version_code)
    drifted = [path for path, content in expected.items() if not content_matches(path, content)]
    if args.check:
        if drifted:
            raise SystemExit("version drift: " + ", ".join(drifted))
    else:
        for path in drifted:
            (ROOT / path).write_text(expected[path], encoding="utf-8")
    action = "checked" if args.check else "synchronized"
    print(f"BlueVPN version {action}: {version} / {version_code} ({len(drifted)} drifted files)")


if __name__ == "__main__":
    main()
