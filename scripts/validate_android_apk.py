#!/usr/bin/env python3
import hashlib, json, sys, zipfile
from pathlib import Path

REQUIRED = {
    "AndroidManifest.xml",
    "classes.dex",
    "resources.arsc",
    "lib/arm64-v8a/libbluevpn_aether.so",
    "lib/armeabi-v7a/libbluevpn_aether.so",
}

def validate(path: Path) -> dict:
    if not path.is_file() or path.stat().st_size < 100_000:
        raise SystemExit(f"APK missing or implausibly small: {path}")
    with zipfile.ZipFile(path) as z:
        bad = z.testzip()
        if bad:
            raise SystemExit(f"Corrupt APK entry: {bad}")
        names = set(z.namelist())
        missing = sorted(REQUIRED - names)
        if missing:
            raise SystemExit(f"APK runtime contract missing entries: {missing}")
        dex = sorted(n for n in names if n.startswith("classes") and n.endswith(".dex"))
        if not dex:
            raise SystemExit("No DEX payload found")
        for abi in ("arm64-v8a", "armeabi-v7a"):
            entry=f"lib/{abi}/libbluevpn_aether.so"
            info=z.getinfo(entry)
            if info.file_size < 100_000:
                raise SystemExit(f"Aether binary too small for {abi}: {info.file_size}")
    return {
        "apk": path.name,
        "size": path.stat().st_size,
        "sha256": hashlib.sha256(path.read_bytes()).hexdigest(),
        "required_entries": "PASS",
        "zip_integrity": "PASS",
    }

def main():
    if len(sys.argv) < 2:
        raise SystemExit("usage: validate_android_apk.py APK [APK...]")
    reports=[validate(Path(p)) for p in sys.argv[1:]]
    out=Path("reports/android-apk-validation.json")
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps({"schema":1,"apks":reports}, indent=2)+"\n")
    print(json.dumps(reports, indent=2))

if __name__ == "__main__":
    main()
