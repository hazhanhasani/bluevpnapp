#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MANAGER = ROOT / "bluevpn-manager"
MANIFEST = MANAGER / "release_php_manifest.json"


def load_manifest() -> list[str]:
    try:
        payload = json.loads(MANIFEST.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise SystemExit(f"ERROR: cannot read PHP manifest: {exc}") from exc

    if payload.get("schema") != 1 or payload.get("authoritative") is not True:
        raise SystemExit("ERROR: PHP manifest is not authoritative")

    rows = payload.get("php_files")
    if not isinstance(rows, list) or not rows:
        raise SystemExit("ERROR: PHP manifest is empty")

    result: list[str] = []
    for raw in rows:
        if not isinstance(raw, str) or not raw.endswith(".php"):
            raise SystemExit(f"ERROR: invalid PHP manifest entry: {raw!r}")
        rel = Path(raw)
        if rel.is_absolute() or ".." in rel.parts:
            raise SystemExit(f"ERROR: unsafe PHP manifest entry: {raw!r}")
        result.append(rel.as_posix())
    return result


def main() -> None:
    expected = load_manifest()

    actual = sorted(
        p.relative_to(MANAGER).as_posix()
        for p in MANAGER.rglob("*.php")
    )
    expected_sorted = sorted(expected)

    extra = sorted(set(actual) - set(expected_sorted))
    missing = sorted(set(expected_sorted) - set(actual))

    if extra:
        print("ERROR: stale/unshipped PHP files remain in repository:", file=sys.stderr)
        for rel in extra:
            print(f"  + bluevpn-manager/{rel}", file=sys.stderr)
    if missing:
        print("ERROR: release PHP files are missing:", file=sys.stderr)
        for rel in missing:
            print(f"  - bluevpn-manager/{rel}", file=sys.stderr)
    if extra or missing:
        raise SystemExit(1)

    failed = 0
    for rel in expected_sorted:
        path = MANAGER / rel
        proc = subprocess.run(
            ["php", "-l", str(path)],
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
        )
        output = proc.stdout.strip()
        if proc.returncode != 0:
            failed += 1
            print(f"PHP LINT FAILED: bluevpn-manager/{rel}", file=sys.stderr)
            print(output, file=sys.stderr)
        else:
            print(f"PHP LINT OK: bluevpn-manager/{rel}")

    if failed:
        raise SystemExit(f"ERROR: {failed} PHP file(s) failed syntax validation")

    print(f"BlueVPN PHP release validation: PASS ({len(expected_sorted)} files)")


if __name__ == "__main__":
    main()
