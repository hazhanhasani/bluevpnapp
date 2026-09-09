from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class SourceLifecycle60303Tests(unittest.TestCase):
    def text(self, path):
        return (ROOT / path).read_text(encoding="utf-8")

    def test_explicit_empty_topology_is_authoritative(self):
        src = self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        for token in [
            "$declaredTopology=$rawText!==''||$sourceText!==''",
            "if(!$declaredTopology)",
            "if(!$hasExplicit&&!$topologyDeclared)",
            "if(!$hasExplicitManual&&!$topologyDeclared)",
        ]:
            self.assertIn(token, src)

    def test_removed_source_lkg_is_pruned(self):
        src = self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        for token in [
            "array_intersect_key($oldSourceLines,$currentSourceKeys)",
            "array_intersect_key($oldSourceStats,$currentSourceKeys)",
            "self::snapshot_store($customerId,$effective",
        ]:
            self.assertIn(token, src)

    def test_lifecycle_covers_reported_paths(self):
        src = self.text("bluevpn-manager/includes/class-bluevpn-source-lifecycle.php")
        for token in [
            "before_customer_sync",
            "before_plan_save",
            "before_paid_source_mutation",
            "before_shahrah_delete",
            "before_provider_mutation",
            "before_free_toggle",
            "repair_customer_missing_providers($id)",
            "bluevpn_shahrah_panel_",
            "invalidate_all",
        ]:
            self.assertIn(token, src)

    def test_free_source_can_remain_explicitly_empty(self):
        self.assertIn(
            "bluevpn_free_sources_initialized",
            self.text("bluevpn-manager/includes/class-bluevpn-free-sources.php"),
        )

    def test_topology_epoch_guard_is_loaded(self):
        bootstrap = self.text("bluevpn-manager/bluevpn-manager.php")
        self.assertIn("class-bluevpn-topology-epoch.php", bootstrap)
        self.assertIn("BlueVPN_Topology_Epoch::init();", bootstrap)

    def test_authoritative_topology_mutations_advance_epoch(self):
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

    def test_account_pool_identity_contains_topology_epoch(self):
        src = self.text("bluevpn-manager/includes/class-bluevpn-topology-epoch.php")
        for token in [
            "topology-epoch:",
            "pool_identity",
            "topology_epoch",
            "X-BlueVPN-Topology-Epoch",
        ]:
            self.assertIn(token, src)


if __name__ == "__main__":
    unittest.main()
