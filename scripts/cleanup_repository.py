#!/usr/bin/env python3
from pathlib import Path
import shutil

ROOT = Path(__file__).resolve().parents[1]

RETIRED_FILES = [
    "android-source/BlueVpnEngineManager.kt",
    "android-source/BlueVpnSingBoxProcess.kt",
    "android-source/BlueVpnSingBoxProfileCompiler.kt",
    "android-source/BlueVpnAiActivity.kt",
]
RETIRED_DIRS = [
    "android-source/generated",
]

# Legacy regression modules from the retired Railway/PostgreSQL control-plane.
# GitHub ZIP overlays do not delete repository files, so these modules can linger
# in a long-lived repository and be rediscovered by ``unittest discover`` even
# though their application packages were intentionally removed during the
# WordPress/MySQL migration. Keep this list explicit: never blanket-delete tests.
RETIRED_TESTS = [
    "test_blueai_scoring.py",
    "test_blueai_submit_event.py",
    "test_bluepanel_sms_center_v332.py",
    "test_bluepay_invalid_invoice_purge_v320.py",
    "test_bluepay_official_contract_v374.py",
    "test_bluepay_payment_runtime_v378.py",
    "test_checkout_lifecycle_v318.py",
    "test_database_fk_migration_regression.py",
    "test_email_global_sub_v335.py",
    "test_entitlement_hot_swap_v371.py",
    "test_expiry_regression_v323.py",
    "test_farazsms_502_resilience_v354.py",
    "test_farazsms_catalog_sync_v358.py",
    "test_farazsms_pattern_pagination_v360.py",
    "test_farazsms_shared_sender_v353.py",
    "test_iranpayamak_v355.py",
    "test_iranpayamak_validation_v357.py",
    "test_jalali_tehran_v321.py",
    "test_live_connections_v317.py",
    "test_locations_bluepay_recovery_v372.py",
    "test_payment_expiry_v315.py",
    "test_pending_orders_v316.py",
    "test_phone_otp_v325.py",
    "test_runtime_pool_bluepay_recovery_v376.py",
    "test_safe_ad_render_v348.py",
    "test_subscription_entitlement_detection_v324.py",
    "test_subscription_recovery_v322.py",
    "test_updater_release_metadata.py",
]

removed = []
for name in RETIRED_TESTS:
    path = ROOT / "tests" / name
    if path.exists() or path.is_symlink():
        path.unlink()
        removed.append(f"tests/{name}")

for rel in RETIRED_FILES:
    path = ROOT / rel
    if path.exists() or path.is_symlink():
        path.unlink()
        removed.append(rel)

for rel in RETIRED_DIRS:
    path = ROOT / rel
    if path.exists():
        shutil.rmtree(path)
        removed.append(rel + "/")

print("BlueVPN repository workspace cleanup complete.")
if removed:
    print("Removed retired paths:")
    for rel in removed:
        print(f"- {rel}")
else:
    print("No retired paths were present.")
