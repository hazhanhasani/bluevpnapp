#!/usr/bin/env python3
from pathlib import Path
import shutil

ROOT = Path(__file__).resolve().parents[1]

RETIRED_FILES = [
    "android-source/BlueVpnEngineManager.kt",
    "android-source/BlueVpnSingBoxProcess.kt",
    "android-source/BlueVpnSingBoxProfileCompiler.kt",
    "android-source/BlueVpnAiActivity.kt",
]
RETIRED_DIRS = [
    "android-source/generated",
]

removed = []
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
    print("Removed retired paths:")
    for rel in removed:
        print(f"- {rel}")
else:
    print("No retired paths were present.")
