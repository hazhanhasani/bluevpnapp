import importlib.util
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

class GatewayAutopilotMigration519Tests(unittest.TestCase):
    def text(self, rel):
        return (ROOT / rel).read_text(encoding="utf-8")

    def test_release_contract(self):
        r=json.loads(self.text("release.json"))
        self.assertEqual(r["version"],"6.1.10")
        self.assertEqual(r["version_code"],60110)
        for f in ("gateway-autopilot-default-on","gateway-telemetry-auto-capacity","gateway-auto-drain-recovery","gateway-graceful-drain","gateway-zero-downtime-session-handoff","gateway-handoff-target-ack-gate","gateway-handoff-overlap-window"):
            self.assertIn(f,r["features"])
        self.assertIn("BLUEVPN_MANAGER_SCHEMA_VERSION', '1.34.0'",self.text("bluevpn-manager/bluevpn-manager.php"))

    def test_schema_has_telemetry_and_migrations(self):
        db=self.text("bluevpn-manager/includes/class-bluevpn-db.php")
        for token in ("gateway_session_migrations","cpu_cores int","memory_total_mb int","ix_gateway_migration_state","source_session_id","target_session_id"):
            self.assertIn(token,db)

    def test_autopilot_is_default_on_and_capacity_is_telemetry_driven(self):
        g=self.text("bluevpn-manager/includes/class-bluevpn-gateway.php")
        for token in ("AUTOPILOT_ENABLED_OPTION","AUTOPILOT_FAILURE_THRESHOLD = 2","AUTOPILOT_RECOVERY_THRESHOLD = 3","autopilot_capacity","cpu_cores","memory_total_mb","node_effectively_draining","auto_draining","bluevpn_gateway_autopilot_enabled"):
            self.assertIn(token,g)
        self.assertIn("self::autopilot_enabled()?100",g)
        self.assertIn("$cores*1000",g)
        self.assertIn("floor($mem/2)",g)

    def test_drain_is_graceful_not_immediate_empty_config(self):
        g=self.text("bluevpn-manager/includes/class-bluevpn-gateway.php")
        start=g.index("public static function rest_config")
        end=g.index("public static function rest_usage",start)
        chunk=g[start:end]
        self.assertIn("Drain is graceful",chunk)
        self.assertIn("if(!self::circuit_allows_node($node))",chunk)
        self.assertNotIn("if((int)($node['draining']??0)===1||!self::circuit_allows_node",chunk)
        self.assertIn("'accept_new'=>!self::node_effectively_draining($node)",chunk)

    def test_handoff_waits_for_target_ack_then_overlap(self):
        g=self.text("bluevpn-manager/includes/class-bluevpn-gateway.php")
        for token in ("MIGRATION_PREPARE_TIMEOUT_SECONDS = 240","MIGRATION_OVERLAP_SECONDS = 60","start_session_migration","migration_target_ready","last_config_ack_at","target_not_ready_before_deadline","target_lost_health_before_cutover","status'=>'retired'"):
            self.assertIn(token,g)
        self.assertIn("self::migration_tick(500)",g)
        self.assertIn("if(self::active_migration_for_source((int)$row['id']))$out[]=$row",g)
        self.assertIn("?'Handoff'",g)

    def test_agent_reports_hardware_for_autocapacity(self):
        agent=self.text("bluevpn-gateway/agent.py")
        self.assertIn('AGENT_VERSION = "6.1.10"',agent)
        self.assertIn("def _memory_total_mb",agent)
        self.assertIn('"cpu_cores":max(1,int(os.cpu_count() or 1))',agent)
        self.assertIn('"memory_total_mb":self._memory_total_mb()',agent)

    def test_phase5_is_documented_as_zero_touch_day2(self):
        d=self.text("bluevpn-gateway/PHASE5.md")
        for token in ("Autopilot (default ON)","Auto-Drain","Auto-Recover","overlap امن 60", "240 ثانیه", "Public Host"):
            self.assertIn(token,d)

if __name__ == '__main__':
    unittest.main()
