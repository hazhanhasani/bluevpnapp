#!/usr/bin/env python3
import argparse
import hashlib
import json
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


def aether_abis_in_apk(names: set[str]) -> set[str]:
    found = set()
    for abi in SUPPORTED_AETHER_ABIS:
        if f"lib/{abi}/libbluevpn_aether.so" in names:
            found.add(abi)
    return found


def validate_apk(path: Path) -> dict:
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

    return {
        "apk": path.name,
        "size": path.stat().st_size,
        "sha256": hashlib.sha256(path.read_bytes()).hexdigest(),
        "aether_abis": sorted(found_abis),
        "aether_sizes": aether_sizes,
        "required_entries": "PASS",
        "zip_integrity": "PASS",
    }


def validate_apk_set(paths: list[Path]) -> list[dict]:
    if not paths:
        raise ValueError("No APKs supplied")

    reports = [validate_apk(path) for path in paths]

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
            report["apks"] = validate_apk_set(apk_paths)
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
