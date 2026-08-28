from __future__ import annotations

import json
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
VERSION_FILE = ROOT / "version.json"
RELEASE_FILE = ROOT / "release.json"

TARGET_VERSION = "6.0.9"
TARGET_CODE = 60009

data = json.loads(VERSION_FILE.read_text(encoding="utf-8"))
data["version"] = TARGET_VERSION
data["version_code"] = TARGET_CODE
components = data.setdefault("components", {})
for key in list(components):
    components[key] = TARGET_VERSION
VERSION_FILE.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

subprocess.run(["python", str(ROOT / "scripts" / "sync_version.py")], cwd=ROOT, check=True)

release = json.loads(RELEASE_FILE.read_text(encoding="utf-8"))
features = release.setdefault("features", [])
for feature in [
    "android-locations-live-real-ping-health",
    "android-locations-scroll-position-preserved-on-selection",
    "android-locations-signal-bars-without-dish-emoji",
    "android-locations-selection-no-activity-restart",
]:
    if feature not in features:
        features.append(feature)
release["description"] = (
    "BlueVPN 6.0.9: Android Locations UX, live latency presentation, "
    "stable selection state, and coordinated release."
)
RELEASE_FILE.write_text(json.dumps(release, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

subprocess.run(["python", str(ROOT / "scripts" / "sync_version.py"), "--check"], cwd=ROOT, check=True)
print(f"Prepared BlueVPN {TARGET_VERSION} / {TARGET_CODE}")
