from __future__ import annotations

import ast
import base64
import json
import re
import tempfile
import xml.etree.ElementTree as ET
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PREPARE = ROOT / "scripts/prepare_android.py"


def assignments() -> dict[str, str]:
    module = ast.parse(PREPARE.read_text(encoding="utf-8"))
    values: dict[str, str] = {}
    for node in module.body:
        if not isinstance(node, ast.Assign) or len(node.targets) != 1:
            continue
        target = node.targets[0]
        if not isinstance(target, ast.Name) or not target.id.endswith("_B64"):
            continue
        value = ast.literal_eval(node.value)
        values[target.id] = base64.b64decode(value).decode("utf-8")
    return values


def main() -> None:
    values = assignments()
    required = {
        "BLUEVPN_HOME_ACTIVITY_B64",
        "BLUEVPN_UPDATE_MANAGER_B64",
        "BLUEVPN_IDS_B64",
        "BLUEVPN_SCREEN_BACKGROUND_B64",
        "BLUEVPN_AI_MANAGER_B64",
        "BLUEVPN_LIVE_REPORTER_B64",
        "BLUEVPN_ACCOUNT_MANAGER_B64",
        "BLUEVPN_SUBSCRIPTIONS_ACTIVITY_B64",
    }
    missing = required.difference(values)
    if missing:
        raise SystemExit(f"missing embedded sources: {sorted(missing)}")

    for name, text in values.items():
        if text.lstrip().startswith("<?xml"):
            ET.fromstring(text)

    home = values["BLUEVPN_HOME_ACTIVITY_B64"]
    checks = {
        "programmatic createScreen": "private fun createScreen(): View" in home,
        "programmatic setContentView": "setContentView(createScreen())" in home,
        "no XML inflation": "setContentView(R.layout.activity_bluevpn_home)" not in home,
        "neon orb": "private fun applyOrbVisual" in home,
        "pulse animator": "ValueAnimator.ofFloat(0f, 1f)" in home,
        "floating stats": "createFloatingStatCard" in home,
        "connected state": "OrbVisualState.CONNECTED" in home,
        "error state": "OrbVisualState.ERROR" in home,
        "submit compile fix": "completion.submit<" not in home and "completion.submit {" in home,
        "no core-only live fallback":
            "Compatibility fallback: other clients accept the config" not in home
            and "هسته Xray متصل است؛ سایت‌های تست عمومی پاسخ ندادند" not in home,
        "strict remote proof":
            "bluevpn-platform" in home
            and 'endpoint.contains("generate_204")' in home,
    }
    failed = [label for label, ok in checks.items() if not ok]
    if failed:
        raise SystemExit("failed checks: " + ", ".join(failed))

    ai = values["BLUEVPN_AI_MANAGER_B64"]
    reporter = values["BLUEVPN_LIVE_REPORTER_B64"]
    live_checks = {
        "VPN transport required":
            "fun hasVpnTransport" in ai,
        "remote tunnel proof":
            "fun verifyTunnel" in ai
            and "bluevpn-health" in ai
            and "cloudflare-204" in ai,
        "heartbeat proof fields":
            '.put("internet_verified", true)' in ai
            and '.put("verification_source", verification.source)' in ai,
        "background reporter":
            "scheduleWithFixedDelay" in reporter
            and "BlueVpnAi.heartbeat" in reporter,
    }
    live_failed = [label for label, ok in live_checks.items() if not ok]
    if live_failed:
        raise SystemExit(
            "failed live-connection checks: " + ", ".join(live_failed)
        )

    account_manager = values["BLUEVPN_ACCOUNT_MANAGER_B64"]
    subscriptions = values["BLUEVPN_SUBSCRIPTIONS_ACTIVITY_B64"]
    checkout_checks = {
        "persistent browser return marker":
            "markCheckoutBrowserOpen" in account_manager
            and "consumeCheckoutBrowserOrder" in account_manager,
        "checkout close API":
            "/checkout/close" in account_manager
            and "fun closeCheckout" in account_manager,
        "return closes checkout":
            "closeCheckoutAfterReturn" in subscriptions
            and "checkoutBrowserOrder" in subscriptions
            and "clearCheckoutBrowserOrder" in subscriptions,
        "close retry":
            "attempt<3" in subscriptions
            and "postDelayed" in subscriptions,
        "five minute status":
            "۵ دقیقه" in subscriptions,
        "abandoned invoice handling":
            '"abandoned"' in subscriptions,
    }
    checkout_failed = [label for label, ok in checkout_checks.items() if not ok]
    if checkout_failed:
        raise SystemExit(
            "failed checkout lifecycle checks: " + ", ".join(checkout_failed)
        )

    updater = values["BLUEVPN_UPDATE_MANAGER_B64"]
    updater_checks = {
        "physical network permission injector":
            "android.permission.CHANGE_NETWORK_STATE" in PREPARE.read_text(encoding="utf-8"),
        "EPERM fallback detection":
            '"eperm" in message' in updater,
        "binding failure fallback detection":
            '"binding socket to network" in message' in updater,
        "default route retry":
            "target.openConnection() as HttpURLConnection" in updater,
        "friendly updater errors":
            "private fun friendlyDownloadError" in updater,
        "no raw error dialog":
            "message = friendlyDownloadError(error)" in updater,
        "APK zip validation":
            'zip.getEntry("AndroidManifest.xml")' in updater,
        "APK package validation":
            "getPackageArchiveInfo" in updater,
        "SHA-256 validation":
            'MessageDigest.getInstance("SHA-256")' in updater,
        "expected asset size validation":
            "KEY_UPDATE_SIZE" in updater and "expectedSize" in updater,
        "MIUI explicit URI grant":
            "context.grantUriPermission(" in updater,
        "ClipData URI propagation":
            "ClipData.newRawUri(" in updater,
        "install package action":
            "Intent.ACTION_INSTALL_PACKAGE" in updater,
        "installer fallback":
            "context.startActivity(fallback)" in updater,
        "data and MIME preserved together":
            "setDataAndType(" in updater,
    }
    updater_failed = [label for label, ok in updater_checks.items() if not ok]
    if updater_failed:
        raise SystemExit(
            "failed updater checks: " + ", ".join(updater_failed)
        )

    required_ids = set(re.findall(r"R\.id\.(bluevpn_[A-Za-z0-9_]+)", home))
    expected_ids = {
        "bluevpn_action_servers",
        "bluevpn_action_settings",
        "bluevpn_action_subscription",
        "bluevpn_active_routes_value",
        "bluevpn_ai_card",
        "bluevpn_ai_summary",
        "bluevpn_connect_button",
        "bluevpn_download_speed",
        "bluevpn_duration_value",
        "bluevpn_history_value",
        "bluevpn_location_value",
        "bluevpn_mode_balanced",
        "bluevpn_mode_gaming",
        "bluevpn_mode_streaming",
        "bluevpn_mode_value",
        "bluevpn_ping_value",
        "bluevpn_premium_badge",
        "bluevpn_quality_value",
        "bluevpn_refresh_subscription",
        "bluevpn_remaining_time",
        "bluevpn_remaining_volume",
        "bluevpn_server_card",
        "bluevpn_server_meta",
        "bluevpn_server_name",
        "bluevpn_status_caption",
        "bluevpn_status_dot",
        "bluevpn_status_text",
        "bluevpn_subscription_summary",
        "bluevpn_upload_speed",
    }
    if not expected_ids.issubset(required_ids):
        raise SystemExit(f"missing IDs: {sorted(expected_ids - required_ids)}")

    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    branding = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    if release["version"] != branding["version_name"]:
        raise SystemExit("version_name mismatch")
    if int(release["version_code"]) != int(branding["version_code"]):
        raise SystemExit("version_code mismatch")

    with tempfile.TemporaryDirectory() as tmp:
        generated = Path(tmp) / "BlueVpnHomeActivity.kt"
        generated.write_text(home, encoding="utf-8")
        if generated.stat().st_size < 50_000:
            raise SystemExit("generated activity unexpectedly small")

    print("Generated Android validation passed")
    print(f"Version: {release['version']} ({release['version_code']})")
    print(f"Embedded sources: {len(values)}")
    print(f"Home IDs: {len(required_ids)}")


if __name__ == "__main__":
    main()
