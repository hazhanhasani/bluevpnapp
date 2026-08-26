#!/usr/bin/env python3
"""Apply BlueVPN's bounded Android release-size policy to upstream v2rayNG.

BlueVPN builds from a pinned upstream checkout on every release. The upstream
release currently keeps R8 disabled, so the APK ships unused bytecode/resources.
This helper changes only the release shrink flags while preserving the upstream
ABI split contract and all runtime/native packaging decisions.
"""
from __future__ import annotations

import argparse
from pathlib import Path


def optimize(path: Path) -> None:
    text = path.read_text(encoding="utf-8")
    original = text

    if "isMinifyEnabled = false" in text:
        text = text.replace(
            "isMinifyEnabled = false",
            "isMinifyEnabled = true\n            isShrinkResources = true",
            1,
        )
    elif "isMinifyEnabled = true" in text and "isShrinkResources = true" not in text:
        text = text.replace(
            "isMinifyEnabled = true",
            "isMinifyEnabled = true\n            isShrinkResources = true",
            1,
        )

    if "isMinifyEnabled = true" not in text:
        raise SystemExit("ERROR: Android release minify flag was not found")
    if "isShrinkResources = true" not in text:
        raise SystemExit("ERROR: Android release resource-shrink flag was not found")
    if "isUniversalApk = abiFilterList.isNullOrEmpty()" not in text:
        raise SystemExit("ERROR: upstream ABI split contract changed")

    if text != original:
        path.write_text(text, encoding="utf-8")
    print(f"BlueVPN Android release optimizer PASS: {path}")

    # The same authoritative pre-Gradle hook also hardens the location pool.
    # It patches both the overlay source and the generated upstream copy when
    # present, keeping R8 builds safe from null/corrupt MMKV rows.
    from harden_android_locations import apply as harden_android_locations
    harden_android_locations()


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "--path",
        default="upstream/V2rayNG/app/build.gradle.kts",
        help="Path to the upstream app build.gradle.kts",
    )
    args = parser.parse_args()
    optimize(Path(args.path))


if __name__ == "__main__":
    main()
