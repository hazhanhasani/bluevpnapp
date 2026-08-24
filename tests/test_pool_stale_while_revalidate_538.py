import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]


class PoolStaleWhileRevalidate538Tests(unittest.TestCase):
    def test_overlay_tolerates_upstream_main_view_model_relocation(self):
        source = (ROOT / "scripts/prepare_android.py").read_text()
        self.assertIn("MANDATORY_UPSTREAM_RUNTIME_GUARD", source)
        self.assertIn("if relative in MANDATORY_UPSTREAM_RUNTIME_GUARD", source)
        self.assertIn("continue", source)
        self.assertNotIn('raise RuntimeError(f"Pinned v2rayNG runtime file is missing', source)

    def test_manifest_launcher_is_resolved_structurally(self):
        source = (ROOT / "scripts/prepare_android.py").read_text()
        self.assertIn('for tag in ("activity", "activity-alias")', source)
        self.assertIn('"android.intent.category.LAUNCHER"', source)
        self.assertIn('application.append(node)', source)
        self.assertNotIn('Could not replace the MainActivity launcher block', source)

    def test_premium_readiness_accepts_same_entitlement_lkg(self):
        source = (ROOT / "android-source/BlueVpnAccountManager.kt").read_text()
        block = source.split("fun hasUsableCurrentEntitlementPool", 1)[1].split("fun entitlementSubscriptionGuids", 1)[0]
        self.assertIn("premiumEntitlementActive(c) -> preferredServerGuids(c).isNotEmpty()", block)

    def test_location_cache_survives_normal_resume_and_is_identity_scoped(self):
        source = (ROOT / "android-source/BlueVpnLocationUtil.kt").read_text()
        self.assertIn("CONTEXT_STALE_GRACE_MS = 24L * 60L * 60L * 1_000L", source)
        self.assertIn("contextCandidateCacheKey == cacheKey", source)
        self.assertIn("entitlementIdentityFingerprint(context)", source)

    def test_locations_no_longer_claims_it_is_waiting_on_premium_pool(self):
        source = (ROOT / "android-source/BlueVpnServersActivity.kt").read_text()
        self.assertIn("در حال آماده‌سازی اولین فهرست مکان‌ها", source)
        self.assertNotIn("در حال خواندن Pool اختصاصی Premium", source)


if __name__ == "__main__":
    unittest.main()
