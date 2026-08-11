from __future__ import annotations

import ast
import base64
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def text(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def embedded(name: str) -> str:
    module = ast.parse(text("scripts/prepare_android.py"))
    for node in module.body:
        if not isinstance(node, ast.Assign) or len(node.targets) != 1:
            continue
        target = node.targets[0]
        if isinstance(target, ast.Name) and target.id == name:
            return base64.b64decode(ast.literal_eval(node.value)).decode("utf-8")
    raise AssertionError(name)


def test_current_v4_metadata_is_synchronized():
    release = json.loads(text("release.json"))
    app = json.loads(text("branding/app.json"))
    plugin = text("bluevpn-manager/bluevpn-manager.php")
    version = re.search(r"BLUEVPN_MANAGER_VERSION', '([^']+)'", plugin).group(1)
    assert release["version"] == app["version_name"] == version
    assert release["version_code"] == app["version_code"]
    assert version.startswith("4.")
    assert "BLUEVPN_MANAGER_SCHEMA_VERSION', '1.4.1'" in plugin


def test_archive_auth_keeps_stability_guards():
    activity = text("android-source/BlueVpnSubscriptionsActivity.kt")
    assert 'archiveSegment("پیامک"' in activity
    assert 'archiveSegment("ایمیل"' in activity
    assert "archiveOtpRow" in activity
    assert "setLineSpacing(0f,1.18f)" in activity
    assert "window.setWindowAnimations(0)" in activity
    assert "BlueVpnDynamicBackgroundView(this)" not in activity
    assert "renderGeneration" in activity
    assert "generation!=renderGeneration" in activity
    assert "syncInProgress" in activity
    assert "(materiallyChanged||force)&&currentFocus !is EditText" in activity
    logout = activity[activity.index('button("خروج از حساب"'): activity.index("private fun phoneBindingCard")]
    assert "finish()" in logout
    assert "recreate()" not in logout


def test_android_generator_and_snapshot_are_identical():
    activity = text("android-source/BlueVpnSubscriptionsActivity.kt")
    assert embedded("BLUEVPN_SUBSCRIPTIONS_ACTIVITY_B64") == activity


def test_sms_queue_has_safe_stale_recovery_and_durable_reconciliation():
    db = text("bluevpn-manager/includes/class-bluevpn-db.php")
    sms = text("bluevpn-manager/includes/class-bluevpn-sms-notifications.php")
    payments = text("bluevpn-manager/includes/class-bluevpn-payments.php")
    assert "sending_started_at datetime NULL" in db
    assert "sending_started_at IS NOT NULL AND sending_started_at<%s" in sms
    assert "status='sending' AND created_at<%s" not in sms
    assert "bool $kick = true" in sms
    assert "invoice-created:" in sms
    assert "payment-success:" in sms
    assert "transactional invoice SMS" in payments
    assert "transactional activation SMS" in payments
    assert "BlueVPN-WordPress/'.BLUEVPN_MANAGER_VERSION" in payments


def test_account_contract_exposes_real_entitlement_and_dual_auth():
    auth = text("bluevpn-manager/includes/class-bluevpn-auth.php")
    api = text("bluevpn-manager/includes/class-bluevpn-api.php")
    assert "'entitlement_active' => $entitlementActive" in auth
    assert "'entitlement_order_id' => $entitlementOrderId" in auth
    assert "'entitlement_plan_id' => $entitlementPlanId" in auth
    assert "$email = (string)($c['email'] ?? '');" in auth
    assert "phone_otp_or_email_password" in api
    assert "'password_login'=>true" in api
    assert "'email_login'=>true" in api


def test_ci_runs_stability_gate_before_gradle():
    workflow = text(".github/workflows/build-apk.yml")
    gate = workflow.index("BlueVPN stability completion regression gate")
    gradle = workflow.index("Build unsigned release APKs")
    assert gate < gradle
    assert "python scripts/validate_generated_android.py" in workflow
    assert "python scripts/validate_stability_completion.py" in workflow
