import json
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

class GatewayPhase3Circuit516Tests(unittest.TestCase):
    def text(self,rel): return (ROOT/rel).read_text(encoding="utf-8")

    def test_release_contract(self):
        r=json.loads(self.text("release.json"))
        self.assertEqual(r["version"],"5.9.9"); self.assertEqual(r["version_code"],50909)
        for token in ("gateway-circuit-breaker-hysteresis","gateway-local-quota-lease-fail-closed","gateway-hysteria2-singbox-sidecar","free-source-sentinel-single-owner"):
            self.assertIn(token,r["features"])

    def test_circuit_breaker_is_hysteretic_and_rollbackable(self):
        g=self.text("bluevpn-manager/includes/class-bluevpn-gateway.php")
        for token in ("CIRCUIT_FAILURE_THRESHOLD = 3","CIRCUIT_RECOVERY_THRESHOLD = 2","CIRCUIT_OPEN_SECONDS = 180","bluevpn_gateway_phase3_circuit_enabled","half_open","circuit_allows_node","record_circuit_observation"):
            self.assertIn(token,g)
        self.assertGreaterEqual(g.count("!self::circuit_allows_node($node)"),2)

    def test_manager_delivers_lease_and_policy_sequence(self):
        g=self.text("bluevpn-manager/includes/class-bluevpn-gateway.php")
        for token in ("lease_bytes","last_seq","policy_hash","active_replica_count","revoked_session_ids"):
            self.assertIn(token,g)

    def test_agent_combines_durable_epoch_lease_and_sidecar(self):
        a=self.text("bluevpn-gateway/agent.py")
        for token in ("agent_epoch","locally_blocked","_enforce_local_leases","BRIDGE_SCHEMES","parse_hysteria2","parse_tuic","build_singbox_config","restart_singbox","singbox_version","Persist immediately after Xray reset=true"):
            self.assertIn(token,a)

if __name__=="__main__": unittest.main()
