from __future__ import annotations

import ast
import base64
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def _embedded_servers_source() -> str:
    module = ast.parse((ROOT / "scripts/prepare_android.py").read_text(encoding="utf-8"))
    for node in module.body:
        if not isinstance(node, ast.Assign) or len(node.targets) != 1:
            continue
        target = node.targets[0]
        if isinstance(target, ast.Name) and target.id == "BLUEVPN_SERVERS_ACTIVITY_B64":
            return base64.b64decode(ast.literal_eval(node.value)).decode("utf-8")
    raise AssertionError("BLUEVPN_SERVERS_ACTIVITY_B64 not found")

def test_servers_search_uses_supported_single_line_property():
    source = (ROOT / "android-source/BlueVpnServersActivity.kt").read_text(encoding="utf-8")
    assert "isSingleLine = true" in source
    assert re.search(r"(?m)^\s*singleLine\s*=", source) is None

def test_embedded_servers_source_matches_snapshot():
    source = (ROOT / "android-source/BlueVpnServersActivity.kt").read_text(encoding="utf-8")
    assert _embedded_servers_source() == source

def test_release_is_3039():
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    assert release["version"] == "3.0.66"
    assert release["version_code"] == 30066
    assert app["version_name"] == "3.0.66"
    assert app["version_code"] == 30066


def test_prepare_android_rejects_invalid_singleline_regression():
    prepare = (ROOT / "scripts/prepare_android.py").read_text(encoding="utf-8")
    assert "Invalid Kotlin generated source: use EditText.isSingleLine" in prepare
    assert 're.search(r"(?m)^\\s*singleLine\\s*=", servers_source)' in prepare
