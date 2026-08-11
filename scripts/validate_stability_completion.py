from __future__ import annotations

import ast
import base64
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def require(ok: bool, message: str) -> None:
    if not ok:
        raise SystemExit(message)


def embedded(name: str) -> str:
    path = ROOT / "scripts" / "prepare_android.py"
    module = ast.parse(path.read_text(encoding="utf-8"))
    for node in module.body:
        if not isinstance(node, ast.Assign) or len(node.targets) != 1:
            continue
        target = node.targets[0]
        if isinstance(target, ast.Name) and target.id == name:
            return base64.b64decode(ast.literal_eval(node.value)).decode("utf-8")
    raise SystemExit(f"missing embedded source: {name}")


def main() -> None:
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding" / "app.json").read_text(encoding="utf-8"))
    plugin = (ROOT / "bluevpn-manager" / "bluevpn-manager.php").read_text(encoding="utf-8")
    version_match = re.search(r"define\('BLUEVPN_MANAGER_VERSION',\s*'([^']+)'\);", plugin)
    schema_match = re.search(r"define\('BLUEVPN_MANAGER_SCHEMA_VERSION',\s*'([^']+)'\);", plugin)
    require(version_match is not None, "plugin version constant is missing")
    require(schema_match is not None, "plugin schema version constant is missing")
    version = str(release.get("version", ""))
    require(version == str(app.get("version_name", "")) == version_match.group(1), "release/app/plugin versions are not synchronized")
    require(re.fullmatch(r"4\.\d+\.\d+", version) is not None, "release must stay in the v4 series")
    require(int(release.get("version_code", -1)) == int(app.get("version_code", -2)), "version codes are not synchronized")
    require(tuple(map(int, schema_match.group(1).split("."))) >= (1, 4, 1), "schema 1.4.1+ is required for SMS sending_started_at")

    activity = (ROOT / "android-source" / "BlueVpnSubscriptionsActivity.kt").read_text(encoding="utf-8")
    activity_checks = {
        "archive SMS/email switch": 'archiveSegment("پیامک"' in activity and 'archiveSegment("ایمیل"' in activity,
        "six digit OTP boxes": "archiveOtpRow" in activity and "InputFilter.LengthFilter(1)" in activity,
        "static first frame": "window.setWindowAnimations(0)" in activity and "BlueVpnDynamicBackgroundView(this)" not in activity,
        "render generation": "renderGeneration" in activity and "generation!=renderGeneration" in activity,
        "UI guard": 'BlueVpnUiGuard.run(this,"render-account")' in activity,
        "sync coalescing": "syncInProgress" in activity and "if(syncInProgress)return" in activity,
        "draft-safe sync": "(materiallyChanged||force)&&currentFocus !is EditText" in activity,
        "handler cleanup": "handler.removeCallbacksAndMessages(null)" in activity,
        "logout exits screen": 'button("خروج از حساب"' in activity and "finish()" in activity,
        "float line spacing": "setLineSpacing(0f,1.18f)" in activity,
        "safe email password listener": 'val password=archiveInput("حداقل ۸ کاراکتر"' in activity and "setOnEditorActionListener{" in activity,
    }
    failed = [name for name, ok in activity_checks.items() if not ok]
    require(not failed, "Android account stability checks failed: " + ", ".join(failed))
    require(embedded("BLUEVPN_SUBSCRIPTIONS_ACTIVITY_B64") == activity, "embedded subscriptions source differs from canonical snapshot")

    db = (ROOT / "bluevpn-manager" / "includes" / "class-bluevpn-db.php").read_text(encoding="utf-8")
    sms = (ROOT / "bluevpn-manager" / "includes" / "class-bluevpn-sms-notifications.php").read_text(encoding="utf-8")
    payments = (ROOT / "bluevpn-manager" / "includes" / "class-bluevpn-payments.php").read_text(encoding="utf-8")
    auth = (ROOT / "bluevpn-manager" / "includes" / "class-bluevpn-auth.php").read_text(encoding="utf-8")
    api = (ROOT / "bluevpn-manager" / "includes" / "class-bluevpn-api.php").read_text(encoding="utf-8")

    sms_checks = {
        "sending_started_at schema": "sending_started_at datetime NULL" in db,
        "sending timestamp is recorded": "'sending_started_at'=>BlueVPN_Utils::now_mysql()" in sms,
        "stale recovery uses sending_started_at": "sending_started_at IS NOT NULL AND sending_started_at<%s" in sms and "status='sending' AND created_at<%s" not in sms,
        "transaction-friendly queue": "bool $kick = true" in sms and "wake_queue" in sms,
        "invoice reconciliation": "invoice-created:" in sms and "payment-success:" in sms,
        "subscription reconciliation": "SELECT COUNT(*)" in sms and "subscription_renewed" in sms and "subscription_upgraded" in sms,
        "zero-var payload object": "'attributes'=>$params ? $params : (object)[]" in sms,
        "dynamic BluePay user agent": "'User-Agent'=>'BlueVPN-WordPress/'.BLUEVPN_MANAGER_VERSION" in payments,
        "invoice DB transaction": "transactional invoice SMS" in payments and "START TRANSACTION" in payments,
        "activation DB transaction": "transactional activation SMS" in payments and "$smsJobs" in payments,
    }
    failed = [name for name, ok in sms_checks.items() if not ok]
    require(not failed, "SMS/outbox stability checks failed: " + ", ".join(failed))

    account_checks = {
        "email preserved with phone": "$email = (string)($c['email'] ?? '');" in auth,
        "entitlement active computed": "'entitlement_active' => $entitlementActive" in auth,
        "entitlement order exposed": "'entitlement_order_id' => $entitlementOrderId" in auth,
        "entitlement plan exposed": "'entitlement_plan_id' => $entitlementPlanId" in auth,
        "email password advertised": "'password_login'=>true" in api and "'email_login'=>true" in api and "'email_registration'=>true" in api,
        "dual auth mode": "phone_otp_or_email_password" in api,
    }
    failed = [name for name, ok in account_checks.items() if not ok]
    require(not failed, "account contract checks failed: " + ", ".join(failed))

    print(f"BlueVPN stability completion validation passed for {version}")


if __name__ == "__main__":
    main()
