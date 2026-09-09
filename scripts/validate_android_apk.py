#!/usr/bin/env python3
import argparse
import hashlib
import json
import os
import shutil
import subprocess
import sys
import xml.etree.ElementTree as ET
import zipfile
from pathlib import Path

COMMON_REQUIRED_APK_ENTRIES = {
    "AndroidManifest.xml",
    "classes.dex",
    "resources.arsc",
}

SUPPORTED_AETHER_ABIS = {
    "arm64-v8a",
    "armeabi-v7a",
}

REQUIRED_PERMISSIONS = {
    "android.permission.POST_NOTIFICATIONS",
    "android.permission.FOREGROUND_SERVICE",
}

REQUIRED_SERVICES = {
    "com.v2ray.ang.bluevpn.BlueVpnWarpKeepAliveService",
    "com.v2ray.ang.bluevpn.BlueVpnQuickTileService",
}

REQUIRED_RECEIVERS = {
    "com.v2ray.ang.bluevpn.BlueVpnSystemActionReceiver",
}

ANDROID_NS = "{http://schemas.android.com/apk/res/android}"
ROOT = Path(__file__).resolve().parents[1]


def load_release_contract() -> dict:
    branding = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    expected = {
        "version_name": str(branding.get("version_name", "")).strip(),
        "version_code": int(branding.get("version_code", -1)),
        "application_id": str(branding.get("application_id", "")).strip(),
    }
    if expected["version_name"] != str(release.get("android_version", release.get("version", ""))).strip():
        raise ValueError("Android version contract drift between branding/app.json and release.json")
    if expected["version_code"] != int(release.get("android_version_code", release.get("version_code", -1))):
        raise ValueError("Android versionCode contract drift between branding/app.json and release.json")
    if not expected["version_name"] or expected["version_code"] <= 0 or not expected["application_id"]:
        raise ValueError("Android release contract is incomplete")
    return expected


def find_apkanalyzer() -> str:
    direct = shutil.which("apkanalyzer")
    if direct:
        return direct
    for env_name in ("ANDROID_HOME", "ANDROID_SDK_ROOT"):
        root = os.environ.get(env_name, "").strip()
        if not root:
            continue
        for rel in (
            "cmdline-tools/latest/bin/apkanalyzer",
            "cmdline-tools/bin/apkanalyzer",
            "tools/bin/apkanalyzer",
        ):
            candidate = Path(root) / rel
            if candidate.is_file():
                return str(candidate)
    raise ValueError("apkanalyzer is required to verify the signed APK version metadata")


def apkanalyzer_value(exe: str, apk: Path, field: str) -> str:
    proc = subprocess.run(
        [exe, "manifest", field, str(apk)],
        check=False,
        text=True,
        capture_output=True,
    )
    if proc.returncode != 0:
        detail = (proc.stderr or proc.stdout or "unknown apkanalyzer failure").strip()
        raise ValueError(f"apkanalyzer {field} failed for {apk.name}: {detail}")
    value = proc.stdout.strip().splitlines()
    if not value:
        raise ValueError(f"apkanalyzer returned no {field} for {apk.name}")
    return value[-1].strip()


def validate_embedded_version(path: Path, contract: dict, apkanalyzer: str) -> dict:
    actual_name = apkanalyzer_value(apkanalyzer, path, "version-name")
    actual_code_raw = apkanalyzer_value(apkanalyzer, path, "version-code")
    actual_app_id = apkanalyzer_value(apkanalyzer, path, "application-id")
    try:
        actual_code = int(actual_code_raw)
    except ValueError as exc:
        raise ValueError(f"invalid embedded Android versionCode in {path.name}: {actual_code_raw!r}") from exc

    errors = []
    if actual_name != contract["version_name"]:
        errors.append(f"versionName {actual_name!r} != {contract['version_name']!r}")
    if actual_code != contract["version_code"]:
        errors.append(f"versionCode {actual_code} != {contract['version_code']}")
    if actual_app_id != contract["application_id"]:
        errors.append(f"applicationId {actual_app_id!r} != {contract['application_id']!r}")
    if errors:
        raise ValueError(f"signed APK release identity mismatch for {path.name}: " + "; ".join(errors))

    return {
        "version_name": actual_name,
        "version_code": actual_code,
        "application_id": actual_app_id,
        "release_identity": "PASS",
    }


def aether_abis_in_apk(names: set[str]) -> set[str]:
    found = set()
    for abi in SUPPORTED_AETHER_ABIS:
        if f"lib/{abi}/libbluevpn_aether.so" in names:
            found.add(abi)
    return found


def validate_apk(path: Path, contract: dict | None = None, apkanalyzer: str | None = None) -> dict:
    if not path.is_file() or path.stat().st_size < 100_000:
        raise ValueError(f"APK missing or implausibly small: {path}")

    with zipfile.ZipFile(path) as zf:
        bad = zf.testzip()
        if bad:
            raise ValueError(f"Corrupt APK entry: {bad}")

        names = set(zf.namelist())
        missing = sorted(COMMON_REQUIRED_APK_ENTRIES - names)
        if missing:
            raise ValueError(f"APK runtime contract missing entries: {missing}")

        dex = sorted(
            name for name in names
            if name.startswith("classes") and name.endswith(".dex")
        )
        if not dex:
            raise ValueError("No DEX payload found")

        found_abis = aether_abis_in_apk(names)
        if not found_abis:
            raise ValueError(
                f"APK contains no supported BlueVPN Aether runtime: {path.name}"
            )

        aether_sizes = {}
        for abi in sorted(found_abis):
            entry = f"lib/{abi}/libbluevpn_aether.so"
            info = zf.getinfo(entry)
            if info.file_size < 100_000:
                raise ValueError(
                    f"Aether binary too small for {abi}: {info.file_size}"
                )
            aether_sizes[abi] = info.file_size

    report = {
        "apk": path.name,
        "size": path.stat().st_size,
        "sha256": hashlib.sha256(path.read_bytes()).hexdigest(),
        "aether_abis": sorted(found_abis),
        "aether_sizes": aether_sizes,
        "required_entries": "PASS",
        "zip_integrity": "PASS",
    }
    if contract is not None:
        if not apkanalyzer:
            raise ValueError("apkanalyzer path missing for Android release identity verification")
        report.update(validate_embedded_version(path, contract, apkanalyzer))
    return report


def validate_apk_set(paths: list[Path], contract: dict | None = None, apkanalyzer: str | None = None) -> list[dict]:
    if not paths:
        raise ValueError("No APKs supplied")

    reports = [validate_apk(path, contract, apkanalyzer) for path in paths]

    covered = set()
    for report in reports:
        covered.update(report["aether_abis"])

    missing_coverage = sorted(SUPPORTED_AETHER_ABIS - covered)
    if missing_coverage:
        raise ValueError(
            "Signed APK set does not cover all required Aether ABIs: "
            f"{missing_coverage}; covered={sorted(covered)}"
        )

    return reports


def validate_manifest_xml(path: Path) -> dict:
    if not path.is_file() or path.stat().st_size <= 0:
        raise ValueError(f"Decoded manifest is missing: {path}")

    root = ET.parse(path).getroot()
    if root.tag != "manifest":
        raise ValueError(f"Unexpected manifest root: {root.tag}")

    permissions = {
        el.attrib.get(ANDROID_NS + "name", "")
        for el in root.findall("uses-permission")
    }
    missing_permissions = sorted(REQUIRED_PERMISSIONS - permissions)
    if missing_permissions:
        raise ValueError(
            f"Signed APK manifest missing permissions: {missing_permissions}"
        )

    app = root.find("application")
    if app is None:
        raise ValueError("Signed APK manifest has no application node")

    services = {
        el.attrib.get(ANDROID_NS + "name", "")
        for el in app.findall("service")
    }
    receivers = {
        el.attrib.get(ANDROID_NS + "name", "")
        for el in app.findall("receiver")
    }

    missing_services = sorted(REQUIRED_SERVICES - services)
    missing_receivers = sorted(REQUIRED_RECEIVERS - receivers)
    if missing_services:
        raise ValueError(
            f"Signed APK manifest missing services: {missing_services}"
        )
    if missing_receivers:
        raise ValueError(
            f"Signed APK manifest missing receivers: {missing_receivers}"
        )

    return {
        "manifest": path.name,
        "permissions": "PASS",
        "services": "PASS",
        "receivers": "PASS",
    }


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("apks", nargs="*")
    parser.add_argument(
        "--manifest-xml",
        action="append",
        default=[],
        help="Decoded AndroidManifest.xml generated with apkanalyzer manifest print",
    )
    parser.add_argument(
        "--skip-release-identity",
        action="store_true",
        help="Skip signed APK version/application-id verification (diagnostic use only)",
    )
    args = parser.parse_args()

    if not args.apks and not args.manifest_xml:
        parser.error("provide at least one APK or --manifest-xml")

    report = {
        "schema": 3,
        "apks": [],
        "manifests": [],
        "aggregate_aether_coverage": [],
    }

    try:
        apk_paths = [Path(raw) for raw in args.apks]
        if apk_paths:
            contract = None
            analyzer = None
            if not args.skip_release_identity:
                contract = load_release_contract()
                analyzer = find_apkanalyzer()
                report["expected_release_identity"] = contract
            report["apks"] = validate_apk_set(apk_paths, contract, analyzer)
            covered = set()
            for item in report["apks"]:
                covered.update(item["aether_abis"])
            report["aggregate_aether_coverage"] = sorted(covered)

        for raw in args.manifest_xml:
            report["manifests"].append(validate_manifest_xml(Path(raw)))
    except (ValueError, OSError, ET.ParseError, zipfile.BadZipFile) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        raise SystemExit(1)

    out = Path("reports/android-apk-validation.json")
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(report, indent=2) + "\n")
    print(json.dumps(report, indent=2))


if __name__ == "__main__":
    main()
