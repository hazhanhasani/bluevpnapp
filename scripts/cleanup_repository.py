#!/usr/bin/env python3
"""Remove artifacts that must not survive an overlay-style BlueVPN release update.

The release bundle is authoritative for Python regression modules. GitHub repositories
that are updated by copying/overlaying a ZIP can retain deleted ``tests/test_*.py``
files from old Railway/PostgreSQL-era releases. ``unittest discover`` would import
those stale modules even though their application code is no longer part of the
release. The manifest below solves that class of failure without hiding failures in
current tests: only test modules that are not shipped by this release are removed.
"""
from pathlib import Path
import json
import shutil
import sys

ROOT = Path(__file__).resolve().parents[1]
MANIFEST = ROOT / "tests" / "release_test_manifest.json"

RETIRED_FILES = [
    "android-source/BlueVpnEngineManager.kt",
    "android-source/BlueVpnSingBoxProcess.kt",
    "android-source/BlueVpnSingBoxProfileCompiler.kt",
    "android-source/BlueVpnAiActivity.kt",
]
RETIRED_DIRS = [
    "android-source/generated",
]


def load_authoritative_tests() -> set[str]:
    try:
        payload = json.loads(MANIFEST.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise SystemExit(f"ERROR: cannot read release test manifest: {exc}") from exc
    if payload.get("schema") != 1 or payload.get("authoritative") is not True:
        raise SystemExit("ERROR: invalid/non-authoritative release test manifest")
    names = payload.get("tests")
    if not isinstance(names, list) or not names:
        raise SystemExit("ERROR: release test manifest is empty")
    approved: set[str] = set()
    for raw in names:
        if not isinstance(raw, str) or not raw.startswith("test_") or not raw.endswith(".py"):
            raise SystemExit(f"ERROR: invalid test manifest entry: {raw!r}")
        name = Path(raw).name
        if name != raw:
            raise SystemExit(f"ERROR: nested/path test manifest entry is not allowed: {raw!r}")
        approved.add(name)
    return approved


def remove_stale_test_modules(approved: set[str], removed: list[str]) -> None:
    tests_dir = ROOT / "tests"
    if not tests_dir.is_dir():
        raise SystemExit("ERROR: tests directory is missing")

    missing = sorted(name for name in approved if not (tests_dir / name).is_file())
    if missing:
        raise SystemExit("ERROR: release manifest references missing tests: " + ", ".join(missing))

    for path in sorted(tests_dir.glob("test_*.py")):
        if path.name not in approved:
            path.unlink()
            removed.append(f"tests/{path.name}")


removed: list[str] = []
remove_stale_test_modules(load_authoritative_tests(), removed)

for rel in RETIRED_FILES:
    path = ROOT / rel
    if path.exists() or path.is_symlink():
        path.unlink()
        removed.append(rel)

for rel in RETIRED_DIRS:
    path = ROOT / rel
    if path.exists():
        shutil.rmtree(path)
        removed.append(rel + "/")

print("BlueVPN repository workspace cleanup complete.")
if removed:
    print("Removed retired/stale paths:")
    for rel in removed:
        print(f"- {rel}")
else:
    print("No retired/stale paths were present.")
