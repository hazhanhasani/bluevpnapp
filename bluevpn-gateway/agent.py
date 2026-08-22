#!/usr/bin/env python3
"""BlueVPN first-party gateway agent (stdlib-only).

The agent pulls HMAC-authenticated per-user gateway sessions from BlueVPN Manager,
builds an Xray VLESS/TLS gateway configuration, queries Xray per-user byte counters,
and reports idempotent usage deltas back to WordPress/MySQL.

Supported upstream URI types in this first gateway release: VLESS, VMess, Trojan,
and Shadowsocks. Hysteria2/TUIC sources stay in the unified Manager snapshot but are
skipped here until a future gateway transport extension supports them safely.
"""
from __future__ import annotations

import argparse
import base64
import hashlib
import hmac
import json
import logging
import os
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

AGENT_VERSION = "5.1.4"
SUPPORTED = {"vless", "vmess", "trojan", "ss"}
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
        tls: dict[str, Any] = {"serverName": q.get("sni") or q.get("serverName") or q.get("host") or "", "allowInsecure": (q.get("allowInsecure") == "1")}
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
    if scheme not in SUPPORTED:
        return None
    if scheme == "vless":
        return parse_vless(line, tag)
    if scheme == "vmess":
        return parse_vmess(line, tag)
    if scheme == "trojan":
        return parse_trojan(line, tag)
    return parse_ss(line, tag)


def build_xray_config(control: dict[str, Any], cfg: dict[str, Any]) -> tuple[dict[str, Any], dict[str, dict[str, int]], list[str]]:
    sessions = control.get("sessions") if isinstance(control.get("sessions"), list) else []
    clients: list[dict[str, Any]] = []
    outbounds: list[dict[str, Any]] = [{"tag": "blocked", "protocol": "blackhole", "settings": {}}]
    rules: list[dict[str, Any]] = []
    balancers: list[dict[str, Any]] = []
    email_map: dict[str, dict[str, int]] = {}
    skipped: list[str] = []

    for session in sessions:
        if not isinstance(session, dict):
            continue
        sid, cid = int(session.get("session_id") or 0), int(session.get("customer_id") or 0)
        email, ident = str(session.get("email") or ""), str(session.get("uuid") or "")
        if sid <= 0 or cid <= 0 or not email or not ident:
            continue
        prefix = f"bv-out-{cid}-"
        usable = 0
        for line in session.get("upstreams") or []:
            if not isinstance(line, str):
                continue
            tag = f"{prefix}{usable + 1}"
            try:
                outbound = parse_upstream(line.strip(), tag)
            except Exception as exc:
                skipped.append(f"customer={cid} parse={line.split(':',1)[0]} error={exc}")
                continue
            if outbound is None:
                skipped.append(f"customer={cid} unsupported={line.split(':',1)[0].lower()}")
                continue
            outbounds.append(outbound)
            usable += 1
        if usable == 0:
            skipped.append(f"customer={cid} has no Xray-compatible upstream")
            continue
        clients.append({"id": ident, "email": email, "level": 0})
        balancer_tag = f"bv-bal-{cid}"
        balancers.append({"tag": balancer_tag, "selector": [prefix], "strategy": {"type": "roundRobin"}})
        rules.append({"type": "field", "user": [email], "balancerTag": balancer_tag})
        email_map[email] = {"session_id": sid, "customer_id": cid}

    inbound = {
        "tag": "bluevpn-gateway-in",
        "listen": str(cfg.get("listen_host") or "0.0.0.0"),
        "port": int(cfg.get("listen_port") or control.get("node", {}).get("public_port") or 443),
        "protocol": "vless",
        "settings": {"clients": clients, "decryption": "none"},
        "streamSettings": {
            "network": "tcp",
            "security": "tls",
            "tlsSettings": {"certificates": [{"certificateFile": str(cfg["cert_file"]), "keyFile": str(cfg["key_file"])}]},
        },
    }
    config = {
        "log": {"loglevel": str(cfg.get("xray_log_level") or "warning")},
        "api": {"tag": "api", "listen": str(cfg.get("api_server") or "127.0.0.1:10085"), "services": ["StatsService"]},
        "stats": {},
        "policy": {"levels": {"0": {"statsUserUplink": True, "statsUserDownlink": True}}},
        "inbounds": [inbound],
        "outbounds": outbounds,
        "routing": {"domainStrategy": "AsIs", "rules": rules, "balancers": balancers},
    }
    return config, email_map, skipped


class Agent:
    def __init__(self, cfg: dict[str, Any]):
        self.cfg = cfg
        self.base = str(cfg["manager_url"]).rstrip("/")
        self.node_id = int(cfg["node_id"])
        self.secret = str(cfg["node_secret"])
        self.xray = str(cfg.get("xray_path") or "/usr/local/bin/xray")
        self.xray_config = Path(str(cfg.get("xray_config_path") or "/etc/bluevpn-gateway/xray.json"))
        self.state_path = Path(str(cfg.get("state_path") or "/var/lib/bluevpn-gateway/state.json"))
        self.log_path = Path(str(cfg.get("xray_log_path") or "/var/log/bluevpn-gateway-xray.log"))
        self.poll_seconds = max(5, int(cfg.get("poll_seconds") or 20))
        self.usage_seconds = max(5, int(cfg.get("usage_seconds") or 15))
        self.http_timeout = max(5, int(cfg.get("http_timeout") or 20))
        self.proc: subprocess.Popen[Any] | None = None
        self.config_hash = ""
        self.email_map: dict[str, dict[str, int]] = {}
        self.stop = False
        self.state = self._load_state()
        self.xray_version = self._xray_version()

    def _load_state(self) -> dict[str, Any]:
        try:
            data = json.loads(self.state_path.read_text())
            return data if isinstance(data, dict) else {}
        except Exception:
            return {"pending": [], "seq": {}}

    def _save_state(self) -> None:
        self.state_path.parent.mkdir(parents=True, exist_ok=True)
        payload = json.dumps(self.state, ensure_ascii=False, separators=(",", ":"))
        fd, tmp = tempfile.mkstemp(prefix="state.", suffix=".tmp", dir=str(self.state_path.parent))
        try:
            with os.fdopen(fd, "w", encoding="utf-8") as f:
                f.write(payload)
                f.flush()
                os.fsync(f.fileno())
            os.replace(tmp, self.state_path)
        finally:
            try:
                if os.path.exists(tmp): os.unlink(tmp)
            except OSError:
                pass

    def _xray_version(self) -> str:
        try:
            p = subprocess.run([self.xray, "version"], capture_output=True, text=True, timeout=5, check=False)
            return (p.stdout or p.stderr).splitlines()[0][:64]
        except Exception as exc:
            return f"unavailable:{exc}"[:64]

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

    def apply_config(self, control: dict[str, Any]) -> None:
        remote_hash = str(control.get("config_hash") or "")
        config, email_map, skipped = build_xray_config(control, self.cfg)
        if skipped:
            for item in skipped[:30]: LOG.warning("upstream skipped: %s", item)
        generated = json.dumps(config, ensure_ascii=False, indent=2)
        local_hash = hashlib.sha256(generated.encode()).hexdigest()
        self.email_map = email_map
        if remote_hash == self.config_hash and self.proc is not None and self.proc.poll() is None:
            return
        self.xray_config.parent.mkdir(parents=True, exist_ok=True)
        fd, tmp = tempfile.mkstemp(prefix="xray.", suffix=".json", dir=str(self.xray_config.parent))
        try:
            with os.fdopen(fd, "w", encoding="utf-8") as f: f.write(generated)
            check = subprocess.run([self.xray, "run", "-test", "-config", tmp], capture_output=True, text=True, timeout=12, check=False)
            if check.returncode != 0:
                raise RuntimeError("xray config validation failed: " + (check.stderr or check.stdout)[-1200:])
            os.replace(tmp, self.xray_config)
        finally:
            try:
                if os.path.exists(tmp): os.unlink(tmp)
            except OSError: pass
        self.restart_xray()
        self.config_hash = remote_hash or local_hash
        LOG.info("applied gateway config sessions=%d hash=%s", len(email_map), self.config_hash[:12])

    def restart_xray(self) -> None:
        self.stop_xray()
        self.log_path.parent.mkdir(parents=True, exist_ok=True)
        log_file = open(self.log_path, "ab", buffering=0)
        self.proc = subprocess.Popen([self.xray, "run", "-config", str(self.xray_config)], stdout=log_file, stderr=subprocess.STDOUT, start_new_session=True)
        time.sleep(0.7)
        if self.proc.poll() is not None:
            raise RuntimeError(f"xray exited during startup with {self.proc.returncode}")

    def stop_xray(self) -> None:
        p = self.proc
        self.proc = None
        if p is None or p.poll() is not None: return
        try:
            p.terminate(); p.wait(timeout=5)
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
            events.append({"event_id": f"{self.node_id}-{sid}-{seq}-{uuid.uuid4().hex}", "session_id": meta["session_id"], "seq": seq, "uplink_bytes": vals["uplink"], "downlink_bytes": vals["downlink"], "reported_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())})
        return events

    def flush_usage(self) -> bool:
        pending = self.state.setdefault("pending", [])
        new_events = self.stats()
        if new_events: pending.extend(new_events)
        if not pending:
            self._save_state(); return False
        batch = pending[:500]
        result = self.request("POST", "/bluevpn-gateway/v1/usage", {"events": batch})
        if not result.get("ok"): raise RuntimeError("usage rejected")
        del pending[:len(batch)]
        self._save_state()
        return bool(result.get("reload_required"))

    def heartbeat(self, error: str = "") -> None:
        try:
            self.request("POST", "/bluevpn-gateway/v1/heartbeat", {"agent_version": AGENT_VERSION, "xray_version": self.xray_version, "config_hash": self.config_hash, "error": error[:1800]})
        except Exception as exc:
            LOG.warning("heartbeat failed: %s", exc)

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
                    self.heartbeat(last_error); next_heartbeat=now+60
                if self.proc is not None and self.proc.poll() is not None:
                    raise RuntimeError(f"xray stopped unexpectedly ({self.proc.returncode})")
            except (urllib.error.URLError, urllib.error.HTTPError, TimeoutError, OSError, RuntimeError) as exc:
                last_error=str(exc); LOG.error("agent loop: %s", exc); self.heartbeat(last_error); force_config=True
                time.sleep(min(10, max(2, self.poll_seconds//2)))
            time.sleep(1)
        try:
            self.flush_usage()
        except Exception as exc: LOG.warning("final usage flush failed: %s", exc)
        self.stop_xray()


def load_config(path: str) -> dict[str, Any]:
    data=json.loads(Path(path).read_text(encoding="utf-8"))
    required=["manager_url","node_id","node_secret","cert_file","key_file"]
    missing=[x for x in required if not data.get(x)]
    if missing: raise SystemExit("Missing config keys: " + ", ".join(missing))
    return data


def main() -> int:
    ap=argparse.ArgumentParser(); ap.add_argument("--config", default="/etc/bluevpn-gateway/agent.json"); args=ap.parse_args()
    cfg=load_config(args.config)
    logging.basicConfig(level=getattr(logging, str(cfg.get("log_level") or "INFO").upper(), logging.INFO), format="%(asctime)s %(levelname)s %(message)s")
    agent=Agent(cfg)
    def stop(_sig: int, _frame: Any) -> None: agent.stop=True
    signal.signal(signal.SIGTERM, stop); signal.signal(signal.SIGINT, stop)
    agent.run(); return 0


if __name__ == "__main__":
    raise SystemExit(main())
