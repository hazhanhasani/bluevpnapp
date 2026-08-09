from __future__ import annotations

import ast
import base64
import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


def _embedded(name: str) -> bytes:
    module = ast.parse(
        (ROOT / "scripts" / "prepare_android.py").read_text(
            encoding="utf-8"
        )
    )
    for node in module.body:
        if not isinstance(node, ast.Assign) or len(node.targets) != 1:
            continue
        target = node.targets[0]
        if isinstance(target, ast.Name) and target.id == name:
            return base64.b64decode(ast.literal_eval(node.value))
    raise AssertionError(f"embedded source not found: {name}")


def test_v338_metadata_and_generated_sources_are_synchronized():
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding" / "app.json").read_text(encoding="utf-8"))

    assert release["version"] == "3.0.67"
    assert release["version_code"] == 30067
    assert app["version_name"] == "3.0.67"
    assert app["version_code"] == 30067

    assert _embedded("BLUEVPN_HOME_ACTIVITY_B64") == (
        ROOT / "android-source" / "BlueVpnHomeActivity.kt"
    ).read_bytes()
    assert _embedded("BLUEVPN_SERVERS_ACTIVITY_B64") == (
        ROOT / "android-source" / "BlueVpnServersActivity.kt"
    ).read_bytes()
    assert _embedded("BLUEVPN_SCREEN_BACKGROUND_B64") == (
        ROOT / "android-source" / "bluevpn_screen_background.xml"
    ).read_bytes()
    assert _embedded("BLUEVPN_THEME_B64") == (
        ROOT / "android-source" / "BlueVpnTheme.kt"
    ).read_bytes()


def test_minimal_home_and_location_browser_contract():
    home = (ROOT / "android-source" / "BlueVpnHomeActivity.kt").read_text(
        encoding="utf-8"
    )
    servers = (
        ROOT / "android-source" / "BlueVpnServersActivity.kt"
    ).read_text(encoding="utf-8")

    screen = home.split("private fun createScreen(): View {", 1)[1].split(
        "private fun createHeader(): View {", 1
    )[0]

    assert "ScrollView(this)" not in screen
    assert "private lateinit var connectTrack: MaterialCardView" in home
    assert '"برای اتصال لمس یا بکشید"' in home
    assert "animated-connection-knob" in json.dumps(
        json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    )
    assert 'textView("مکان‌ها"' in servers
    assert 'LocationTab.FAVORITES' in servers
    assert 'LocationTab.RECENT' in servers
    assert "BlueVpnExperience.history(this)" in servers
    assert "JumpJump" not in home
    assert "JumpJump" not in servers
