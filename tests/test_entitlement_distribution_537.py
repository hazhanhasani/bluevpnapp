import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]


class EntitlementDistribution537Tests(unittest.TestCase):
    def text(self, path):
        return (ROOT / path).read_text(encoding="utf-8")

    def test_windows_consumes_panel_multi_source_contract(self):
        models = self.text("bluevpn-windows/Models/WindowsRuntimeModels.cs")
        api = self.text("bluevpn-windows/Services/BlueVpnApiClient.cs")
        connection = self.text("bluevpn-windows/Services/ConnectionOrchestrator.cs")
        self.assertIn('[JsonPropertyName("subscriptions")]', models)
        self.assertIn("List<FreeSubscriptionSource>", models)
        self.assertIn("policy?.Subscriptions", api)
        self.assertIn(".Take(100)", api)
        self.assertIn("new SemaphoreSlim(8)", api)
        self.assertIn("Task.WhenAll(tasks)", api)
        self.assertIn("GetFreeSubscriptionAsync(free, ct)", connection)

    def test_paid_snapshot_is_last_known_good_per_source(self):
        providers = self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        for token in ("'source_lines'=>$sourceLines", "$freshSourceLines", "$oldSourceLines", "$effectiveSourceLines"):
            self.assertIn(token, providers)
        self.assertIn("array_merge($oldSourceLines,$freshSourceLines)", providers)
        self.assertNotIn("$effective=$complete||empty($old['lines'])?$lines:(array)$old['lines']", providers)

    def test_every_paid_subscription_path_checks_entitlement(self):
        providers = self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        serve = providers.split("public static function serve_subscription(): void", 1)[1]
        self.assertIn("subscription_entitlement_allows($c)", serve)
        for token in ("subscription_expire", "data_limit_bytes", "used_traffic_bytes", "last_sync_error"):
            self.assertIn(token, serve)


if __name__ == "__main__":
    unittest.main()
