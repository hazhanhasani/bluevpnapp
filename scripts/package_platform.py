from __future__ import annotations

import argparse
import os
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
EXCLUDED_DIRS = {'.git', '__pycache__', '.pytest_cache', '.idea', '.vscode', 'node_modules'}
EXCLUDED_SUFFIXES = {'.pyc', '.pyo'}


def include(path: Path) -> bool:
    rel = path.relative_to(ROOT)
    if any(part in EXCLUDED_DIRS for part in rel.parts):
        return False
    if path.suffix.lower() in EXCLUDED_SUFFIXES:
        return False
    return path.is_file()


def build(output: Path) -> None:
    output = output.resolve()
    output.parent.mkdir(parents=True, exist_ok=True)
    if output.exists():
        output.unlink()

    files = sorted(p for p in ROOT.rglob('*') if include(p) and p.resolve() != output)
    with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
        for path in files:
            zf.write(path, path.relative_to(ROOT).as_posix())

    with zipfile.ZipFile(output, 'r') as zf:
        names = set(zf.namelist())
        required = {
            'release.json',
            'branding/app.json',
            'bluevpn-manager/bluevpn-manager.php',
            'bluevpn-site/style.css',
            '.github/workflows/build-apk.yml',
            '.github/workflows/build-windows.yml',
            '.github/workflows/bluevpn-site-theme-release.yml',
            '.github/workflows/bluevpn-manager-release.yml',
            '.github/workflows/bluevpn-sentinel.yml',
        }
        missing = sorted(required - names)
        if missing:
            raise SystemExit('platform ZIP missing required files: ' + ', '.join(missing))
        bad = zf.testzip()
        if bad:
            raise SystemExit(f'platform ZIP CRC failure: {bad}')

    print(f'BlueVPN platform ZIP ready: {output}')


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('output', nargs='?', default=str(ROOT.parent / 'bluevpn-platform.zip'))
    args = parser.parse_args()
    build(Path(args.output))


if __name__ == '__main__':
    main()
