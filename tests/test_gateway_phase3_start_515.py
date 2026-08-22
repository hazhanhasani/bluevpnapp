import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

class GatewayPhase3Start515Tests(unittest.TestCase):
    def text(self,rel):
        return (ROOT/rel).read_text(encoding="utf-8")

    def test_phase3_class_is_loaded_and_initialized(self):
        plugin=self.text("bluevpn-manager/bluevpn-manager.php")
        self.assertIn("class-bluevpn-gateway-phase3.php",plugin)
        self.assertIn("BlueVPN_Gateway_Phase3::init();",plugin)

    def test_phase3_is_observational_and_health_aware(self):
        phase3=self.text("bluevpn-manager/includes/class-bluevpn-gateway-phase3.php")
        for token in (
            "score_node",
            "phase3_health_score",
            "last_pending_events",
            "last_load1",
            "last_active_sessions",
            "max_sessions",
            "priority",
            "refresh_snapshot",
            "phase'=>'3-groundwork'",
        ):
            self.assertIn(token,phase3)
        self.assertNotIn("$wpdb->update(BlueVPN_DB::table('gateway_nodes')",phase3)

    def test_phase3_roadmap_has_circuit_breaker_and_safe_rollout(self):
        doc=self.text("bluevpn-gateway/PHASE3.md")
        self.assertIn("circuit-breaker",doc)
        self.assertIn("config-generation acknowledgement",doc)
        self.assertIn("automatic rollback",doc)

if __name__=="__main__":
    unittest.main()
