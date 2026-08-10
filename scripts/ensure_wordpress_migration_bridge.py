from __future__ import annotations

import ast
import shutil
from pathlib import Path

MAIN = Path("server/main.py")
BRIDGE = Path("server/wordpress_migration_bridge.py")
CANONICAL = Path(".github/bluevpn/wordpress_migration_bridge.py")
IMPORT_LINE = "from .wordpress_migration_bridge import register_wordpress_migration_bridge"
CALL_LINE = "register_wordpress_migration_bridge(app)"


def ensure_bridge_file() -> bool:
    if BRIDGE.exists():
        return False
    if not CANONICAL.exists():
        raise SystemExit("Migration Bridge is missing from both server/ and .github/bluevpn/")
    BRIDGE.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(CANONICAL, BRIDGE)
    print("Restored server/wordpress_migration_bridge.py")
    return True


def insert_import(lines: list[str]) -> bool:
    if any(line.strip() == IMPORT_LINE for line in lines):
        return False

    # Put the import after the last relative project import near the top.
    last_relative = None
    for i, line in enumerate(lines[:180]):
        stripped = line.strip()
        if stripped.startswith("from .") or stripped.startswith("import ."):
            last_relative = i
    if last_relative is None:
        raise SystemExit("Could not find a safe relative-import anchor in server/main.py")
    lines.insert(last_relative + 1, IMPORT_LINE + "\n")
    print("Added WordPress Migration Bridge import")
    return True


def find_fastapi_assignment_end(lines: list[str]) -> int:
    start = None
    for i, line in enumerate(lines):
        normalized = line.replace(" ", "").replace("\t", "")
        if normalized.startswith("app=FastAPI("):
            start = i
            break
    if start is None:
        raise SystemExit("Could not find app=FastAPI(...) in server/main.py")

    depth = 0
    started = False
    for i in range(start, min(len(lines), start + 80)):
        text = lines[i]
        # Good enough for this constructor block; strings here do not contain parentheses.
        for ch in text:
            if ch == "(":
                depth += 1
                started = True
            elif ch == ")" and started:
                depth -= 1
        if started and depth == 0:
            return i
    raise SystemExit("Could not determine the end of app=FastAPI(...) block")


def insert_call(lines: list[str]) -> bool:
    # Remove accidental duplicate registration calls, then insert exactly one after app creation.
    occurrences = [i for i, line in enumerate(lines) if line.strip() == CALL_LINE]
    changed = False
    if occurrences:
        keep = occurrences[0]
        for i in reversed(occurrences[1:]):
            del lines[i]
            changed = True
        # Re-evaluate after deletes.
        current = next(i for i, line in enumerate(lines) if line.strip() == CALL_LINE)
        app_end = find_fastapi_assignment_end(lines)
        if current == app_end + 1:
            return changed
        del lines[current]
        changed = True

    app_end = find_fastapi_assignment_end(lines)
    lines.insert(app_end + 1, CALL_LINE + "\n")
    print("Registered WordPress Migration Bridge on FastAPI app")
    return True


def validate(text: str) -> None:
    ast.parse(text)
    if IMPORT_LINE not in text:
        raise SystemExit("Bridge import validation failed")
    if text.count(CALL_LINE) != 1:
        raise SystemExit("Bridge registration validation failed")
    app_pos = text.find("app=FastAPI(")
    call_pos = text.find(CALL_LINE)
    if app_pos < 0 or call_pos < app_pos:
        raise SystemExit("Bridge registration is before FastAPI app creation")


def main() -> None:
    if not MAIN.exists():
        raise SystemExit("server/main.py not found")
    changed = ensure_bridge_file()
    lines = MAIN.read_text(encoding="utf-8").splitlines(keepends=True)
    changed = insert_import(lines) or changed
    changed = insert_call(lines) or changed
    text = "".join(lines)
    validate(text)
    if changed:
        MAIN.write_text(text, encoding="utf-8")
        print("Migration Bridge guard repaired the backend")
    else:
        print("Migration Bridge is already healthy; no changes needed")


if __name__ == "__main__":
    main()
