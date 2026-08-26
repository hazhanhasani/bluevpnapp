from pathlib import Path

path = Path(__file__).resolve().parents[1] / "tests/test_dual_control_plane_581.py"
text = path.read_text(encoding="utf-8")
old = "        self.assertIn('trimEnd('/') + \"/health\"', settings)\n"
new = "        self.assertIn(\"trimEnd('/') + \\\"/health\\\"\", settings)\n"
if old not in text:
    raise SystemExit("diagnostics assertion marker not found")
path.write_text(text.replace(old, new, 1), encoding="utf-8")
print("diagnostics assertion quoting fixed")
