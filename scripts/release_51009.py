from pathlib import Path
import json
import subprocess

OLD_VERSION = "5.10.8"
NEW_VERSION = "5.10.9"
OLD_CODE = "51008"
NEW_CODE = "51009"

main = Path("bluevpn-windows/MainWindow.xaml.cs")
s = main.read_text(encoding="utf-8")

old = """            // WebView must participate in layout while the provider script is
            // loading; a Collapsed WebView gives ad iframes a zero viewport.
            TapsellWebView.Visibility = Visibility.Visible;
            AdFallbackPanel.Visibility = Visibility.Collapsed;
            TapsellLoadingPanel.Visibility = Visibility.Visible;
"""
new = """            // Keep the WebView in layout while the provider loads so ad iframes
            // receive a real viewport, but do not expose the native white surface.
            // It becomes visible only after provider content is positively detected.
            TapsellWebView.Visibility = Visibility.Hidden;
            AdFallbackPanel.Visibility = Visibility.Collapsed;
            TapsellLoadingPanel.Visibility = Visibility.Visible;
"""
if old not in s:
    raise SystemExit("Tapsell loading visibility block not found")
s = s.replace(old, new, 1)

old_wait = """            if (!await WaitForTapsellContentAsync(_lifetimeCts.Token))
                return false;
            TapsellLoadingPanel.Visibility = Visibility.Collapsed;
            TapsellWebView.Visibility = Visibility.Visible;
"""
new_wait = """            if (!await WaitForTapsellContentAsync(_lifetimeCts.Token))
            {
                TapsellWebView.Visibility = Visibility.Collapsed;
                TapsellLoadingPanel.Visibility = Visibility.Collapsed;
                AdProviderLabel.Visibility = Visibility.Collapsed;
                return false;
            }
            TapsellLoadingPanel.Visibility = Visibility.Collapsed;
            TapsellWebView.Visibility = Visibility.Visible;
"""
if old_wait not in s:
    raise SystemExit("Tapsell render gate block not found")
s = s.replace(old_wait, new_wait, 1)
main.write_text(s, encoding="utf-8")

test = Path("tests/test_windows_theme_tapsell_render_576.py")
if test.exists():
    t = test.read_text(encoding="utf-8")
    marker = '        self.assertIn("WaitForTapsellContentAsync", code)\n'
    if marker in t and 'TapsellWebView.Visibility = Visibility.Hidden' not in t:
        t = t.replace(marker, marker + '        self.assertIn("TapsellWebView.Visibility = Visibility.Hidden", code)\n')
        test.write_text(t, encoding="utf-8")

vf = Path("version.json")
v = json.loads(vf.read_text(encoding="utf-8"))
v["version"] = NEW_VERSION
v["version_code"] = int(NEW_CODE)
v["components"] = {k: NEW_VERSION for k in v.get("components", {})}
vf.write_text(json.dumps(v, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

subprocess.run(["python", "scripts/sync_version.py"], check=True)

paths = subprocess.check_output(["git", "ls-files", "-z"]).decode().split("\0")
for raw in paths:
    if not raw or raw.startswith(".github/workflows/"):
        continue
    p = Path(raw)
    try:
        data = p.read_text(encoding="utf-8")
    except (UnicodeDecodeError, OSError):
        continue
    updated = data.replace(OLD_VERSION, NEW_VERSION).replace(OLD_CODE, NEW_CODE)
    if updated != data:
        p.write_text(updated, encoding="utf-8")

subprocess.run(["python", "scripts/sync_version.py", "--check"], check=True)
subprocess.run(["python", "scripts/validate_release.py"], check=True)
subprocess.run(["python", "scripts/validate_windows.py"], check=True)
subprocess.run(["python", "scripts/validate_php_release.py"], check=True)
