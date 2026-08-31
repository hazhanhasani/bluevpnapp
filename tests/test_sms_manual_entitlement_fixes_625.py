from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class SmsManualEntitlementFixes625Tests(unittest.TestCase):
    def text(self, path):
        return (ROOT / path).read_text(encoding="utf-8")

    def test_sms_worker_has_immediate_wake_hook_not_only_recurring_cron(self):
        sms = self.text("bluevpn-manager/includes/class-bluevpn-sms-notifications.php")
        self.assertIn("public const HOOK_WAKE = 'bluevpn_sms_wake_queue';", sms)
        self.assertIn("add_action(self::HOOK_WAKE, [self::class, 'cron_process']);", sms)
        self.assertIn("wp_schedule_single_event(time(), self::HOOK_WAKE)", sms)
        self.assertIn("spawn_cron(time())", sms)
        self.assertIn("self::process(24)", sms)

    def test_user_visible_sms_can_queue_and_dispatch_foreground(self):
        sms = self.text("bluevpn-manager/includes/class-bluevpn-sms-notifications.php")
        payments = self.text("bluevpn-manager/includes/class-bluevpn-payments.php")
        self.assertIn("public static function queue_and_dispatch(", sms)
        self.assertIn("$result=self::dispatch_now($id);", sms)
        self.assertIn("$wpdb->query('COMMIT');", payments)
        commit_at = payments.index("$wpdb->query('COMMIT');")
        dispatch_at = payments.index("BlueVPN_SMS_Notifications::dispatch_now($deliveryId)")
        self.assertLess(commit_at, dispatch_at)
        self.assertIn("BlueVPN post-commit SMS dispatch", payments)

    def test_manual_customer_history_never_searches_plaintext_inside_hashed_dedupe(self):
        manual = self.text("bluevpn-manager/includes/class-bluevpn-manual-customers.php")
        self.assertIn("private static function backfill_legacy_sms_owners()", manual)
        self.assertIn("HAVING COUNT(*)=1", manual)
        self.assertIn("d.customer_id IS NULL", manual)
        self.assertNotIn("dedupe_key LIKE 'manual-customer:%'", manual)
        self.assertNotIn("dedupe_key LIKE %s", manual)
        self.assertIn("BlueVPN_SMS_Notifications::dispatch_now($deliveryId)", manual)

    def test_manual_app_activation_plan_is_optional_for_custom_on_existing_plan(self):
        cc = self.text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        tab_start = cc.index("private static function tab_manual()")
        tab_end = cc.index("private static function tab_customers()", tab_start)
        tab = cc[tab_start:tab_end]
        self.assertIn("پلن (اختیاری)", tab)
        self.assertIn("استفاده از پلن فعلی کاربر", tab)
        self.assertNotIn('name="plan_id" required', tab)

        start = cc.index("public static function manual_activate()")
        end = cc.index("public static function guardcore_refresh_catalog", start)
        body = cc[start:end]
        self.assertIn("$requestedPlanId", body)
        self.assertIn("$planId=$requestedPlanId>0?$requestedPlanId:max(0,(int)($before['plan_id']??0));", body)
        self.assertIn("$requestedPlanId<=0&&!$customDuration&&!$customVolume", body)
        self.assertIn("پلن فعلی خودکار استفاده شد", body)

    def test_active_customer_entitlement_can_be_edited_absolutely(self):
        cc = self.text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        self.assertIn("'bluevpn_cc_update_customer_entitlement'=>'update_customer_entitlement'", cc)
        start = cc.index("public static function update_customer_entitlement()")
        end = cc.index("public static function manual_activate()", start)
        body = cc[start:end]
        self.assertIn("absolute_expire_date", body)
        self.assertIn("absolute_data_limit_gb", body)
        self.assertIn("mysql_from_tehran_date", body)
        self.assertIn("$targetBytes=(int)round($volumeGb*1024*1024*1024)", body)
        self.assertIn("provision_customer($customerId,$planId,$targetExpiry,$targetBytes)", body)
        self.assertIn("admin_entitlement_adjustment", body)
        self.assertNotIn("$base+$durationDays*DAY_IN_SECONDS", body)

    def test_entitlement_edit_does_not_reset_usage(self):
        cc = self.text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        start = cc.index("public static function update_customer_entitlement()")
        end = cc.index("public static function manual_activate()", start)
        body = cc[start:end]
        self.assertNotIn("'used_traffic_bytes'=>0", body)
        self.assertIn("مصرف فعلی Reset نمی‌شود", cc)


if __name__ == "__main__":
    unittest.main()
