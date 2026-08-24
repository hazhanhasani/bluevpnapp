from pathlib import Path

root = Path(__file__).resolve().parents[1]
required_workflows = {
    "bluevpn-manager-release.yml", "bluevpn-sentinel.yml",
    "bluevpn-site-theme-release.yml", "build-apk.yml", "build-ios.yml",
    "build-windows.yml", "external-health.yml", "project-health.yml",
}
workflow_dir = root / ".github" / "workflows"
present = {path.name for path in workflow_dir.glob("*.yml")}
missing = sorted(required_workflows - present)
if missing:
    raise SystemExit("deployment bundle is missing GitHub workflows: " + ", ".join(missing))
print(f"BlueVPN bundle integrity PASS — {len(required_workflows)} workflows preserved")
