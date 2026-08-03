from __future__ import annotations

import base64
import json
import re
from pathlib import Path
from xml.sax.saxutils import escape
from PIL import Image

ROOT = Path(__file__).resolve().parents[1]
CONFIG = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
ANDROID = ROOT / "upstream" / "V2rayNG"
APP = ANDROID / "app"
BOOTSTRAP_B64 = "cGFja2FnZSBjb20udjJyYXkuYW5nLmJsdWV2cG4KCmltcG9ydCBhbmRyb2lkLmFwcC5Ob3RpZmljYXRpb25DaGFubmVsCmltcG9ydCBhbmRyb2lkLmFwcC5Ob3RpZmljYXRpb25NYW5hZ2VyCmltcG9ydCBhbmRyb2lkLmFwcC5QZW5kaW5nSW50ZW50CmltcG9ydCBhbmRyb2lkLmNvbnRlbnQuQ29udGV4dAppbXBvcnQgYW5kcm9pZC5jb250ZW50LkludGVudAppbXBvcnQgYW5kcm9pZC5uZXQuVXJpCmltcG9ydCBhbmRyb2lkLm9zLkJ1aWxkCmltcG9ydCBhbmRyb2lkeC5jb3JlLmFwcC5Ob3RpZmljYXRpb25Db21wYXQKaW1wb3J0IGNvbS52MnJheS5hbmcuQnVpbGRDb25maWcKaW1wb3J0IGNvbS52MnJheS5hbmcuUgppbXBvcnQgY29tLnYycmF5LmFuZy5oYW5kbGVyLkFuZ0NvbmZpZ01hbmFnZXIKaW1wb3J0IG9yZy5qc29uLkpTT05PYmplY3QKaW1wb3J0IGphdmEubmV0Lkh0dHBVUkxDb25uZWN0aW9uCmltcG9ydCBqYXZhLm5ldC5VUkwKCm9iamVjdCBCbHVlVnBuQm9vdHN0cmFwIHsKICAgIHByaXZhdGUgY29uc3QgdmFsIFBSRUZTID0gImJsdWV2cG5fcmVtb3RlX2NvbmZpZyIKICAgIHByaXZhdGUgY29uc3QgdmFsIENIQU5ORUwgPSAiYmx1ZXZwbl9hZG1pbiIKCiAgICBmdW4gc3RhcnQoY29udGV4dDogQ29udGV4dCkgewogICAgICAgIHZhbCBiYXNlVXJsID0gQnVpbGRDb25maWcuQkxVRVZQTl9BUElfQkFTRV9VUkwudHJpbUVuZCgnLycpCiAgICAgICAgaWYgKGJhc2VVcmwuaXNCbGFuaygpKSByZXR1cm4KICAgICAgICBUaHJlYWQgewogICAgICAgICAgICB0cnkgewogICAgICAgICAgICAgICAgdmFsIGNvbmZpZyA9IGdldEpzb24oIiRiYXNlVXJsL2FwaS92MS9tb2JpbGUvY29uZmlnIikKICAgICAgICAgICAgICAgIGltcG9ydERlZmF1bHRTdWJzY3JpcHRpb24oY29udGV4dCwgY29uZmlnKQogICAgICAgICAgICAgICAgc2hvd1JlbW90ZU5vdGljZShjb250ZXh0LCBjb25maWcpCiAgICAgICAgICAgIH0gY2F0Y2ggKF86IEV4Y2VwdGlvbikgewogICAgICAgICAgICAgICAgLy8gVGhlIFZQTiByZW1haW5zIHVzYWJsZSBpZiB0aGUgY29udHJvbCBwYW5lbCBpcyB0ZW1wb3JhcmlseSB1bmF2YWlsYWJsZS4KICAgICAgICAgICAgfQogICAgICAgIH0uc3RhcnQoKQogICAgfQoKICAgIHByaXZhdGUgZnVuIGdldEpzb24odXJsOiBTdHJpbmcpOiBKU09OT2JqZWN0IHsKICAgICAgICB2YWwgY29ubmVjdGlvbiA9IFVSTCh1cmwpLm9wZW5Db25uZWN0aW9uKCkgYXMgSHR0cFVSTENvbm5lY3Rpb24KICAgICAgICBjb25uZWN0aW9uLmNvbm5lY3RUaW1lb3V0ID0gODAwMAogICAgICAgIGNvbm5lY3Rpb24ucmVhZFRpbWVvdXQgPSA4MDAwCiAgICAgICAgY29ubmVjdGlvbi5yZXF1ZXN0TWV0aG9kID0gIkdFVCIKICAgICAgICBjb25uZWN0aW9uLnNldFJlcXVlc3RQcm9wZXJ0eSgiQWNjZXB0IiwgImFwcGxpY2F0aW9uL2pzb24iKQogICAgICAgIHJldHVybiBjb25uZWN0aW9uLmlucHV0U3RyZWFtLmJ1ZmZlcmVkUmVhZGVyKCkudXNlIHsgSlNPTk9iamVjdChpdC5yZWFkVGV4dCgpKSB9CiAgICB9CgogICAgcHJpdmF0ZSBmdW4gaW1wb3J0RGVmYXVsdFN1YnNjcmlwdGlvbihjb250ZXh0OiBDb250ZXh0LCBjb25maWc6IEpTT05PYmplY3QpIHsKICAgICAgICB2YWwgc3Vic2NyaXB0aW9uID0gY29uZmlnLm9wdFN0cmluZygiZGVmYXVsdF9zdWJzY3JpcHRpb25fdXJsIikudHJpbSgpCiAgICAgICAgaWYgKHN1YnNjcmlwdGlvbi5pc0JsYW5rKCkpIHJldHVybgogICAgICAgIHZhbCBwcmVmcyA9IGNvbnRleHQuZ2V0U2hhcmVkUHJlZmVyZW5jZXMoUFJFRlMsIENvbnRleHQuTU9ERV9QUklWQVRFKQogICAgICAgIHZhbCBrZXkgPSAiaW1wb3J0ZWRfIiArIHN1YnNjcmlwdGlvbi5oYXNoQ29kZSgpCiAgICAgICAgaWYgKHByZWZzLmdldEJvb2xlYW4oa2V5LCBmYWxzZSkpIHJldHVybgogICAgICAgIHZhbCByZXN1bHQgPSBBbmdDb25maWdNYW5hZ2VyLmltcG9ydEJhdGNoQ29uZmlnKHN1YnNjcmlwdGlvbiwgIiIsIGZhbHNlKQogICAgICAgIGlmIChyZXN1bHQuZmlyc3QgKyByZXN1bHQuc2Vjb25kID4gMCkgewogICAgICAgICAgICBwcmVmcy5lZGl0KCkucHV0Qm9vbGVhbihrZXksIHRydWUpLmFwcGx5KCkKICAgICAgICB9CiAgICB9CgogICAgcHJpdmF0ZSBmdW4gc2hvd1JlbW90ZU5vdGljZShjb250ZXh0OiBDb250ZXh0LCBjb25maWc6IEpTT05PYmplY3QpIHsKICAgICAgICB2YWwgYW5ub3VuY2VtZW50ID0gY29uZmlnLm9wdEpTT05PYmplY3QoImFubm91bmNlbWVudCIpID86IEpTT05PYmplY3QoKQogICAgICAgIHZhbCBlbmFibGVkID0gYW5ub3VuY2VtZW50Lm9wdEJvb2xlYW4oImVuYWJsZWQiLCBmYWxzZSkKICAgICAgICB2YWwgZm9yY2VVcGRhdGUgPSBjb25maWcub3B0Qm9vbGVhbigiZm9yY2VfdXBkYXRlIiwgZmFsc2UpCiAgICAgICAgdmFsIG1haW50ZW5hbmNlID0gY29uZmlnLm9wdEJvb2xlYW4oIm1haW50ZW5hbmNlIiwgZmFsc2UpCiAgICAgICAgaWYgKCFlbmFibGVkICYmICFmb3JjZVVwZGF0ZSAmJiAhbWFpbnRlbmFuY2UpIHJldHVybgoKICAgICAgICB2YWwgaWQgPSBhbm5vdW5jZW1lbnQub3B0U3RyaW5nKCJpZCIsICJub3RpY2UiKQogICAgICAgIHZhbCBwcmVmcyA9IGNvbnRleHQuZ2V0U2hhcmVkUHJlZmVyZW5jZXMoUFJFRlMsIENvbnRleHQuTU9ERV9QUklWQVRFKQogICAgICAgIGlmICghZm9yY2VVcGRhdGUgJiYgIW1haW50ZW5hbmNlICYmIHByZWZzLmdldFN0cmluZygibGFzdF9ub3RpY2UiLCAiIikgPT0gaWQpIHJldHVybgoKICAgICAgICB2YWwgdGl0bGUgPSB3aGVuIHsKICAgICAgICAgICAgZm9yY2VVcGRhdGUgLT4gItmG2LPYrtmHINis2K/bjNivIEJsdWVWUE4iCiAgICAgICAgICAgIG1haW50ZW5hbmNlIC0+ICJCbHVlVlBOINiv2LEg2K3Yp9mEINio2YfigIzYsdmI2LLYsdiz2KfZhtuMINin2LPYqiIKICAgICAgICAgICAgZWxzZSAtPiBhbm5vdW5jZW1lbnQub3B0U3RyaW5nKCJ0aXRsZSIsICJCbHVlVlBOIikKICAgICAgICB9CiAgICAgICAgdmFsIG1lc3NhZ2UgPSB3aGVuIHsKICAgICAgICAgICAgZm9yY2VVcGRhdGUgLT4gItio2LHYp9uMINin2K/Yp9mF2YfYjCDZhtiz2K7ZhyDYrNiv24zYryDYqNix2YbYp9mF2Ycg2LHYpyDYr9ix24zYp9mB2Kog2qnZhtuM2K8uIgogICAgICAgICAgICBtYWludGVuYW5jZSAtPiAi2YTYt9mB2KfZiyDaqdmF24wg2KjYudivINiv2YjYqNin2LHZhyDYqtmE2KfYtCDaqdmG24zYry4iCiAgICAgICAgICAgIGVsc2UgLT4gYW5ub3VuY2VtZW50Lm9wdFN0cmluZygibWVzc2FnZSIsICIiKQogICAgICAgIH0KCiAgICAgICAgdmFsIGFwa1VybCA9IGNvbmZpZy5vcHRTdHJpbmcoImFwa191cmwiLCAiIikKICAgICAgICB2YWwgaW50ZW50ID0gaWYgKGFwa1VybC5zdGFydHNXaXRoKCJodHRwIikpIHsKICAgICAgICAgICAgSW50ZW50KEludGVudC5BQ1RJT05fVklFVywgVXJpLnBhcnNlKGFwa1VybCkpCiAgICAgICAgfSBlbHNlIHsKICAgICAgICAgICAgY29udGV4dC5wYWNrYWdlTWFuYWdlci5nZXRMYXVuY2hJbnRlbnRGb3JQYWNrYWdlKGNvbnRleHQucGFja2FnZU5hbWUpCiAgICAgICAgfQogICAgICAgIHZhbCBwZW5kaW5nSW50ZW50ID0gUGVuZGluZ0ludGVudC5nZXRBY3Rpdml0eSgKICAgICAgICAgICAgY29udGV4dCwgMTAwMSwgaW50ZW50LAogICAgICAgICAgICBQZW5kaW5nSW50ZW50LkZMQUdfVVBEQVRFX0NVUlJFTlQgb3IgUGVuZGluZ0ludGVudC5GTEFHX0lNTVVUQUJMRQogICAgICAgICkKCiAgICAgICAgdmFsIG1hbmFnZXIgPSBjb250ZXh0LmdldFN5c3RlbVNlcnZpY2UoQ29udGV4dC5OT1RJRklDQVRJT05fU0VSVklDRSkgYXMgTm90aWZpY2F0aW9uTWFuYWdlcgogICAgICAgIGlmIChCdWlsZC5WRVJTSU9OLlNES19JTlQgPj0gQnVpbGQuVkVSU0lPTl9DT0RFUy5PKSB7CiAgICAgICAgICAgIG1hbmFnZXIuY3JlYXRlTm90aWZpY2F0aW9uQ2hhbm5lbCgKICAgICAgICAgICAgICAgIE5vdGlmaWNhdGlvbkNoYW5uZWwoQ0hBTk5FTCwgIkJsdWVWUE4iLCBOb3RpZmljYXRpb25NYW5hZ2VyLklNUE9SVEFOQ0VfREVGQVVMVCkKICAgICAgICAgICAgKQogICAgICAgIH0KCiAgICAgICAgdmFsIG5vdGlmaWNhdGlvbiA9IE5vdGlmaWNhdGlvbkNvbXBhdC5CdWlsZGVyKGNvbnRleHQsIENIQU5ORUwpCiAgICAgICAgICAgIC5zZXRTbWFsbEljb24oUi5kcmF3YWJsZS5pY19zdGF0X25hbWUpCiAgICAgICAgICAgIC5zZXRDb250ZW50VGl0bGUodGl0bGUpCiAgICAgICAgICAgIC5zZXRDb250ZW50VGV4dChtZXNzYWdlKQogICAgICAgICAgICAuc2V0U3R5bGUoTm90aWZpY2F0aW9uQ29tcGF0LkJpZ1RleHRTdHlsZSgpLmJpZ1RleHQobWVzc2FnZSkpCiAgICAgICAgICAgIC5zZXRDb250ZW50SW50ZW50KHBlbmRpbmdJbnRlbnQpCiAgICAgICAgICAgIC5zZXRBdXRvQ2FuY2VsKHRydWUpCiAgICAgICAgICAgIC5idWlsZCgpCgogICAgICAgIG1hbmFnZXIubm90aWZ5KDcxMDEsIG5vdGlmaWNhdGlvbikKICAgICAgICBwcmVmcy5lZGl0KCkucHV0U3RyaW5nKCJsYXN0X25vdGljZSIsIGlkKS5hcHBseSgpCiAgICB9Cn0K"

def patch_build_gradle() -> None:
    path = APP / "build.gradle.kts"
    text = path.read_text(encoding="utf-8")
    text = re.sub(r'applicationId\s*=\s*"[^"]+"', f'applicationId = "{CONFIG["application_id"]}"', text, count=1)
    text = re.sub(r'versionCode\s*=\s*\d+', f'versionCode = {int(CONFIG["version_code"])}', text, count=1)
    text = re.sub(r'versionName\s*=\s*"[^"]+"', f'versionName = "{CONFIG["version_name"]}"', text, count=1)
    text = text.replace('v2rayNG_${variant.versionName}', 'BlueVPN_${variant.versionName}')
    api_value = CONFIG.get("api_base_url", "").rstrip("/")
    marker = f'applicationId = "{CONFIG["application_id"]}"'
    field = '\n        buildConfigField("String", "BLUEVPN_API_BASE_URL", "\\"' + api_value + '\\"")'
    if "BLUEVPN_API_BASE_URL" not in text:
        text = text.replace(marker, marker + field, 1)
    path.write_text(text, encoding="utf-8")


def _upsert_android_string(xml_text: str, name: str, value: str) -> str:
    """Replace a string resource if present; otherwise append it once."""
    escaped_value = escape(value)
    pattern = re.compile(
        rf'(<string\s+name="{re.escape(name)}"[^>]*>).*?(</string>)',
        flags=re.DOTALL,
    )

    if pattern.search(xml_text):
        return pattern.sub(
            lambda match: f"{match.group(1)}{escaped_value}{match.group(2)}",
            xml_text,
            count=1,
        )

    closing_tag = "</resources>"
    if closing_tag not in xml_text:
        raise RuntimeError("Invalid Android strings XML: </resources> not found")

    addition = f'    <string name="{name}">{escaped_value}</string>\n'
    return xml_text.replace(closing_tag, addition + closing_tag, 1)


def patch_strings() -> None:
    # Change the default app name.
    default_path = APP / "src/main/res/values/strings.xml"
    default_text = default_path.read_text(encoding="utf-8")
    default_text = _upsert_android_string(
        default_text,
        "app_name",
        CONFIG["app_name"],
    )
    default_path.write_text(default_text, encoding="utf-8")

    # v2rayNG already contains Persian resources. Edit the existing file
    # instead of defining the same resource names in a second XML file.
    fa_dir = APP / "src/main/res/values-fa"
    fa_dir.mkdir(parents=True, exist_ok=True)
    fa_path = fa_dir / "strings.xml"

    if fa_path.exists():
        fa_text = fa_path.read_text(encoding="utf-8")
    else:
        fa_text = '<?xml version="1.0" encoding="utf-8"?>\n<resources>\n</resources>\n'

    translations = {
        "app_widget_name": "اتصال سریع",
        "app_tile_name": "BlueVPN",
        "connection_connected": "متصل است؛ برای بررسی اتصال لمس کنید",
        "connection_not_connected": "اتصال برقرار نیست",
        "title_sub_setting": "مدیریت اشتراک",
        "title_sub_update": "به‌روزرسانی اشتراک",
        "import_subscription_success": "اشتراک با موفقیت افزوده شد",
        "import_subscription_failure": "افزودن اشتراک ناموفق بود",
    }

    for name, value in translations.items():
        fa_text = _upsert_android_string(fa_text, name, value)

    fa_path.write_text(fa_text, encoding="utf-8")

    # Remove the obsolete file created by the first BlueVPN script.
    duplicate_file = fa_dir / "bluevpn_strings.xml"
    if duplicate_file.exists():
        duplicate_file.unlink()

def patch_manifest() -> None:
    path = APP / "src/main/AndroidManifest.xml"
    text = path.read_text(encoding="utf-8")
    text = text.replace('android:scheme="v2rayng"', f'android:scheme="{CONFIG["deep_link_scheme"]}"')
    path.write_text(text, encoding="utf-8")

def patch_app_config() -> None:
    path = APP / "src/main/java/com/v2ray/ang/AppConfig.kt"
    text = path.read_text(encoding="utf-8")
    website = CONFIG.get("website_url", "").strip()
    support = CONFIG.get("support_url", "").strip()
    if website:
        text = re.sub(r'const val APP_URL = ".*?"', f'const val APP_URL = "{website}"', text, count=1)
    if support:
        text = re.sub(r'const val TG_CHANNEL_URL = ".*?"', f'const val TG_CHANNEL_URL = "{support}"', text, count=1)
    path.write_text(text, encoding="utf-8")

def inject_bootstrap() -> None:
    source_dir = APP / "src/main/java/com/v2ray/ang/bluevpn"
    source_dir.mkdir(parents=True, exist_ok=True)
    (source_dir / "BlueVpnBootstrap.kt").write_bytes(base64.b64decode(BOOTSTRAP_B64))

    app_path = APP / "src/main/java/com/v2ray/ang/AngApplication.kt"
    text = app_path.read_text(encoding="utf-8")
    if "import com.v2ray.ang.bluevpn.BlueVpnBootstrap" not in text:
        text = text.replace(
            "import com.v2ray.ang.handler.SettingsManager",
            "import com.v2ray.ang.handler.SettingsManager\nimport com.v2ray.ang.bluevpn.BlueVpnBootstrap",
            1,
        )
    if "BlueVpnBootstrap.start(this)" not in text:
        text = text.replace(
            "SettingsManager.initApp(this)",
            "SettingsManager.initApp(this)\n        BlueVpnBootstrap.start(this)",
            1,
        )
    app_path.write_text(text, encoding="utf-8")

def generate_icons() -> None:
    source = Image.open(ROOT / "branding/icon.png").convert("RGBA")
    sizes = {"mipmap-mdpi":48, "mipmap-hdpi":72, "mipmap-xhdpi":96, "mipmap-xxhdpi":144, "mipmap-xxxhdpi":192}
    res = APP / "src/main/res"
    for folder, size in sizes.items():
        target = res / folder
        target.mkdir(parents=True, exist_ok=True)
        square = source.resize((size, size), Image.Resampling.LANCZOS)
        square.save(target / "ic_launcher.png")
        square.save(target / "ic_launcher_round.png")
        source.resize((size * 2, size), Image.Resampling.LANCZOS).save(target / "ic_banner.png")
    adaptive_dir = res / "mipmap-anydpi-v26"
    if adaptive_dir.exists():
        for path in adaptive_dir.glob("ic_launcher*.xml"):
            path.unlink()
    source.resize((512, 512), Image.Resampling.LANCZOS).save(APP / "src/main/ic_launcher-web.png")

def add_source_notice() -> None:
    assets = APP / "src/main/assets"
    assets.mkdir(parents=True, exist_ok=True)
    (assets / "BLUEVPN_SOURCE.txt").write_text(
        "BlueVPN Android build is based on v2rayNG 2.2.6 under GNU GPL v3.\n"
        "Build scripts and modifications are in the BlueVPN repository.\n"
        "Upstream source: https://github.com/2dust/v2rayNG\n",
        encoding="utf-8",
    )

def main() -> None:
    if not APP.exists():
        raise RuntimeError("Upstream project not found at upstream/V2rayNG")
    patch_build_gradle()
    patch_strings()
    patch_manifest()
    patch_app_config()
    inject_bootstrap()
    generate_icons()
    add_source_notice()
    print("BlueVPN branding applied successfully.")
    print(f"Package: {CONFIG['application_id']}")
    print(f"Version: {CONFIG['version_name']} ({CONFIG['version_code']})")

if __name__ == "__main__":
    main()
