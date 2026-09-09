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


if __name__ == "__main__":
    unittest.main()
