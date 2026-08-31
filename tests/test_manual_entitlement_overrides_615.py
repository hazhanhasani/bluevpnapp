from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class ManualEntitlementOverrides615Tests(unittest.TestCase):
    def test_admin_forms_expose_optional_custom_duration_and_volume(self):
        cc = (ROOT / "bluevpn-manager/includes/class-bluevpn-control-center.php").read_text(encoding="utf-8")
        self.assertGreaterEqual(cc.count('name="custom_duration_days"'), 2)
        self.assertGreaterEqual(cc.count('name="custom_data_limit_gb"'), 2)
        self.assertIn("خالی = مدت پلن", cc)
        self.assertIn("خالی = حجم پلن", cc)
        self.assertIn("۰ = نامحدود", cc)

    def test_manual_activation_extends_from_current_expiry_and_passes_quota_override(self):
        cc = (ROOT / "bluevpn-manager/includes/class-bluevpn-control-center.php").read_text(encoding="utf-8")
        start = cc.index("public static function manual_activate()")
        end = cc.index("public static function guardcore_refresh_catalog", start)
        body = cc[start:end]
        for token in (
            "custom_duration_days",
            "custom_data_limit_gb",
            "$base=max(time(),$previousExpiryTs);",
            "$base+$durationDays*DAY_IN_SECONDS",
            "$customDataLimitBytes=(int)round($volumeGb*1024*1024*1024)",
            "provision_customer($customerId,$planId,$targetExpiry,$providerDataLimitOverride)",
            "'custom_duration'=>$customDuration?1:0",
            "'custom_data_limit'=>$customVolume?1:0",
        ):
            self.assertIn(token, body)

    def test_provider_provision_uses_manual_quota_when_present(self):
        providers = (ROOT / "bluevpn-manager/includes/class-bluevpn-providers.php").read_text(encoding="utf-8")
        start = providers.index("public static function provision_customer(")
        end = providers.index("public static function request_background_sync", start)
        body = providers[start:end]
        self.assertIn("?int $overrideDataLimitBytes=null", body)
        self.assertIn("$overrideDataLimitBytes!==null", body)
        self.assertIn("max(0,$overrideDataLimitBytes)", body)
        self.assertIn("'data_limit_source'=>$overrideDataLimitBytes!==null?'manual_override':'plan'", body)
        self.assertIn("'data_limit_bytes'=>$total", body)


if __name__ == "__main__":
    unittest.main()
