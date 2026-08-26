from pathlib import Path
import json
import subprocess

OLD_VERSION = "5.10.10"
NEW_VERSION = "6.0.0"
OLD_CODE = "51010"
NEW_CODE = "60000"

def replace_once(path: str, old: str, new: str) -> None:
    p = Path(path)
    s = p.read_text(encoding="utf-8")
    if old not in s:
        raise SystemExit(f"marker not found in {path}")
    p.write_text(s.replace(old, new, 1), encoding="utf-8")

# Manager: accept either the bare Mediaad id or the complete <div id="mediaad-*"> snippet.
ads_path = Path("bluevpn-manager/includes/class-bluevpn-ads.php")
ads = ads_path.read_text(encoding="utf-8")

anchor = """    private static function guard(string $nonce): void {
"""
helper = """    private static function normalize_windows_web_placement_id(string $value): string {
        $value = html_entity_decode(trim((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('/mediaad-[A-Za-z0-9_-]{2,120}/', $value, $m)) {
            return (string)$m[0];
        }
        $plain = trim(wp_strip_all_tags($value));
        if ($plain !== '' && preg_match('/^[A-Za-z0-9_-]{2,120}$/', $plain)) {
            return str_starts_with($plain, 'mediaad-') ? $plain : 'mediaad-' . $plain;
        }
        return '';
    }

"""
if "normalize_windows_web_placement_id" not in ads:
    if anchor not in ads:
        raise SystemExit("Manager placement helper anchor missing")
    ads = ads.replace(anchor, helper + anchor, 1)

ads = ads.replace(
"""        $settings = BlueVPN_DB::settings();
        $slot = mb_substr(trim((string)($settings['tapsell_windows_web_placement_id'] ?? '')), 0, 200);
        $target = add_query_arg(['bluevpn_tapsell_windows'=>'1','slot'=>$slot], 'https://blluepanel.ir/');
""",
"""        $settings = BlueVPN_DB::settings();
        $slot = self::normalize_windows_web_placement_id((string)($settings['tapsell_windows_web_placement_id'] ?? ''));
        $target = add_query_arg(['bluevpn_tapsell_windows'=>'1','slot'=>$slot], 'https://blluepanel.ir/');
""",
1)

# Normalize legacy saved HTML without requiring the admin to save the form again.
marker = """        $enabledPlacements = array_filter(
            $placements,
            static fn($row) => !empty($row['enabled'])
        );
"""
if marker not in ads:
    raise SystemExit("Manager Tapsell payload marker missing")
if "$windowsWebPlacementId = self::normalize_windows_web_placement_id" not in ads:
    ads = ads.replace(marker, marker + """        $windowsWebPlacementId = self::normalize_windows_web_placement_id(
            (string)($settings['tapsell_windows_web_placement_id'] ?? '')
        );
""", 1)

ads = ads.replace(
"""                'enabled' => !empty($settings['tapsell_windows_web_enabled']) && trim((string)($settings['tapsell_windows_web_script_html'] ?? '')) !== '' && trim((string)($settings['tapsell_windows_web_placement_id'] ?? '')) !== '',
                'placement_id' => mb_substr(trim((string)($settings['tapsell_windows_web_placement_id'] ?? '')), 0, 200),
                'script_html' => (string)($settings['tapsell_windows_web_script_html'] ?? ''),
                'bridge_url' => add_query_arg(['bluevpn_tapsell_windows'=>'1','slot'=>mb_substr(trim((string)($settings['tapsell_windows_web_placement_id'] ?? '')),0,200)], 'https://blluepanel.ir/'),
""",
"""                'enabled' => !empty($settings['tapsell_windows_web_enabled']) && $windowsWebPlacementId !== '',
                'placement_id' => $windowsWebPlacementId,
                'script_html' => (string)($settings['tapsell_windows_web_script_html'] ?? ''),
                'bridge_url' => add_query_arg(['bluevpn_tapsell_windows'=>'1','slot'=>$windowsWebPlacementId], 'https://blluepanel.ir/'),
""",
1)

ads = ads.replace(
"""        $s['tapsell_windows_web_placement_id'] = mb_substr(trim((string)wp_unslash($_POST['tapsell_windows_web_placement_id'] ?? '')), 0, 200);
""",
"""        $s['tapsell_windows_web_placement_id'] = self::normalize_windows_web_placement_id(
            (string)wp_unslash($_POST['tapsell_windows_web_placement_id'] ?? '')
        );
""",
1)
ads_path.write_text(ads, encoding="utf-8")

# Approved blluepanel.ir bridge: extract mediaad-* even when an old client sends the whole HTML snippet.
site_path = Path("bluevpn-site/functions.php")
site = site_path.read_text(encoding="utf-8")
old_slot = """    $slot = sanitize_text_field((string)wp_unslash($_GET['slot'] ?? ''));
    if ($slot !== '' && strpos($slot, 'mediaad-') !== 0 && preg_match('/^[A-Za-z0-9_-]{2,120}$/', $slot)) {
        $slot = 'mediaad-' . $slot;
    }
"""
new_slot = """    $slotRaw = html_entity_decode((string)wp_unslash($_GET['slot'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $slot = '';
    if (preg_match('/mediaad-[A-Za-z0-9_-]{2,120}/', $slotRaw, $slotMatch)) {
        $slot = (string)$slotMatch[0];
    } else {
        $plainSlot = trim(wp_strip_all_tags($slotRaw));
        if ($plainSlot !== '' && preg_match('/^[A-Za-z0-9_-]{2,120}$/', $plainSlot)) {
            $slot = str_starts_with($plainSlot, 'mediaad-') ? $plainSlot : 'mediaad-' . $plainSlot;
        }
    }
"""
if old_slot not in site:
    raise SystemExit("Site Mediaad slot parser block missing")
site = site.replace(old_slot, new_slot, 1)
site_path.write_text(site, encoding="utf-8")

# Regression contract: new bridge is dynamic and must preserve/extract the Mediaad slot.
test_path = Path("tests/test_tapsell_premium_carousel_windows_bridge_577.py")
test = test_path.read_text(encoding="utf-8")
test = test.replace(
"""        self.assertIn("'slot'=>mb_substr", ads)
        self.assertIn("$target = add_query_arg(['bluevpn_tapsell_windows'=>'1','slot'=>$slot]", ads)
        self.assertIn("wp_redirect($target, 302, 'BlueVPN')", ads)
""",
"""        self.assertIn("normalize_windows_web_placement_id", ads)
        self.assertIn("'slot'=>$windowsWebPlacementId", ads)
        self.assertIn("$target = add_query_arg(['bluevpn_tapsell_windows'=>'1','slot'=>$slot]", ads)
        self.assertIn("wp_redirect($target, 302, 'BlueVPN')", ads)
        self.assertIn("preg_match('/mediaad-[A-Za-z0-9_-]{2,120}/'", site)
        self.assertIn("wp_strip_all_tags($slotRaw)", site)
""",
1)
test_path.write_text(test, encoding="utf-8")

# Bump the canonical whole-project version.
vf = Path("version.json")
v = json.loads(vf.read_text(encoding="utf-8"))
v["version"] = NEW_VERSION
v["version_code"] = int(NEW_CODE)
v["components"] = {k: NEW_VERSION for k in v.get("components", {})}
vf.write_text(json.dumps(v, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

subprocess.run(["python", "scripts/sync_version.py"], check=True)

# Synchronize test assertions and other current release markers while leaving workflow history intact.
paths = subprocess.check_output(["git", "ls-files", "-z"]).decode().split("\0")
for raw in paths:
    if not raw or raw.startswith(".github/workflows/") or raw == "tests/release_test_manifest.json":
        continue
    p = Path(raw)
    try:
        data = p.read_text(encoding="utf-8")
    except (UnicodeDecodeError, OSError):
        continue
    updated = data.replace(OLD_VERSION, NEW_VERSION).replace(OLD_CODE, NEW_CODE)
    if updated != data:
        p.write_text(updated, encoding="utf-8")

# Full local gate before the release commit exists.
subprocess.run(["python", "scripts/sync_version.py", "--check"], check=True)
subprocess.run(["python", "scripts/validate_bundle_integrity.py"], check=True)
subprocess.run(["python", "scripts/validate_release.py"], check=True)
subprocess.run(["python", "scripts/validate_windows.py"], check=True)
subprocess.run(["python", "scripts/validate_php_release.py"], check=True)
subprocess.run(["python", "-m", "unittest", "discover", "-s", "tests", "-p", "test_*.py"], check=True)
