import importlib.util
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
SPEC=importlib.util.spec_from_file_location("bluevpn_gateway_agent",ROOT/"bluevpn-gateway/agent.py")
AGENT=importlib.util.module_from_spec(SPEC); SPEC.loader.exec_module(AGENT)

class GatewayAgentSidecar516Tests(unittest.TestCase):
    def control(self):
        return {"sessions":[{"session_id":7,"customer_id":9,"email":"u@example","uuid":"11111111-1111-4111-8111-111111111111","lease_bytes":4096,"last_seq":12,"upstreams":["hysteria2://secret@example.com:443?sni=example.com","tuic://123e4567-e89b-12d3-a456-426614174000:pass@example.net:443?sni=example.net"]}]}

    def test_sidecar_builds_hysteria_tuic_urltest(self):
        config,ports,skipped=AGENT.build_singbox_config(self.control(),{"bridge_socks_base_port":18080},set())
        self.assertFalse(skipped); self.assertEqual(ports[7],18080); self.assertIsNotNone(config)
        types=[x.get("type") for x in config["outbounds"]]
        self.assertIn("hysteria2",types); self.assertIn("tuic",types); self.assertIn("urltest",types)

    def test_xray_keeps_metering_ingress_and_bridges_locally(self):
        config,email_map,skipped=AGENT.build_xray_config(self.control(),{"cert_file":"/c","key_file":"/k"},{7:18080},set())
        self.assertFalse(skipped); self.assertEqual(config["inbounds"][0]["protocol"],"vless")
        self.assertEqual(email_map["u@example"]["lease_bytes"],4096); self.assertEqual(email_map["u@example"]["last_seq"],12)
        self.assertTrue(any(x.get("protocol")=="socks" for x in config["outbounds"]))

    def test_blocked_session_is_removed_from_both_dataplanes(self):
        sb,ports,_=AGENT.build_singbox_config(self.control(),{}, {7}); self.assertIsNone(sb); self.assertEqual(ports,{})
        xr,email_map,_=AGENT.build_xray_config(self.control(),{"cert_file":"/c","key_file":"/k"},{}, {7}); self.assertEqual(email_map,{})
        self.assertEqual(xr["inbounds"][0]["settings"]["clients"],[])

if __name__=="__main__": unittest.main()
