import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class GatewayHaPhase3515Tests(unittest.TestCase):
    def text(self, rel):
        return (ROOT / rel).read_text(encoding="utf-8")

    def test_release_and_schema_are_phase3(self):
        release = json.loads(self.text("release.json"))
        self.assertEqual(release["version"], "6.1.8")
        self.assertEqual(release["version_code"], 60108)
        self.assertIn("gateway-ha-capacity-aware-placement", release["features"])
        plugin = self.text("bluevpn-manager/bluevpn-manager.php")
        self.assertIn("BLUEVPN_MANAGER_SCHEMA_VERSION', '1.34.0'", plugin)
        db = self.text("bluevpn-manager/includes/class-bluevpn-db.php")
        for token in (
            "region varchar(80)", "priority int", "max_sessions int", "draining tinyint(1)",
            "health_status varchar(24)", "active_sessions int", "pending_usage_events int",
            "agent_epoch varchar(64)", "last_seq bigint unsigned", "role varchar(16)",
            "gateway_replica_count int NOT NULL DEFAULT 2",
        ):
            self.assertIn(token, db)

    def test_scheduler_is_health_capacity_region_and_drain_aware(self):
        gateway = self.text("bluevpn-manager/includes/class-bluevpn-gateway.php")
        for token in (
            "HEALTHY_WINDOW_SECONDS", "DEGRADED_WINDOW_SECONDS", "node_has_capacity",
            "select_nodes_for_customer", "gateway_replica_count", "primary", "standby",
            "draining", "region", "priority", "max_sessions", "retired",
        ):
            self.assertIn(token, gateway)
        self.assertIn("bluevpn_gateway_reconcile_tick", gateway)
        self.assertIn("bluevpn_one_minute", gateway)
        self.assertIn("reconcile_metered_customers(120,true)", gateway)
        self.assertIn("BlueVPN_Gateway::unschedule()", self.text("bluevpn-manager/bluevpn-manager.php"))

    def test_metering_is_crash_durable_and_replay_guarded(self):
        gateway = self.text("bluevpn-manager/includes/class-bluevpn-gateway.php")
        agent = self.text("bluevpn-gateway/agent.py")
        for token in (
            "LIMIT 1 FOR UPDATE", "agent_epoch", "last_seq", "limited_session_ids",
            "used_traffic_bytes=%d", "reload_required",
        ):
            self.assertIn(token, gateway)
        self.assertIn("Persist immediately after Xray reset=true", agent)
        self.assertIn('Persist immediately after Xray reset=true', agent)
        self.assertIn('_enforce_local_leases', agent)
        self.assertIn('if not pending:', agent)
        self.assertIn('"agent_epoch": self.usage_epoch', agent)
        self.assertIn('self.state["last_usage_flush_at"]', agent)

    def test_heartbeat_exposes_live_gateway_health(self):
        gateway = self.text("bluevpn-manager/includes/class-bluevpn-gateway.php")
        agent = self.text("bluevpn-gateway/agent.py")
        for token in (
            '"xray_running":running', '"active_sessions":len(self.email_map)',
            '"pending_usage_events":len(self.state.get("pending") or [])',
            '"cpu_load_pct":self._cpu_load_pct()', '"memory_used_pct":self._memory_used_pct()',
            '"uptime_seconds":self._uptime_seconds()', '"agent_boot_id":self.boot_id',
        ):
            self.assertIn(token, agent)
        for token in ("health_status", "active_sessions", "pending_usage_events", "cpu_load_pct", "memory_used_pct", "agent_uptime_seconds"):
            self.assertIn(token, gateway)

    def test_windows_already_tries_gateway_standby_candidates(self):
        orchestrator = self.text("bluevpn-windows/Services/ConnectionOrchestrator.cs")
        self.assertIn("var candidates = ranked.Where", orchestrator)
        self.assertIn("Take(5)", orchestrator)
        self.assertIn("foreach (var endpoint in candidates)", orchestrator)
        self.assertIn("_ai.RecordFailure(endpoint", orchestrator)

    def test_hysteria_tuic_use_metered_sidecar(self):
        agent = self.text("bluevpn-gateway/agent.py")
        readme = self.text("bluevpn-gateway/README.md")
        self.assertIn('XRAY_SCHEMES = {"vless", "vmess", "trojan", "ss"}', agent)
        self.assertIn('BRIDGE_SCHEMES = {"hysteria2", "hy2", "tuic"}', agent)
        self.assertIn("build_singbox_config", agent)
        self.assertIn("Hysteria2/TUIC", readme)
        self.assertIn("Xray", readme)


if __name__ == "__main__":
    unittest.main()
