from __future__ import annotations

import json
import os
import re
from pathlib import Path

_DEFAULT_VERSION = "3.0.12"
_DEFAULT_VERSION_CODE = 30012


def _valid_version(value: str) -> str:
    value = value.strip().lstrip("v")
    return value if re.fullmatch(r"\d+\.\d+\.\d+", value) else ""


def _load_release() -> tuple[str, int]:
    env_version = _valid_version(os.getenv("BLUEVPN_VERSION", ""))
    env_code = os.getenv("BLUEVPN_VERSION_CODE", "").strip()
    if env_version:
        try:
            code = int(env_code) if env_code else _DEFAULT_VERSION_CODE
        except ValueError:
            code = _DEFAULT_VERSION_CODE
        return env_version, code

    candidates = (
        Path(__file__).resolve().parents[1] / "release.json",
        Path("/app/release.json"),
    )
    for path in candidates:
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
            version = _valid_version(str(data.get("version") or ""))
            code = int(data.get("version_code") or 0)
            if version:
                return version, code or _DEFAULT_VERSION_CODE
        except (OSError, ValueError, TypeError, json.JSONDecodeError):
            continue

    return _DEFAULT_VERSION, _DEFAULT_VERSION_CODE


VERSION, VERSION_CODE = _load_release()
