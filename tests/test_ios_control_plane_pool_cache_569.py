import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]


class IOSControlPlanePoolCache569Tests(unittest.TestCase):
    def text(self, path):
        return (ROOT / path).read_text(encoding="utf-8")

    def test_control_plane_exposes_free_and_premium_sources(self):
        models = self.text("bluevpn-ios/BlueVPNApp/Models.swift")
        store = self.text("bluevpn-ios/BlueVPNApp/BlueVPNStore.swift")
        self.assertIn('case freeAccess="free_access"', models)
        self.assertIn('poolIdentity="pool_identity"', models)
        self.assertIn("payload.freeAccess?.sources", store)
        self.assertIn("account.subscriptionURL", store)

    def test_pool_cache_is_stale_while_revalidate_and_health_ranked(self):
        pool = self.text("bluevpn-ios/BlueVPNApp/PoolCoordinator.swift")
        self.assertIn("pool-cache-v1.json", pool)
        self.assertIn("Task{try? await self.refresh", pool)
        self.assertIn("staleTTL", pool)
        self.assertIn("lastLatencyMS", pool)
        self.assertIn("lastFailureAt", pool)

    def test_tunnel_receives_cached_subscription_not_display_only_url(self):
        manager = self.text("bluevpn-ios/BlueVPNApp/VPNManager.swift")
        tunnel = self.text("bluevpn-ios/PacketTunnel/PacketTunnelProvider.swift")
        self.assertIn('"subscription_text": subscriptionText', manager)
        self.assertIn('configuration["subscription_text"]', tunnel)
        self.assertIn("runtime.convertSubscription(value)", tunnel)

    def test_connected_state_requires_real_egress_change(self):
        manager = self.text("bluevpn-ios/BlueVPNApp/VPNManager.swift")
        store = self.text("bluevpn-ios/BlueVPNApp/BlueVPNStore.swift")
        self.assertIn("baselineIP", manager)
        self.assertIn("current != baselineIP", manager)
        self.assertLess(store.index("try await vpn.verifyRealEgress()"), store.index("state = .connected"))

    def test_pool_download_rejects_local_network_hosts(self):
        api = self.text("bluevpn-ios/BlueVPNApp/APIClient.swift")
        self.assertIn('host=="localhost"', api)
        self.assertIn('"192.168."', api)
        self.assertIn('url.scheme=="https"', api)


if __name__ == "__main__":
    unittest.main()
