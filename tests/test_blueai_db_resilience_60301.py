from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]

class BlueAiDbResilience60301Tests(unittest.TestCase):
    def text(self,path):
        return (ROOT/path).read_text(encoding="utf-8")

    def test_db_health_checks_columns_not_only_table_names(self):
        db=self.text("bluevpn-manager/includes/class-bluevpn-db.php")
        for token in ["critical_schema_contract","inspect_schema_contract","missing_columns","SCHEMA_AUDIT_TRANSIENT","DATABASE_SCHEMA_DRIFT"]:
            self.assertIn(token,db)
        self.assertIn("ai_connection_events",db)
        self.assertIn("ai_live_connections",db)
        self.assertIn("ai_route_aggregates",db)

    def test_schema_self_heals_even_without_version_change(self):
        db=self.text("bluevpn-manager/includes/class-bluevpn-db.php")
        block=db[db.index("public static function maybe_upgrade"):db.index("private static function repair_client_types")]
        self.assertIn("$auditDue=!get_transient",block)
        self.assertIn("empty($audit['ready'])",block)
        self.assertIn("install_schema()",block)

    def test_blueai_write_failures_are_not_silent(self):
        ai=self.text("bluevpn-manager/includes/class-bluevpn-ai.php")
        self.assertIn("BLUEAI_DB_WRITE_FAILED",ai)
        self.assertIn("require_db_write",ai)
        self.assertIn("ai_connection_events.insert",ai)
        self.assertIn("ai_route_aggregates.update",ai)
        self.assertIn("ai_feedback.insert",ai)
        self.assertIn("live_write_failed",ai)

    def test_ops_runs_every_five_minutes_and_rotates_cursor(self):
        ops=self.text("bluevpn-manager/includes/class-bluevpn-ai-ops.php")
        self.assertIn("bluevpn_ai_five_minutes",ops)
        self.assertIn("'interval'=>300",ops)
        self.assertIn("RECONCILE_CURSOR_OPTION",ops)
        self.assertIn("repair_candidate_ids_after($cursor,$limit)",ops)
        self.assertIn("db_unhealthy",ops)

    def test_deferred_provider_work_is_not_marked_healthy(self):
        ops=self.text("bluevpn-manager/includes/class-bluevpn-ai-ops.php")
        self.assertIn("$outcome=$deferred>0?'deferred'",ops)
        self.assertIn("in_array($outcome,['repaired','verified'],true)",ops)

    def test_ai_admin_surfaces_readiness(self):
        ai=self.text("bluevpn-manager/includes/class-bluevpn-ai.php")
        self.assertIn("دیتابیس BlueAI",ai)
        self.assertIn("چرخه Operations",ai)
        self.assertIn("آمادگی یادگیری",ai)

if __name__=="__main__":
    unittest.main()
