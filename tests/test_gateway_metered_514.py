import json
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class GatewayMetered514Tests(unittest.TestCase):
    def text(self, rel):
        return (ROOT / rel).read_text(encoding="utf-8")

    def test_release_schema_and_gateway_tables_are_authoritative(self):
        release = json.loads(self.text("release.json"))
        self.assertEqual(release["version"], "6.3.1")
        self.assertEqual(release["version_code"], 60301)
        plugin = self.text("bluevpn-manager/bluevpn-manager.php")
        self.assertIn("BLUEVPN_MANAGER_SCHEMA_VERSION', '1.37.0'", plugin)
        db = self.text("bluevpn-manager/includes/class-bluevpn-db.php")
        for table in ("subscription_sources", "gateway_nodes", "gateway_sessions", "gateway_usage_events"):
            self.assertIn("CREATE TABLE {$t('" + table + "')}", db)
        self.assertIn("traffic_mode varchar(24) NOT NULL DEFAULT 'provider_reported'", db)
        self.assertIn("source_ids_json longtext NULL", db)
        self.assertIn("UNIQUE KEY uq_gateway_usage_event (event_id)", db)

    def test_manual_sources_are_encrypted_and_gateway_subscription_hides_upstreams(self):
        sources = self.text("bluevpn-manager/includes/class-bluevpn-subscription-sources.php")
        gateway = self.text("bluevpn-manager/includes/class-bluevpn-gateway.php")
        providers = self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        self.assertIn("BlueVPN_Utils::encrypt_secret($raw)", sources)
        self.assertIn("BlueVPN_Utils::decrypt_secret", sources)
        self.assertIn("active_entries_for_plan", providers)
        self.assertIn("BlueVPN_Gateway::gateway_subscription_lines($c)", providers)
        self.assertIn("X-BlueVPN-Traffic-Mode: gateway_metered", providers)
        self.assertIn("vless://", gateway)
        # Upstream source payloads are emitted only to authenticated gateway config, not /sub clients.
        serve = providers[providers.index("public static function serve_subscription"):]
        self.assertNotIn("gateway_upstream_pool", serve)

    def test_gateway_usage_is_hmac_idempotent_and_central_quota_is_authoritative(self):
        gateway = self.text("bluevpn-manager/includes/class-bluevpn-gateway.php")
        providers = self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        for token in ("hash_hmac('sha256'", "hash_equals($expected,$signature)", "event_id", "used_traffic_bytes=%d", "FOR UPDATE", "agent_epoch", "last_seq", "subscription_status'=>'limited'", "reload_required"):
            self.assertIn(token, gateway)
        self.assertIn("$providerQuota=$trafficMode==='gateway_metered'?0:$quota", providers)
        self.assertIn("if(!$gateway&&$responses>0)$u['used_traffic_bytes']=$providerUsed", providers)
        self.assertIn("Gateway byte events are the only quota authority", providers)

    def test_linux_agent_uses_xray_per_user_stats_and_fail_closed_routing(self):
        agent = self.text("bluevpn-gateway/agent.py")
        for token in ("statsUserUplink", "statsUserDownlink", '"stats"', '"user"', '"balancerTag"', '"selector"', '"protocol":"blackhole"', '"protocol":"vless"', '"security":"tls"', '"-reset=true"', '"/bluevpn-gateway/v1/usage"'):
            self.assertIn(token, agent)
        self.assertIn('XRAY_SCHEMES = {"vless", "vmess", "trojan", "ss"}', agent)
        self.assertIn('BRIDGE_SCHEMES = {"hysteria2", "hy2", "tuic"}', agent)
        self.assertIn("build_singbox_config", agent)
        self.assertIn("_enforce_local_leases", agent)
        self.assertNotIn("import requests", agent)
        self.assertNotIn("import aiohttp", agent)


    def test_manager_ui_exposes_sources_gateway_and_plan_traffic_mode(self):
        cc = self.text("bluevpn-manager/includes/class-bluevpn-control-center.php")
        admin = self.text("bluevpn-manager/includes/class-bluevpn-admin.php")
        ui = self.text("bluevpn-manager/includes/class-bluevpn-unified-ui.php")
        for token in ("'sources'=>'منابع اشتراک'", "'gateway'=>'دروازه اندازه‌گیری مصرف'", "name=\"traffic_mode\"", "render_plan_picker"):
            self.assertIn(token, cc)
        self.assertIn("bluevpn-subscription-sources", admin)
        self.assertIn("bluevpn-gateway", admin)
        self.assertIn("bluevpn-subscription-sources", ui)
        self.assertIn("bluevpn-gateway", ui)


if __name__ == "__main__":
    unittest.main()
