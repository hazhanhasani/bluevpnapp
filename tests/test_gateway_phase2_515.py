import json
import py_compile
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class GatewayPhase2515Tests(unittest.TestCase):
    def text(self, rel):
        return (ROOT / rel).read_text(encoding="utf-8")

    def test_release_and_schema_bump(self):
        release = json.loads(self.text("release.json"))
        self.assertEqual(release["version"], "5.1.5")
        self.assertEqual(release["version_code"], 50105)
        plugin = self.text("bluevpn-manager/bluevpn-manager.php")
        self.assertIn("BLUEVPN_MANAGER_SCHEMA_VERSION', '1.27.0'", plugin)
        db = self.text("bluevpn-manager/includes/class-bluevpn-db.php")
        for field in (
            "priority int NOT NULL DEFAULT 100",
            "max_sessions int NOT NULL DEFAULT 0",
            "draining tinyint(1) NOT NULL DEFAULT 0",
            "last_active_sessions int NOT NULL DEFAULT 0",
            "last_pending_events int NOT NULL DEFAULT 0",
            "last_load1 decimal(8,2) NOT NULL DEFAULT 0",
        ):
            self.assertIn(field, db)

    def test_health_aware_replica_scheduler_and_failover(self):
        gateway = self.text("bluevpn-manager/includes/class-bluevpn-gateway.php")
        for token in (
            "GATEWAY_REPLICA_COUNT",
            "healthy_nodes",
            "draining=0",
            "max_sessions",
            "priority",
            "status'=>'standby'",
            "node_is_online",
            "remaining_bytes",
            "lease_bytes",
            "last_seq",
        ):
            self.assertIn(token, gateway)
        self.assertIn("revoked_session_ids", gateway)
        self.assertIn("SELECT id FROM {$ut} WHERE session_id=%d AND seq=%d", gateway)
        self.assertIn("SELECT used_traffic_bytes,data_limit_bytes", gateway)

    def test_agent_persists_unacked_usage_and_enforces_local_lease(self):
        agent = self.text("bluevpn-gateway/agent.py")
        py_compile.compile(str(ROOT / "bluevpn-gateway/agent.py"), doraise=True)
        for token in (
            'AGENT_VERSION = "5.1.5"',
            'self.state.setdefault("pending", [])',
            "self._save_state()  # persist before network I/O",
            "lease_bytes",
            "locally_blocked",
            "revoked_session_ids",
            "last_seq",
            "heartbeat_seconds",
            "last_active_sessions",
            "pending_events",
        ):
            self.assertIn(token, agent)

    def test_hysteria2_tuic_bridge_uses_sing_box_sidecar(self):
        agent = self.text("bluevpn-gateway/agent.py")
        for token in (
            'BRIDGE_SCHEMES = {"hysteria2", "hy2", "tuic"}',
            "parse_hysteria2",
            "parse_tuic",
            '"type": "hysteria2"',
            '"type": "tuic"',
            '"type": "urltest"',
            '"type": "socks"',
            '"action": "route"',
            "sing-box",
            "singbox_path",
            "singbox_config_path",
        ):
            self.assertIn(token, agent)
        readme = self.text("bluevpn-gateway/README.md")
        self.assertIn("Hysteria2", readme)
        self.assertIn("TUIC", readme)
        self.assertIn("sing-box", readme)

    def test_admin_exposes_capacity_priority_and_drain(self):
        gateway = self.text("bluevpn-manager/includes/class-bluevpn-gateway.php")
        for html in ('name="priority"', 'name="max_sessions"', 'name="draining"'):
            self.assertIn(html, gateway)
        self.assertIn("Session فعال", gateway)
        self.assertIn("Pending usage", gateway)


if __name__ == "__main__":
    unittest.main()
