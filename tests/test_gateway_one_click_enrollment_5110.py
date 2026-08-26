import importlib.util
import json
import os
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

class GatewayOneClickEnrollment5110Tests(unittest.TestCase):
    def text(self, rel):
        return (ROOT / rel).read_text(encoding="utf-8")

    def test_release_and_schema_contract(self):
        r=json.loads(self.text("release.json"))
        self.assertEqual(r["version"],"5.10.9")
        self.assertEqual(r["version_code"],51009)
        for f in (
            "gateway-one-click-enrollment","gateway-one-time-bootstrap-token","gateway-enrollment-expiry",
            "gateway-agent-package-from-manager","gateway-dual-secret-rotation-grace",
            "gateway-agent-credential-auto-persist","gateway-auto-secret-rotation",
            "gateway-enrollment-health-watchdog","gateway-production-provisioning-hardening",
        ):
            self.assertIn(f,r["features"])
        self.assertIn("BLUEVPN_MANAGER_SCHEMA_VERSION', '1.31.0'",self.text("bluevpn-manager/bluevpn-manager.php"))

    def test_schema_has_enrollment_and_rotation_metadata(self):
        db=self.text("bluevpn-manager/includes/class-bluevpn-db.php")
        for token in (
            "previous_secret_enc longtext","previous_secret_hash varchar(64)","previous_secret_expires_at datetime",
            "secret_generation bigint unsigned","last_secret_rotated_at datetime","enrollment_token_hash varchar(64)",
            "enrollment_expires_at datetime","enrolled_at datetime",
        ):
            self.assertIn(token,db)

    def test_manager_one_click_enrollment_is_single_use_and_rate_limited(self):
        g=self.text("bluevpn-manager/includes/class-bluevpn-gateway.php")
        for token in (
            "ENROLLMENT_TOKEN_TTL_SECONDS = 1800","ENROLLMENT_HEARTBEAT_GRACE_SECONDS = 900",
            "register_rest_route('bluevpn-gateway/v1','/enroll'","enrollment_token_key","issue_enrollment_token",
            "GATEWAY_ENROLLMENT_RATE_LIMIT","GATEWAY_ENROLLMENT_EXPIRED","'enrollment_token_hash'=>''",
            "one-click-install.sh","ساخت دستور نصب جدید",
        ):
            self.assertIn(token,g)
        # Raw enrollment token must not be persisted in DB, only a derived hash.
        self.assertNotIn("'enrollment_token'=>$token",g)

    def test_dual_secret_rotation_is_bounded_and_automatic(self):
        g=self.text("bluevpn-manager/includes/class-bluevpn-gateway.php")
        for token in (
            "SECRET_ROTATION_SECONDS = 2592000","PREVIOUS_SECRET_GRACE_SECONDS = 86400",
            "rotate_node_secret_internal","previous_secret_enc","previous_secret_expires_at",
            "_auth_secret_slot","'previous'","credential_update","rotate_stale_secrets(10)",
        ):
            self.assertIn(token,g)
        self.assertIn("Agent در Heartbeat بعدی Credential جدید را خودکار دریافت",g)

    def test_enrollment_watchdog_is_resolved_by_heartbeat(self):
        g=self.text("bluevpn-manager/includes/class-bluevpn-gateway.php")
        self.assertIn("GATEWAY_ENROLLMENT_NO_HEARTBEAT_",g)
        self.assertIn("BlueVPN_Error_Monitor::resolve_matching('gateway','enrollment'",g)
        self.assertIn("self::enrollment_health_watchdog(25)",g)

    def test_agent_persists_rotated_credential_atomically(self):
        agent=self.text("bluevpn-gateway/agent.py")
        self.assertIn('AGENT_VERSION = "5.10.9"',agent)
        for token in (
            "def _persist_credentials","def _apply_credential_update","os.fsync","os.chmod(tmp,0o600)",
            "os.replace(tmp,self.config_path)","credential_generation","self._apply_credential_update(response)",
        ):
            self.assertIn(token,agent)

        spec=importlib.util.spec_from_file_location("bluevpn_gateway_agent_5110",ROOT/"bluevpn-gateway/agent.py")
        module=importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
        with tempfile.TemporaryDirectory() as td:
            td=Path(td); cfg_path=td/"agent.json"; state=td/"state.json"
            cfg={
                "manager_url":"https://manager.invalid","node_id":9,"node_secret":"old-secret-abcdefghijklmnopqrstuvwxyz",
                "credential_generation":1,"cert_file":"/tmp/cert","key_file":"/tmp/key","state_path":str(state),
                "xray_path":"/missing/xray","singbox_path":"/missing/sing-box","_config_path":str(cfg_path),
            }
            cfg_path.write_text(json.dumps({k:v for k,v in cfg.items() if not k.startswith('_')}),encoding="utf-8")
            a=module.Agent(cfg)
            new_secret="new-secret-abcdefghijklmnopqrstuvwxyz"
            a._persist_credentials(new_secret,2)
            persisted=json.loads(cfg_path.read_text(encoding="utf-8"))
            self.assertEqual(persisted["node_secret"],new_secret)
            self.assertEqual(persisted["credential_generation"],2)
            self.assertEqual(a.secret,new_secret)
            self.assertEqual(os.stat(cfg_path).st_mode & 0o777,0o600)

    def test_installer_is_fail_safe_and_manager_assets_match(self):
        install=self.text("bluevpn-gateway/one-click-install.sh")
        for token in (
            "BlueVPN-Gateway-Installer/${AGENT_VERSION}","python3 -m py_compile","install -m 0600",
            "systemctl enable bluevpn-gateway","TLS certificate","/usr/local/bin/xray",
            "Gateway was NOT started because prerequisites are missing",
        ):
            self.assertIn(token,install)
        self.assertEqual((ROOT/"bluevpn-gateway/agent.py").read_bytes(),(ROOT/"bluevpn-manager/assets/gateway/agent.py").read_bytes())
        self.assertEqual((ROOT/"bluevpn-gateway/bluevpn-gateway.service").read_bytes(),(ROOT/"bluevpn-manager/assets/gateway/bluevpn-gateway.service").read_bytes())
        self.assertEqual((ROOT/"bluevpn-gateway/one-click-install.sh").read_bytes(),(ROOT/"bluevpn-manager/assets/gateway/one-click-install.sh").read_bytes())

    def test_phase6_documented(self):
        d=self.text("bluevpn-gateway/PHASE6.md")
        for token in ("one-time enrollment token","30 minutes","atomic","24-hour","15 minutes","fail-safe"):
            self.assertIn(token,d)

if __name__ == '__main__':
    unittest.main()
