from __future__ import annotations

import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
VERSION_FILE = ROOT / "version.json"


def parse_version(value: str) -> tuple[int, int, int]:
    match = re.fullmatch(r"(\d+)\.(\d+)\.(\d+)", str(value).strip())
    if match is None:
        raise ValueError(f"invalid BlueVPN version: {value!r}")
    major, minor, patch = map(int, match.groups())
    if minor > 10 or patch > 10:
        raise ValueError("BlueVPN minor/patch must stay within 0..10")
    return major, minor, patch


def version_code(version: tuple[int, int, int]) -> int:
    major, minor, patch = version
    return major * 10000 + minor * 100 + patch


def next_version(version: tuple[int, int, int]) -> tuple[int, int, int]:
    major, minor, patch = version
    if patch < 10:
        return major, minor, patch + 1
    if minor < 10:
        return major, minor + 1, 0
    return major + 1, 0, 0


def format_version(version: tuple[int, int, int]) -> str:
    return ".".join(map(str, version))


def update_contract(old_text: str) -> tuple[str, str, int, str, int]:
    data = json.loads(old_text)
    old = parse_version(str(data.get("version", "")))
    old_code = int(data.get("version_code", -1))
    if old_code != version_code(old):
        raise ValueError(f"version_code mismatch: {old_code} != {version_code(old)}")

    new = next_version(old)
    old_name = format_version(old)
    new_name = format_version(new)
    new_code = version_code(new)

    data["version"] = new_name
    data["version_code"] = new_code
    components = data.get("components")
    if not isinstance(components, dict) or not components:
        raise ValueError("version.json components contract is missing")
    for key in list(components):
        components[key] = new_name

    return json.dumps(data, ensure_ascii=False, indent=2) + "\n", old_name, old_code, new_name, new_code


def update_regression_expectations(old_version: str, old_code: int, new_version: str, new_code: int) -> int:
    changed = 0
    tests_dir = ROOT / "tests"
    for path in sorted(tests_dir.glob("test_*.py")):
        text = path.read_text(encoding="utf-8")
        updated = text.replace(old_version, new_version).replace(str(old_code), str(new_code))
        if updated != text:
            path.write_text(updated, encoding="utf-8")
            changed += 1
    return changed


def main() -> None:
    current_text = VERSION_FILE.read_text(encoding="utf-8")
    updated, old_version, old_code, new_version, new_code = update_contract(current_text)
    VERSION_FILE.write_text(updated, encoding="utf-8")

    subprocess.run([sys.executable, str(ROOT / "scripts" / "sync_version.py")], cwd=ROOT, check=True)
    touched_tests = update_regression_expectations(old_version, old_code, new_version, new_code)

    print(
        f"BlueVPN automatic release version: {old_version}/{old_code} -> "
        f"{new_version}/{new_code}; regression expectations updated in {touched_tests} tests"
    )


if __name__ == "__main__":
    main()
