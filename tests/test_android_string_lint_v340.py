from __future__ import annotations

import importlib.util
import json
import xml.etree.ElementTree as ET
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
KEYS = {
    "service_started",
    "service_stopped",
    "notification_service_running",
}


def _load_prepare_module():
    path = ROOT / "scripts/prepare_android.py"
    spec = importlib.util.spec_from_file_location("prepare_android_v340", path)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def _names(path: Path) -> set[str]:
    root = ET.parse(path).getroot()
    return {
        node.attrib["name"]
        for node in root.findall("string")
        if "name" in node.attrib
    }


def test_patch_strings_adds_default_locale_counterparts(tmp_path):
    module = _load_prepare_module()
    app = tmp_path / "app"
    default = app / "src/main/res/values/strings.xml"
    persian = app / "src/main/res/values-fa/strings.xml"
    default.parent.mkdir(parents=True)
    persian.parent.mkdir(parents=True)
    default.write_text(
        '<?xml version="1.0" encoding="utf-8"?>\n'
        '<resources>\n'
        '<string name="app_name">Old</string>\n'
        '</resources>\n',
        encoding="utf-8",
    )
    persian.write_text(
        '<?xml version="1.0" encoding="utf-8"?>\n'
        '<resources>\n'
        '</resources>\n',
        encoding="utf-8",
    )
    module.APP = app
    module.CONFIG = {"app_name": "BlueVPN"}

    module.patch_strings()

    default_names = _names(default)
    persian_names = _names(persian)
    assert KEYS.issubset(default_names)
    assert KEYS.issubset(persian_names)
    assert persian_names.issubset(default_names)
    ET.parse(default)
    ET.parse(persian)


def test_generator_contains_missing_default_guard():
    prepare = (ROOT / "scripts/prepare_android.py").read_text(encoding="utf-8")
    assert "Missing default Android string resources for translated keys" in prepare
    assert "default_fallbacks" in prepare


def test_release_is_3040():
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    assert release["version"] == "3.0.76"
    assert release["version_code"] == 30076
    assert app["version_name"] == "3.0.76"
    assert app["version_code"] == 30076
