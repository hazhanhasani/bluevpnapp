#!/usr/bin/env python3
"""BlueVPN first-party gateway agent — Phase 6 Autopilot + one-click enrollment.

Data path:
  BlueVPN client -> Xray VLESS/TLS gateway -> native Xray upstreams
                                            -> local sing-box bridge for Hysteria2/TUIC

The Manager remains quota authority. The agent keeps crash-durable reset-counter events,
uses agent_epoch + monotonic sequence replay protection, enforces per-replica quota leases
locally (fail closed), and preserves Phase 3 health/HA telemetry.
"""
from __future__ import annotations

import argparse
import base64
import hashlib
import hmac
import json
import logging
import os
import shutil
import signal
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from pathlib import Path
from typing import Any

AGENT_VERSION = "5.7.8"
XRAY_SCHEMES = {"vless", "vmess", "trojan", "ss"}
BRIDGE_SCHEMES = {"hysteria2", "hy2", "tuic"}
LOG = logging.getLogger("bluevpn-gateway")


def b64decode_text(value: str) -> str:
    raw = value.strip()
    raw += "=" * (-len(raw) % 4)
    try:
        return base64.urlsafe_b64decode(raw.encode()).decode("utf-8")
    except Exception:
        return base64.b64decode(raw.encode()).decode("utf-8")


def query_map(uri: urllib.parse.ParseResult) -> dict[str, str]:
    return {k: (v[-1] if v else "") for k, v in urllib.parse.parse_qs(uri.query, keep_blank_values=True).items()}

def truthy(value: str) -> bool:
    return str(value).strip().lower() in {"1", "true", "yes", "on"}


def stream_settings(q: dict[str, str]) -> dict[str, Any]:
    network = (q.get("type") or q.get("network") or "tcp").lower()
    security = (q.get("security") or "none").lower()
    stream: dict[str, Any] = {"network": network, "security": security}
    if network == "ws":
        headers = {}
        host = q.get("host") or q.get("hostHeader")
        if host:
            headers["Host"] = host
        stream["wsSettings"] = {"path": q.get("path") or "/", "headers": headers}
    elif network == "grpc":
        stream["grpcSettings"] = {"serviceName": q.get("serviceName") or q.get("service_name") or "", "multiMode": False}
    elif network in {"httpupgrade", "http-upgrade"}:
        stream["network"] = "httpupgrade"
        stream["httpupgradeSettings"] = {"path": q.get("path") or "/", "host": q.get("host") or ""}
    elif network == "splithttp":
        stream["splithttpSettings"] = {"path": q.get("path") or "/", "host": q.get("host") or ""}
    if security == "tls":
        tls: dict[str, Any] = {"serverName": q.get("sni") or q.get("serverName") or q.get("host") or "", "allowInsecure": truthy(q.get("allowInsecure") or q.get("insecure") or "")}
        if q.get("fp"):
            tls["fingerprint"] = q["fp"]
        if q.get("alpn"):
            tls["alpn"] = [x for x in q["alpn"].split(",") if x]
        stream["tlsSettings"] = tls
    elif security == "reality":
        reality: dict[str, Any] = {
            "serverName": q.get("sni") or q.get("serverName") or "",
            "fingerprint": q.get("fp") or "chrome",
            "publicKey": q.get("pbk") or q.get("publicKey") or "",
            "shortId": q.get("sid") or q.get("shortId") or "",
            "spiderX": q.get("spx") or q.get("spiderX") or "",
        }
        stream["realitySettings"] = reality
    return stream


def parse_vless(line: str, tag: str) -> dict[str, Any]:
    u = urllib.parse.urlparse(line)
    q = query_map(u)
    if not u.hostname or not u.port or not u.username:
        raise ValueError("invalid VLESS URI")
    user: dict[str, Any] = {"id": urllib.parse.unquote(u.username), "encryption": q.get("encryption") or "none"}
    if q.get("flow"):
        user["flow"] = q["flow"]
    return {
        "tag": tag,
        "protocol": "vless",
        "settings": {"vnext": [{"address": u.hostname, "port": u.port, "users": [user]}]},
        "streamSettings": stream_settings(q),
    }


def parse_trojan(line: str, tag: str) -> dict[str, Any]:
    u = urllib.parse.urlparse(line)
    q = query_map(u)
    if not u.hostname or not u.port or not u.username:
        raise ValueError("invalid Trojan URI")
    return {
        "tag": tag,
        "protocol": "trojan",
        "settings": {"servers": [{"address": u.hostname, "port": u.port, "password": urllib.parse.unquote(u.username)}]},
        "streamSettings": stream_settings(q),
    }


def parse_vmess(line: str, tag: str) -> dict[str, Any]:
    payload = json.loads(b64decode_text(line[len("vmess://"):]))
    host = str(payload.get("add") or "").strip()
    port = int(payload.get("port") or 0)
    ident = str(payload.get("id") or "").strip()
    if not host or not port or not ident:
        raise ValueError("invalid VMess URI")
    network = str(payload.get("net") or "tcp")
    security = "tls" if str(payload.get("tls") or "").lower() == "tls" else "none"
    q = {
        "type": network,
        "security": security,
        "sni": str(payload.get("sni") or payload.get("host") or ""),
        "host": str(payload.get("host") or ""),
        "path": str(payload.get("path") or "/"),
        "serviceName": str(payload.get("path") or payload.get("serviceName") or ""),
        "fp": str(payload.get("fp") or ""),
        "alpn": str(payload.get("alpn") or ""),
    }
    user: dict[str, Any] = {"id": ident, "security": str(payload.get("scy") or payload.get("security") or "auto")}
    aid = int(payload.get("aid") or 0)
    if aid:
        user["alterId"] = aid
    return {"tag": tag, "protocol": "vmess", "settings": {"vnext": [{"address": host, "port": port, "users": [user]}]}, "streamSettings": stream_settings(q)}


def parse_ss(line: str, tag: str) -> dict[str, Any]:
    raw = line[len("ss://"):].split("#", 1)[0]
    # SIP002: userinfo may be base64(method:password), or whole method:pass@host:port is base64.
    if "@" not in raw:
        raw = b64decode_text(raw)
    userinfo, hostport = raw.rsplit("@", 1)
    if ":" not in userinfo:
        userinfo = b64decode_text(userinfo)
    method, password = userinfo.split(":", 1)
    parsed = urllib.parse.urlparse("ss://" + hostport)
    if not parsed.hostname or not parsed.port:
        raise ValueError("invalid Shadowsocks URI")
    return {"tag": tag, "protocol": "shadowsocks", "settings": {"servers": [{"address": parsed.hostname, "port": parsed.port, "method": urllib.parse.unquote(method), "password": urllib.parse.unquote(password)}]}}


def parse_upstream(line: str, tag: str) -> dict[str, Any] | None:
    scheme = line.split(":", 1)[0].lower()
    if scheme not in XRAY_SCHEMES:
        return None
    if scheme == "vless":
        return parse_vless(line, tag)
    if scheme == "vmess":
        return parse_vmess(line, tag)
    if scheme == "trojan":
        return parse_trojan(line, tag)
    return parse_ss(line, tag)


def singbox_tls(u: urllib.parse.ParseResult, q: dict[str, str]) -> dict[str, Any]:
    server_name = q.get("sni") or q.get("server_name") or q.get("peer") or u.hostname or ""
    tls: dict[str, Any] = {"enabled": True, "server_name": server_name, "insecure": truthy(q.get("insecure") or q.get("allowInsecure") or q.get("allow_insecure") or "")}
    fp = q.get("fp") or q.get("fingerprint")
    if fp:
        tls["utls"] = {"enabled": True, "fingerprint": fp}
    alpn = q.get("alpn")
    if alpn:
        tls["alpn"] = [x.strip() for x in alpn.split(",") if x.strip()]
    return tls


def parse_hysteria2(line: str, tag: str) -> dict[str, Any]:
    if line.lower().startswith("hy2://"):
        line = "hysteria2://" + line[len("hy2://"):]
    u = urllib.parse.urlparse(line); q = query_map(u)
    if not u.hostname or not u.port or not u.username:
        raise ValueError("invalid Hysteria2 URI")
    password = urllib.parse.unquote(u.username)
    if u.password is not None:
        password += ":" + urllib.parse.unquote(u.password)
    out: dict[str, Any] = {"type": "hysteria2", "tag": tag, "server": u.hostname, "server_port": u.port, "password": password, "tls": singbox_tls(u, q)}
    obfs_type = q.get("obfs") or q.get("obfs_type"); obfs_password = q.get("obfs-password") or q.get("obfs_password")
    if obfs_type:
        out["obfs"] = {"type": obfs_type, "password": obfs_password or ""}
    if q.get("up_mbps"): out["up_mbps"] = max(0, int(q["up_mbps"]))
    if q.get("down_mbps"): out["down_mbps"] = max(0, int(q["down_mbps"]))
    return out


def parse_tuic(line: str, tag: str) -> dict[str, Any]:
    u = urllib.parse.urlparse(line); q = query_map(u)
    if not u.hostname or not u.port or not u.username:
        raise ValueError("invalid TUIC URI")
    ident = urllib.parse.unquote(u.username); password = urllib.parse.unquote(u.password or q.get("password") or "")
    if not password:
        raise ValueError("TUIC password missing")
    return {"type": "tuic", "tag": tag, "server": u.hostname, "server_port": u.port, "uuid": ident, "password": password, "congestion_control": q.get("congestion_control") or q.get("congestion-control") or "cubic", "udp_relay_mode": q.get("udp_relay_mode") or q.get("udp-relay-mode") or "native", "tls": singbox_tls(u, q)}


def parse_bridge_upstream(line: str, tag: str) -> dict[str, Any] | None:
    scheme = line.split(":", 1)[0].lower()
    if scheme not in BRIDGE_SCHEMES:
        return None
    return parse_hysteria2(line, tag) if scheme in {"hysteria2", "hy2"} else parse_tuic(line, tag)


def bridge_port_map(control: dict[str, Any], cfg: dict[str, Any], blocked: set[int]) -> dict[int, int]:
    base = max(1024, min(60000, int(cfg.get("bridge_socks_base_port") or 18080)))
    sessions = [x for x in (control.get("sessions") or []) if isinstance(x, dict) and int(x.get("session_id") or 0) not in blocked]
    result: dict[int, int] = {}
    for index, session in enumerate(sessions):
        if any(isinstance(x, str) and x.split(":",1)[0].lower() in BRIDGE_SCHEMES for x in (session.get("upstreams") or [])):
            port=base+index
            if port>65535: raise RuntimeError("bridge SOCKS port range exhausted")
            result[int(session.get("session_id") or 0)] = port
    return result


def build_singbox_config(control: dict[str, Any], cfg: dict[str, Any], blocked: set[int]) -> tuple[dict[str, Any] | None, dict[int, int], list[str]]:
    ports=bridge_port_map(control,cfg,blocked)
    if not ports: return None, {}, []
    inbounds: list[dict[str, Any]]=[]; outbounds: list[dict[str, Any]]=[]; rules: list[dict[str, Any]]=[]; skipped: list[str]=[]
    for session in control.get("sessions") or []:
        if not isinstance(session,dict): continue
        sid=int(session.get("session_id") or 0); cid=int(session.get("customer_id") or 0)
        if sid not in ports or sid in blocked: continue
        tags=[]
        for line in session.get("upstreams") or []:
            if not isinstance(line,str) or line.split(":",1)[0].lower() not in BRIDGE_SCHEMES: continue
            tag=f"bv-sb-{cid}-{len(tags)+1}"
            try: outbound=parse_bridge_upstream(line.strip(),tag)
            except Exception as exc:
                skipped.append(f"customer={cid} bridge-parse={line.split(':',1)[0]} error={exc}"); continue
            if outbound is not None: outbounds.append(outbound); tags.append(tag)
        if not tags: ports.pop(sid,None); continue
        inbound_tag=f"bv-bridge-in-{sid}"; group_tag=f"bv-bridge-auto-{cid}"
        inbounds.append({"type":"socks","tag":inbound_tag,"listen":"127.0.0.1","listen_port":ports[sid]})
        outbounds.append({"type":"urltest","tag":group_tag,"outbounds":tags,"url":str(cfg.get("bridge_test_url") or "https://www.gstatic.com/generate_204"),"interval":str(cfg.get("bridge_test_interval") or "2m"),"tolerance":int(cfg.get("bridge_test_tolerance_ms") or 80),"interrupt_exist_connections":True})
        rules.append({"inbound":[inbound_tag],"action":"route","outbound":group_tag})
    if not inbounds: return None, {}, skipped
    return {"log":{"level":str(cfg.get("singbox_log_level") or "warn")},"inbounds":inbounds,"outbounds":outbounds,"route":{"rules":rules}},ports,skipped


def build_xray_config(control: dict[str, Any], cfg: dict[str, Any], bridge_ports: dict[int, int] | None = None, blocked: set[int] | None = None) -> tuple[dict[str, Any], dict[str, dict[str, int]], list[str]]:
    sessions = control.get("sessions") if isinstance(control.get("sessions"), list) else []
    blocked=blocked or set(); bridge_ports=bridge_ports or {}
    clients=[]; outbounds=[{"tag":"blocked","protocol":"blackhole","settings":{}}]; rules=[]; balancers=[]; email_map={}; skipped=[]
    for session in sessions:
        if not isinstance(session,dict): continue
        sid,cid=int(session.get("session_id") or 0),int(session.get("customer_id") or 0); email,ident=str(session.get("email") or ""),str(session.get("uuid") or "")
        if sid<=0 or cid<=0 or not email or not ident or sid in blocked: continue
        prefix=f"bv-out-{cid}-"; usable=0
        for line in session.get("upstreams") or []:
            if not isinstance(line,str): continue
            scheme=line.split(":",1)[0].lower()
            if scheme in BRIDGE_SCHEMES: continue
            tag=f"{prefix}{usable+1}"
            try: outbound=parse_upstream(line.strip(),tag)
            except Exception as exc: skipped.append(f"customer={cid} parse={scheme} error={exc}"); continue
            if outbound is None: skipped.append(f"customer={cid} unsupported={scheme}"); continue
            outbounds.append(outbound); usable+=1
        if sid in bridge_ports:
            tag=f"{prefix}{usable+1}"; outbounds.append({"tag":tag,"protocol":"socks","settings":{"servers":[{"address":"127.0.0.1","port":int(bridge_ports[sid])}]}}); usable+=1
        if usable==0: skipped.append(f"customer={cid} has no usable gateway upstream"); continue
        clients.append({"id":ident,"email":email,"level":0}); balancer_tag=f"bv-bal-{cid}"; balancers.append({"tag":balancer_tag,"selector":[prefix],"strategy":{"type":"roundRobin"}}); rules.append({"type":"field","user":[email],"balancerTag":balancer_tag})
        email_map[email]={"session_id":sid,"customer_id":cid,"lease_bytes":max(0,int(session.get("lease_bytes") or 0)),"last_seq":max(0,int(session.get("last_seq") or 0))}
    inbound={"tag":"bluevpn-gateway-in","listen":str(cfg.get("listen_host") or "0.0.0.0"),"port":int(cfg.get("listen_port") or control.get("node",{}).get("public_port") or 443),"protocol":"vless","settings":{"clients":clients,"decryption":"none"},"streamSettings":{"network":"tcp","security":"tls","tlsSettings":{"certificates":[{"certificateFile":str(cfg["cert_file"]),"keyFile":str(cfg["key_file"])}]}}}
    config={"log":{"loglevel":str(cfg.get("xray_log_level") or "warning")},"api":{"tag":"api","listen":str(cfg.get("api_server") or "127.0.0.1:10085"),"services":["StatsService"]},"stats":{},"policy":{"levels":{"0":{"statsUserUplink":True,"statsUserDownlink":True}}},"inbounds":[inbound],"outbounds":outbounds,"routing":{"domainStrategy":"AsIs","rules":rules,"balancers":balancers}}
    return config,email_map,skipped


class Agent:
    def __init__(self, cfg: dict[str, Any]):
        self.cfg=cfg; self.base=str(cfg["manager_url"]).rstrip("/"); self.node_id=int(cfg["node_id"]); self.secret=str(cfg["node_secret"]); self.config_path=Path(str(cfg.get("_config_path") or "/etc/bluevpn-gateway/agent.json"))
        self.xray=str(cfg.get("xray_path") or "/usr/local/bin/xray"); self.singbox=str(cfg.get("singbox_path") or "/usr/local/bin/sing-box")
        self.xray_config=Path(str(cfg.get("xray_config_path") or "/etc/bluevpn-gateway/xray.json")); self.singbox_config=Path(str(cfg.get("singbox_config_path") or "/etc/bluevpn-gateway/sing-box.json"))
        self.state_path=Path(str(cfg.get("state_path") or "/var/lib/bluevpn-gateway/state.json")); self.log_path=Path(str(cfg.get("xray_log_path") or "/var/log/bluevpn-gateway-xray.log")); self.singbox_log_path=Path(str(cfg.get("singbox_log_path") or "/var/log/bluevpn-gateway-singbox.log"))
        self.poll_seconds=max(5,int(cfg.get("poll_seconds") or 15)); self.usage_seconds=max(3,int(cfg.get("usage_seconds") or 5)); self.heartbeat_seconds=max(10,int(cfg.get("heartbeat_seconds") or 30)); self.http_timeout=max(5,int(cfg.get("http_timeout") or 20))
        self.proc: subprocess.Popen[Any] | None=None; self.singbox_proc: subprocess.Popen[Any] | None=None
        self.state=self._load_state(); self.usage_epoch=str(self.state.get("agent_epoch") or uuid.uuid4().hex); self.state["agent_epoch"]=self.usage_epoch; self.boot_id=uuid.uuid4().hex
        self.config_hash=str(self.state.get("applied_config_hash") or ""); self.policy_hash=str(self.state.get("applied_policy_hash") or ""); self.applied_generation=max(0,int(self.state.get("applied_config_generation") or 0)); self.config_applied_at=str(self.state.get("config_applied_at") or "")
        self.desired_config_hash=""; self.desired_policy_hash=""; self.desired_generation=0; self.applied_xray_hash=""; self.applied_singbox_hash=""; self.email_map={}; self.last_control={}; self.stop=False
        self.locally_blocked={int(x) for x in self.state.get("locally_blocked",[]) if str(x).isdigit()}; self._save_state()
        self.xray_version=self._binary_version(self.xray,["version"]); self.singbox_available=bool(shutil.which(self.singbox) or Path(self.singbox).exists()); self.singbox_version=self._binary_version(self.singbox,["version"]) if self.singbox_available else "not-installed"

    def _load_state(self) -> dict[str, Any]:
        try:
            data=json.loads(self.state_path.read_text(encoding="utf-8")); return data if isinstance(data,dict) else {}
        except Exception:
            return {"pending":[],"seq":{},"agent_epoch":uuid.uuid4().hex,"last_usage_flush_at":"","locally_blocked":[],"applied_config_generation":0,"applied_config_hash":"","applied_policy_hash":"","config_applied_at":""}

    def _save_state(self) -> None:
        self.state["locally_blocked"]=sorted(self.locally_blocked) if hasattr(self,"locally_blocked") else self.state.get("locally_blocked",[])
        self.state_path.parent.mkdir(parents=True,exist_ok=True); payload=json.dumps(self.state,ensure_ascii=False,separators=(",",":")); fd,tmp=tempfile.mkstemp(prefix="state.",suffix=".tmp",dir=str(self.state_path.parent))
        try:
            with os.fdopen(fd,"w",encoding="utf-8") as f: f.write(payload); f.flush(); os.fsync(f.fileno())
            os.replace(tmp,self.state_path)
        finally:
            try:
                if os.path.exists(tmp): os.unlink(tmp)
            except OSError: pass

    @staticmethod
    def _binary_version(binary: str, args: list[str]) -> str:
        try:
            p=subprocess.run([binary,*args],capture_output=True,text=True,timeout=5,check=False); return (p.stdout or p.stderr or "unknown").splitlines()[0][:64]
        except Exception as exc: return f"unavailable:{exc}"[:64]

    def _signature(self, method: str, route: str, body: bytes, timestamp: str) -> str:
        message = f"{timestamp}\n{method.upper()}\n{route}\n{hashlib.sha256(body).hexdigest()}".encode()
        return hmac.new(self.secret.encode(), message, hashlib.sha256).hexdigest()

    def request(self, method: str, route: str, payload: dict[str, Any] | None = None) -> dict[str, Any]:
        body = b"" if payload is None else json.dumps(payload, ensure_ascii=False, separators=(",", ":")).encode()
        ts = str(int(time.time()))
        headers = {
            "User-Agent": f"BlueVPN-Gateway/{AGENT_VERSION}",
            "Accept": "application/json",
            "X-BlueVPN-Gateway-ID": str(self.node_id),
            "X-BlueVPN-Gateway-Timestamp": ts,
            "X-BlueVPN-Gateway-Signature": self._signature(method, route, body, ts),
        }
        if payload is not None: headers["Content-Type"] = "application/json"
        req = urllib.request.Request(self.base + "/wp-json" + route, data=(body if payload is not None else None), headers=headers, method=method.upper())
        with urllib.request.urlopen(req, timeout=self.http_timeout) as r:
            data = json.loads(r.read().decode("utf-8"))
            if not isinstance(data, dict): raise RuntimeError("invalid Manager response")
            return data

    def _persist_credentials(self, secret: str, generation: int) -> None:
        secret=str(secret or "").strip(); generation=max(1,int(generation or 1))
        if len(secret) < 20:
            raise RuntimeError("credential update rejected")
        current_generation=max(1,int(self.cfg.get("credential_generation") or 1))
        if generation < current_generation:
            return
        self.secret=secret; self.cfg["node_secret"]=secret; self.cfg["credential_generation"]=generation
        payload={k:v for k,v in self.cfg.items() if not str(k).startswith("_")}
        self.config_path.parent.mkdir(parents=True,exist_ok=True)
        fd,tmp=tempfile.mkstemp(prefix="agent.",suffix=".tmp",dir=str(self.config_path.parent))
        try:
            with os.fdopen(fd,"w",encoding="utf-8") as f:
                json.dump(payload,f,ensure_ascii=False,indent=2); f.write("\n"); f.flush(); os.fsync(f.fileno())
            os.chmod(tmp,0o600); os.replace(tmp,self.config_path)
        finally:
            try:
                if os.path.exists(tmp): os.unlink(tmp)
            except OSError: pass
        self.state["credential_generation"]=generation; self._save_state()
        LOG.info("gateway credential rotated to generation %s",generation)

    def _apply_credential_update(self, response: dict[str, Any]) -> None:
        update=response.get("credential_update")
        if not isinstance(update,dict): return
        secret=str(update.get("node_secret") or "").strip(); generation=max(0,int(update.get("generation") or 0))
        if secret and generation>0:
            self._persist_credentials(secret,generation)

    def _pending_bytes(self) -> dict[int,int]:
        totals={}
        for event in self.state.setdefault("pending",[]):
            if not isinstance(event,dict): continue
            sid=int(event.get("session_id") or 0); totals[sid]=totals.get(sid,0)+max(0,int(event.get("uplink_bytes") or 0))+max(0,int(event.get("downlink_bytes") or 0))
        return totals

    def _sync_policy(self, control: dict[str, Any]) -> None:
        seqs=self.state.setdefault("seq",{}); pending=self._pending_bytes(); allowed=set()
        for session in control.get("sessions") or []:
            if not isinstance(session,dict): continue
            sid=int(session.get("session_id") or 0)
            if sid<=0: continue
            allowed.add(sid); seqs[str(sid)]=max(int(seqs.get(str(sid),0)),int(session.get("last_seq") or 0)); lease=max(0,int(session.get("lease_bytes") or 0))
            if lease==0 or pending.get(sid,0)<lease: self.locally_blocked.discard(sid)
            else: self.locally_blocked.add(sid)
        for sid in list(self.locally_blocked):
            if sid not in allowed and pending.get(sid,0)==0: self.locally_blocked.discard(sid)
        self._save_state()

    def _apply_singbox(self, control: dict[str, Any]) -> tuple[dict[int,int],list[str]]:
        if not self.singbox_available:
            has_bridge=any(isinstance(line,str) and line.split(":",1)[0].lower() in BRIDGE_SCHEMES for row in (control.get("sessions") or []) if isinstance(row,dict) for line in (row.get("upstreams") or []))
            return {},(["sing-box not installed; Hysteria2/TUIC bridge unavailable"] if has_bridge else [])
        config,ports,skipped=build_singbox_config(control,self.cfg,self.locally_blocked)
        if config is None: self.stop_singbox(); self.applied_singbox_hash=""; return {},skipped
        generated=json.dumps(config,ensure_ascii=False,indent=2); local_hash=hashlib.sha256(generated.encode()).hexdigest()
        if local_hash==self.applied_singbox_hash and self.singbox_proc is not None and self.singbox_proc.poll() is None: return ports,skipped
        self.singbox_config.parent.mkdir(parents=True,exist_ok=True); fd,tmp=tempfile.mkstemp(prefix="sing-box.",suffix=".json",dir=str(self.singbox_config.parent))
        try:
            with os.fdopen(fd,"w",encoding="utf-8") as f: f.write(generated)
            check=subprocess.run([self.singbox,"check","-c",tmp],capture_output=True,text=True,timeout=15,check=False)
            if check.returncode!=0: raise RuntimeError("sing-box config validation failed: "+(check.stderr or check.stdout)[-1600:])
            os.replace(tmp,self.singbox_config)
        finally:
            try:
                if os.path.exists(tmp): os.unlink(tmp)
            except OSError: pass
        self.restart_singbox(); self.applied_singbox_hash=local_hash; return ports,skipped

    def _mark_config_applied(self) -> None:
        # ACK is persisted only after both dataplane validators/restarts succeeded.
        # Heartbeat therefore proves the Manager generation reached the live runtime,
        # instead of merely proving that /config was downloaded.
        self.applied_generation=max(0,int(self.desired_generation)); self.config_hash=self.desired_config_hash; self.policy_hash=self.desired_policy_hash
        self.config_applied_at=time.strftime("%Y-%m-%dT%H:%M:%SZ",time.gmtime()); self.state["applied_config_generation"]=self.applied_generation; self.state["applied_config_hash"]=self.config_hash; self.state["applied_policy_hash"]=self.policy_hash; self.state["config_applied_at"]=self.config_applied_at; self._save_state()

    def apply_config(self, control: dict[str, Any]) -> None:
        self.last_control=control; self.desired_generation=max(0,int(control.get("config_generation") or 0)); self.desired_config_hash=str(control.get("config_hash") or ""); self.desired_policy_hash=str(control.get("policy_hash") or ""); self._sync_policy(control)
        bridge_ports,bridge_skipped=self._apply_singbox(control); config,email_map,skipped=build_xray_config(control,self.cfg,bridge_ports,self.locally_blocked)
        for item in (bridge_skipped+skipped)[:50]: LOG.warning("upstream skipped: %s",item)
        generated=json.dumps(config,ensure_ascii=False,indent=2); local_hash=hashlib.sha256(generated.encode()).hexdigest(); self.email_map=email_map
        if local_hash==self.applied_xray_hash and self.proc is not None and self.proc.poll() is None:
            self._mark_config_applied(); return
        self.xray_config.parent.mkdir(parents=True,exist_ok=True); fd,tmp=tempfile.mkstemp(prefix="xray.",suffix=".json",dir=str(self.xray_config.parent))
        try:
            with os.fdopen(fd,"w",encoding="utf-8") as f: f.write(generated)
            check=subprocess.run([self.xray,"run","-test","-config",tmp],capture_output=True,text=True,timeout=12,check=False)
            if check.returncode!=0: raise RuntimeError("xray config validation failed: "+(check.stderr or check.stdout)[-1200:])
            os.replace(tmp,self.xray_config)
        finally:
            try:
                if os.path.exists(tmp): os.unlink(tmp)
            except OSError: pass
        self.restart_xray(); self.applied_xray_hash=local_hash; self._mark_config_applied(); LOG.info("applied gateway generation=%d sessions=%d blocked=%d hash=%s",self.applied_generation,len(email_map),len(self.locally_blocked),self.config_hash[:12])

    def restart_xray(self) -> None:
        self.stop_xray(); self.log_path.parent.mkdir(parents=True,exist_ok=True); log_file=open(self.log_path,"ab",buffering=0); self.proc=subprocess.Popen([self.xray,"run","-config",str(self.xray_config)],stdout=log_file,stderr=subprocess.STDOUT,start_new_session=True); time.sleep(0.7)
        if self.proc.poll() is not None: raise RuntimeError(f"xray exited during startup with {self.proc.returncode}")

    def restart_singbox(self) -> None:
        self.stop_singbox(); self.singbox_log_path.parent.mkdir(parents=True,exist_ok=True); log_file=open(self.singbox_log_path,"ab",buffering=0); self.singbox_proc=subprocess.Popen([self.singbox,"run","-c",str(self.singbox_config)],stdout=log_file,stderr=subprocess.STDOUT,start_new_session=True); time.sleep(0.7)
        if self.singbox_proc.poll() is not None: raise RuntimeError(f"sing-box exited during startup with {self.singbox_proc.returncode}")

    def stop_xray(self) -> None:
        p=self.proc; self.proc=None
        if p is None or p.poll() is not None: return
        try: p.terminate(); p.wait(timeout=5)
        except Exception:
            try: p.kill()
            except Exception: pass

    def stop_singbox(self) -> None:
        p=self.singbox_proc; self.singbox_proc=None
        if p is None or p.poll() is not None: return
        try: p.terminate(); p.wait(timeout=5)
        except Exception:
            try: p.kill()
            except Exception: pass

    def stats(self) -> list[dict[str, Any]]:
        if not self.email_map or self.proc is None or self.proc.poll() is not None:
            return []
        api = str(self.cfg.get("api_server") or "127.0.0.1:10085")
        p = subprocess.run([self.xray, "api", "statsquery", f"--server={api}", "-pattern", "user>>>", "-reset=true"], capture_output=True, text=True, timeout=10, check=False)
        if p.returncode != 0:
            raise RuntimeError("xray statsquery failed: " + (p.stderr or p.stdout)[-800:])
        try: doc = json.loads(p.stdout or "{}")
        except json.JSONDecodeError: return []
        rows = doc.get("stat") or doc.get("stats") or []
        if not isinstance(rows, list): return []
        totals: dict[str, dict[str, int]] = {}
        for row in rows:
            if not isinstance(row, dict): continue
            name, value = str(row.get("name") or ""), int(row.get("value") or 0)
            parts = name.split(">>>")
            if len(parts) < 4 or parts[0] != "user" or parts[2] != "traffic": continue
            email, direction = parts[1], parts[3]
            if email not in self.email_map or direction not in {"uplink", "downlink"} or value <= 0: continue
            totals.setdefault(email, {"uplink": 0, "downlink": 0})[direction] += value
        events=[]
        seqs = self.state.setdefault("seq", {})
        for email, vals in totals.items():
            meta=self.email_map[email]; sid=str(meta["session_id"]); seq=int(seqs.get(sid, 0))+1; seqs[sid]=seq
            events.append({"event_id": f"{self.node_id}-{sid}-{seq}-{uuid.uuid4().hex}", "session_id": meta["session_id"], "seq": seq, "agent_epoch": self.usage_epoch, "uplink_bytes": vals["uplink"], "downlink_bytes": vals["downlink"], "reported_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())})
        return events

    def _enforce_local_leases(self) -> bool:
        pending=self._pending_bytes(); changed=False
        for meta in self.email_map.values():
            sid=int(meta["session_id"]); lease=max(0,int(meta.get("lease_bytes") or 0))
            if lease>0 and pending.get(sid,0)>=lease and sid not in self.locally_blocked:
                LOG.warning("local quota lease exhausted session=%d pending=%d lease=%d",sid,pending.get(sid,0),lease); self.locally_blocked.add(sid); changed=True
        if changed:
            self._save_state()
            if self.last_control: self.apply_config(self.last_control)
        return changed

    def flush_usage(self) -> bool:
        pending=self.state.setdefault("pending",[]); new_events=self.stats()
        if new_events:
            pending.extend(new_events)
            # Persist immediately after Xray reset=true. If process/network dies,
            # the same event_id + agent_epoch + seq is replayed safely.
            self._save_state()
        local_blocked=self._enforce_local_leases()
        if not pending: self._save_state(); return local_blocked
        batch=pending[:500]; result=self.request("POST","/bluevpn-gateway/v1/usage",{"events":batch})
        if not result.get("ok"): raise RuntimeError("usage rejected")
        del pending[:len(batch)]; self.state["last_usage_flush_at"]=time.strftime("%Y-%m-%dT%H:%M:%SZ",time.gmtime())
        revoked={int(x) for x in (result.get("revoked_session_ids") or result.get("limited_session_ids") or []) if str(x).isdigit()}
        if revoked: self.locally_blocked.update(revoked)
        self._save_state()
        if revoked and self.last_control: self.apply_config(self.last_control)
        finite=any(int(meta.get("lease_bytes") or 0)>0 for meta in self.email_map.values())
        return bool(result.get("reload_required")) or bool(revoked) or (int(result.get("accepted") or 0)>0 and finite)

    @staticmethod
    def _memory_used_pct() -> float:
        try:
            values: dict[str, int] = {}
            for line in Path("/proc/meminfo").read_text(encoding="utf-8").splitlines():
                if ":" not in line:
                    continue
                key, raw = line.split(":", 1)
                values[key] = int(raw.strip().split()[0])
            total = values.get("MemTotal", 0)
            available = values.get("MemAvailable", 0)
            if total <= 0:
                return 0.0
            return round(max(0.0, min(100.0, ((total - available) / total) * 100.0)), 2)
        except Exception:
            return 0.0

    @staticmethod
    def _uptime_seconds() -> int:
        try:
            return max(0, int(float(Path("/proc/uptime").read_text(encoding="utf-8").split()[0])))
        except Exception:
            return 0

    @staticmethod
    def _cpu_load_pct() -> float:
        try:
            load1 = os.getloadavg()[0]
            cpus = max(1, os.cpu_count() or 1)
            return round(max(0.0, min(1000.0, (load1 / cpus) * 100.0)), 2)
        except Exception:
            return 0.0

    def _memory_total_mb(self) -> int:
        try:
            for line in Path('/proc/meminfo').read_text(encoding='utf-8').splitlines():
                if line.startswith('MemTotal:'):
                    return max(256,int(line.split()[1])//1024)
        except Exception:
            pass
        return 256

    def heartbeat(self, error: str = "") -> None:
        try:
            running=self.proc is not None and self.proc.poll() is None; singbox_running=self.singbox_proc is None or self.singbox_proc.poll() is None
            payload={"agent_version":AGENT_VERSION,"xray_version":self.xray_version,"singbox_version":self.singbox_version,"config_generation":self.applied_generation,"config_hash":self.config_hash,"policy_hash":self.policy_hash,"config_applied_at":self.config_applied_at,"desired_config_generation":self.desired_generation,"desired_config_hash":self.desired_config_hash,"error":error[:1800],"xray_running":running,"singbox_running":singbox_running,"active_sessions":len(self.email_map),"pending_usage_events":len(self.state.get("pending") or []),"cpu_load_pct":self._cpu_load_pct(),"memory_used_pct":self._memory_used_pct(),"cpu_cores":max(1,int(os.cpu_count() or 1)),"memory_total_mb":self._memory_total_mb(),"uptime_seconds":self._uptime_seconds(),"agent_boot_id":self.boot_id,"last_usage_flush_at":str(self.state.get("last_usage_flush_at") or ""),"locally_blocked_sessions":len(self.locally_blocked)}
            response=self.request("POST","/bluevpn-gateway/v1/heartbeat",payload)
            self._apply_credential_update(response)
        except Exception as exc: LOG.warning("heartbeat failed: %s",exc)

    def run(self) -> None:
        next_config = 0.0; next_usage = 0.0; next_heartbeat = 0.0; force_config = True; last_error = ""
        while not self.stop:
            now = time.monotonic()
            try:
                if force_config or now >= next_config:
                    control = self.request("GET", "/bluevpn-gateway/v1/config")
                    if not control.get("ok"): raise RuntimeError("Manager config rejected")
                    self.apply_config(control); force_config=False; next_config=now+self.poll_seconds; last_error=""
                if now >= next_usage:
                    force_config = self.flush_usage() or force_config; next_usage=now+self.usage_seconds
                if now >= next_heartbeat:
                    self.heartbeat(last_error); next_heartbeat=now+self.heartbeat_seconds
                if self.proc is not None and self.proc.poll() is not None:
                    raise RuntimeError(f"xray stopped unexpectedly ({self.proc.returncode})")
                if self.singbox_proc is not None and self.singbox_proc.poll() is not None:
                    raise RuntimeError(f"sing-box stopped unexpectedly ({self.singbox_proc.returncode})")
            except (urllib.error.URLError, urllib.error.HTTPError, TimeoutError, OSError, RuntimeError) as exc:
                last_error=str(exc); LOG.error("agent loop: %s", exc); self.heartbeat(last_error); force_config=True
                time.sleep(min(10, max(2, self.poll_seconds//2)))
            time.sleep(1)
        try:
            self.flush_usage()
        except Exception as exc: LOG.warning("final usage flush failed: %s", exc)
        self.stop_xray(); self.stop_singbox()


def load_config(path: str) -> dict[str, Any]:
    data=json.loads(Path(path).read_text(encoding="utf-8"))
    required=["manager_url","node_id","node_secret","cert_file","key_file"]
    missing=[x for x in required if not data.get(x)]
    if missing: raise SystemExit("Missing config keys: " + ", ".join(missing))
    return data


def main() -> int:
    ap=argparse.ArgumentParser(); ap.add_argument("--config", default="/etc/bluevpn-gateway/agent.json"); args=ap.parse_args()
    cfg=load_config(args.config); cfg["_config_path"]=args.config
    logging.basicConfig(level=getattr(logging, str(cfg.get("log_level") or "INFO").upper(), logging.INFO), format="%(asctime)s %(levelname)s %(message)s")
    agent=Agent(cfg)
    def stop(_sig: int, _frame: Any) -> None: agent.stop=True
    signal.signal(signal.SIGTERM, stop); signal.signal(signal.SIGINT, stop)
    agent.run(); return 0


if __name__ == "__main__":
    raise SystemExit(main())
