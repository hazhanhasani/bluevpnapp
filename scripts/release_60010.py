from __future__ import annotations

import json
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
VERSION_FILE = ROOT / "version.json"
RELEASE_FILE = ROOT / "release.json"

TARGET_VERSION = "6.0.10"
TARGET_CODE = 60010

data = json.loads(VERSION_FILE.read_text(encoding="utf-8"))
data["version"] = TARGET_VERSION
data["version_code"] = TARGET_CODE
components = data.setdefault("components", {})
for key in list(components):
    components[key] = TARGET_VERSION
VERSION_FILE.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

release = json.loads(RELEASE_FILE.read_text(encoding="utf-8"))
features = release.setdefault("features", [])
for feature in [
    "android-locations-dense-premium-browser-redesign",
    "android-locations-stable-order-and-scroll",
    "android-locations-connected-live-handover",
    "android-locations-live-latency-signal-bars",
    "android-locations-accordion-visual-hierarchy",
    "android-account-sync-timeout-hardening",
    "android-locations-timeout-preserves-local-pool",
]:
    if feature not in features:
        features.append(feature)
release["description"] = (
    "BlueVPN 6.0.10: redesigned Android Locations browser, stable scroll and selection, "
    "live latency presentation, and refresh-timeout hardening."
)
RELEASE_FILE.write_text(json.dumps(release, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

subprocess.run(["python", str(ROOT / "scripts" / "sync_version.py")], cwd=ROOT, check=True)
subprocess.run(["python", str(ROOT / "scripts" / "sync_version.py"), "--check"], cwd=ROOT, check=True)
print(f"Prepared BlueVPN {TARGET_VERSION} / {TARGET_CODE}")
