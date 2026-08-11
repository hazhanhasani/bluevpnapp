#!/usr/bin/env python3
from __future__ import annotations

import json
import py_compile
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
passed: list[str] = []
failed: list[str] = []
warnings: list[str] = []


def check(name: str, condition: bool, detail: str = "") -> None:
    if condition:
        passed.append(name)
    else:
        failed.append(f"{name}: {detail}" if detail else name)


def text(relative: str) -> str:
    path = ROOT / relative
    check(f"file:{relative}", path.is_file(), "missing")
    return path.read_text(encoding="utf-8") if path.is_file() else ""


home = text("android-source/BlueVpnHomeActivity.kt")
account = text("android-source/BlueVpnAccountManager.kt")
engine = text("android-source/BlueVpnEngineManager.kt")
singbox = text("android-source/BlueVpnSingBoxProcess.kt")
prepare = text("scripts/prepare_android.py")
workflow = text(".github/workflows/build-apk.yml")
doc = text("docs/DUAL_ENGINE_RUNTIME_3070_FA.md")

check("ui-no-direct-core-service", "CoreServiceManager" not in home)
check("account-no-direct-core-service", "import com.v2ray.ang.core.CoreServiceManager" not in account and "CoreServiceManager.stopVService(appContext)" not in "\n".join(line for line in account.splitlines() if not line.strip().startswith("//")))
check("engine-is-only-compatibility-boundary", "CoreServiceManager.startVService" in engine and "CoreServiceManager.stopVService" in engine)
check("home-routes-start-through-engine", "BlueVpnEngineManager.start" in home)
check("home-routes-stop-through-engine", "BlueVpnEngineManager.stop" in home)
check("sing-box-native-profile-check", 'listOf("check", "-c"' in singbox)
check("sing-box-full-json-guard", 'root.has("inbounds")' in singbox and 'root.has("outbounds")' in singbox)
check("sing-box-api24-process-compatibility", all(token not in singbox for token in ("process.isAlive", "process.destroyForcibly", "process.waitFor(timeout")))
check("single-tun-owner-guard", "Do not launch a second TUN-capable process" in engine)
check("plain-source-overrides-enabled", "plain_overrides" in prepare and "shutil.copy2(source, target)" in prepare)
check("engine-in-generated-sources", 'BlueVpnEngineManager.kt"' in prepare)
check("sing-box-in-generated-sources", 'BlueVpnSingBoxProcess.kt"' in prepare)
check("workflow-pins-sing-box", "steps.config.outputs.sing_box_ref" in workflow)
check("workflow-builds-arm64", "arm64-v8a/libbluevpn_singbox.so" in workflow)
check(
    "workflow-armv7-safe-fallback",
    "armeabi-v7a intentionally uses the Xray fallback" in workflow,
)
check("workflow-avoids-second-gomobile-aar", "libbox.aar" not in workflow)
check("migration-documented", "Xray" in doc and "sing-box" in doc and "TUN" in doc)

for relative in ("branding/app.json", "release.json", "deployment-marker.json", "validation-report.json"):
    try:
        json.loads((ROOT / relative).read_text(encoding="utf-8"))
        passed.append(f"json:{relative}")
    except Exception as exc:  # noqa: BLE001
        failed.append(f"json:{relative}: {exc}")

app = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
marker = json.loads((ROOT / "deployment-marker.json").read_text(encoding="utf-8"))
check("metadata-version-consistent", {app.get("version_name"), release.get("version"), marker.get("version_name"), marker.get("release")} == {"4.0.0"})
check("metadata-version-code-consistent", {app.get("version_code"), release.get("version_code"), marker.get("version_code")} == {40000})
check("sing-box-reference-declared", app.get("sing_box_ref") == "v1.13.16")

try:
    py_compile.compile(str(ROOT / "scripts/prepare_android.py"), doraise=True)
    passed.append("python:prepare_android")
except Exception as exc:  # noqa: BLE001
    failed.append(f"python:prepare_android: {exc}")

# Lightweight YAML structure guard. It catches accidental indentation or missing
# steps without depending on a particular YAML parser's treatment of the `on` key.
step_names = re.findall(r"(?m)^\s{6}- name:\s+(.+)$", workflow)
check("workflow-has-build-job", "jobs:" in workflow and "build:" in workflow)
check("workflow-sing-box-step-ordered", "Build isolated sing-box Android runtime" in step_names)
check("workflow-android-build-still-present", any("Build" in name and "APK" in name for name in step_names))

warnings.append("Full Gradle/NDK/Go dependency build is delegated to GitHub Actions and was not executable in this offline container.")

report_path = ROOT / "validation-report.json"
report = json.loads(report_path.read_text(encoding="utf-8"))
report["tests"] = {"passed": len(passed), "failed": len(failed), "warnings": len(warnings)}
report["dual_engine"]["status"] = "passed" if not failed else "failed"
report["dual_engine"]["ui_core_decoupling"] = "passed" if "ui-no-direct-core-service" in passed and "account-no-direct-core-service" in passed else "failed"
report["static_validation"] = {"passed": passed, "failed": failed, "warnings": warnings}
report_path.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

print(f"passed={len(passed)} failed={len(failed)} warnings={len(warnings)}")
for item in failed:
    print(f"FAIL: {item}")
for item in warnings:
    print(f"WARN: {item}")
sys.exit(1 if failed else 0)
