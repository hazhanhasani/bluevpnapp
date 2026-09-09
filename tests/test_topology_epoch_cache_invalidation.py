from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class TopologyEpochCacheInvalidationTests(unittest.TestCase):
    def text(self, path):
        return (ROOT / path).read_text(encoding="utf-8")

    def test_plugin_loads_topology_epoch_guard(self):
        bootstrap = self.text("bluevpn-manager/bluevpn-manager.php")
        self.assertIn("class-bluevpn-topology-epoch.php", bootstrap)
        self.assertIn("BlueVPN_Topology_Epoch::init();", bootstrap)

    def test_authoritative_mutations_advance_epoch(self):
        src = self.text("bluevpn-manager/includes/class-bluevpn-topology-epoch.php")
        for token in [
            "bluevpn_cc_delete_subscription_source",
            "bluevpn_cc_toggle_subscription_source",
            "bluevpn_cc_delete_provider",
            "bluevpn_shahrah_delete",
            "bluevpn_free_source_delete",
            "bluevpn_free_source_toggle",
            "bluevpn_cc_save_plan_routing",
        ]:
            self.assertIn(token, src)

    def test_account_pool_identity_contains_epoch(self):
        src = self.text("bluevpn-manager/includes/class-bluevpn-topology-epoch.php")
        self.assertIn("topology-epoch:", src)
        self.assertIn("pool_identity", src)
        self.assertIn("topology_epoch", src)
        self.assertIn("X-BlueVPN-Topology-Epoch", src)

    def test_existing_empty_topology_remains_authoritative(self):
        src = self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        self.assertIn("$declaredTopology=$rawText!==''||$sourceText!==''", src)
        self.assertIn("array_intersect_key($oldSourceLines,$currentSourceKeys)", src)
        self.assertIn("array_intersect_key($oldSourceStats,$currentSourceKeys)", src)


if __name__ == "__main__":
    unittest.main()
