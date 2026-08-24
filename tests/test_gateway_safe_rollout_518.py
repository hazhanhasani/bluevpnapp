import importlib.util
import json
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class GatewaySafeRollout518Tests(unittest.TestCase):
    def text(self, rel):
        return (ROOT / rel).read_text(encoding="utf-8")

    def test_release_and_schema_contract(self):
        release = json.loads(self.text("release.json"))
        self.assertEqual(release["version"], "5.4.1")
        self.assertEqual(release["version_code"], 50401)
        for feature in (
            "gateway-config-generation",
            "gateway-agent-apply-ack",
            "gateway-safe-staged-rollout",
            "gateway-canary-rollout",
            "gateway-rollout-auto-rollback",
            "gateway-config-hash-mismatch-detection",
        ):
            self.assertIn(feature, release["features"])
        plugin = self.text("bluevpn-manager/bluevpn-manager.php")
        self.assertIn("BLUEVPN_MANAGER_SCHEMA_VERSION', '1.31.0'", plugin)
        db = self.text("bluevpn-manager/includes/class-bluevpn-db.php")
        self.assertIn("gateway_config_generations", db)
        self.assertIn("last_config_generation bigint unsigned", db)
        self.assertIn("last_config_ack_at datetime", db)
        self.assertIn("UNIQUE KEY uq_gateway_config_generation_node", db)

    def test_rollout_is_staged_canary_and_auto_rollback(self):
        gateway = self.text("bluevpn-manager/includes/class-bluevpn-gateway.php")
        for token in (
            "ROLLOUT_STAGES = [10,25,50,100]",
            "ROLLOUT_ACK_TIMEOUT_SECONDS = 150",
            "ROLLOUT_HEALTH_HOLD_SECONDS = 45",
            "ROLLOUT_RETRY_COOLDOWN_SECONDS = 900",
            "ROLLOUT_AGENT_MIN_VERSION = '5.1.8'",
            "canary_started",
            "GATEWAY_ROLLOUT_AUTO_ROLLBACK",
            "CONFIG_HASH_MISMATCH",
            "candidate_changed_during_rollout",
            "config_ack_timeout",
        ):
            self.assertIn(token, gateway)
        self.assertIn("rollout_included_ids", gateway)
        self.assertIn("ordered_rollout_node_ids", gateway)
        self.assertIn("rollout_agents_ready", gateway)

    def test_config_get_never_pretends_ack(self):
        gateway = self.text("bluevpn-manager/includes/class-bluevpn-gateway.php")
        start = gateway.index("public static function rest_config")
        end = gateway.index("public static function rest_usage", start)
        rest_config = gateway[start:end]
        self.assertIn("config_generation", rest_config)
        self.assertIn("rollout_canary", rest_config)
        self.assertIn("last_config_hash is ACKed runtime state", rest_config)
        self.assertNotIn("'last_config_hash'=>$hash", rest_config)

    def test_agent_ack_is_after_successful_apply_and_persisted(self):
        agent = self.text("bluevpn-gateway/agent.py")
        self.assertIn('AGENT_VERSION = "5.4.1"', agent)
        for token in (
            "def _mark_config_applied",
            'self.state["applied_config_generation"]',
            'self.state["applied_config_hash"]',
            'self.state["applied_policy_hash"]',
            'self.state["config_applied_at"]',
            '"config_generation":self.applied_generation',
            '"config_applied_at":self.config_applied_at',
        ):
            self.assertIn(token, agent)
        apply = agent[agent.index("def apply_config"):agent.index("def restart_xray")]
        self.assertIn("xray config validation failed", apply)
        self.assertGreater(apply.rindex("self._mark_config_applied()"), apply.index("self.restart_xray()"))


    def test_agent_ack_state_survives_restart(self):
        spec = importlib.util.spec_from_file_location("bluevpn_gateway_agent_518", ROOT / "bluevpn-gateway/agent.py")
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        with tempfile.TemporaryDirectory() as td:
            state = str(Path(td) / "state.json")
            cfg = {
                "manager_url": "https://manager.invalid",
                "node_id": 1,
                "node_secret": "secret",
                "cert_file": "/tmp/cert",
                "key_file": "/tmp/key",
                "state_path": state,
                "xray_path": "/missing/xray",
                "singbox_path": "/missing/sing-box",
            }
            agent = module.Agent(cfg)
            agent.desired_generation = 42
            agent.desired_config_hash = "a" * 64
            agent.desired_policy_hash = "b" * 64
            agent._mark_config_applied()
            restarted = module.Agent(cfg)
            self.assertEqual(restarted.applied_generation, 42)
            self.assertEqual(restarted.config_hash, "a" * 64)
            self.assertEqual(restarted.policy_hash, "b" * 64)
            self.assertTrue(restarted.config_applied_at.endswith("Z"))

    def test_policy_stays_live_while_structural_snapshot_is_pinned(self):
        gateway = self.text("bluevpn-manager/includes/class-bluevpn-gateway.php")
        self.assertIn("hydrate_structural_snapshot", gateway)
        self.assertIn("gateway_upstream_pool($customerId)", gateway)
        self.assertIn("remaining_bytes", gateway)
        self.assertIn("lease_bytes", gateway)
        self.assertIn("last_seq", gateway)
        self.assertIn("Circuit isolation is immediate. Drain is graceful", gateway)

    def test_phase4_documented(self):
        phase4 = self.text("bluevpn-gateway/PHASE4.md")
        for token in ("10% -> 25% -> 50% -> 100%", "ACK", "rollback", "bluevpn_gateway_config_generations"):
            self.assertIn(token, phase4)


if __name__ == "__main__":
    unittest.main()
