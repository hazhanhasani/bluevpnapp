# BlueVPN Gateway — Phase 6 / 5.1.10

Phase 6 removes day-one credential/config work from normal Gateway operations and hardens provisioning for production.

## One-click enrollment

1. In WordPress, create a Gateway with only **Public Host**.
2. Manager issues a cryptographically random, **one-time enrollment token** valid for 30 minutes.
3. Copy the single install command shown by Manager and run it as root on the VPS.
4. The installer exchanges the token over HTTPS for Node ID/Secret and a canonical `agent.json`, downloads the exact 5.1.10 agent/service assets from the installed Manager, validates Python, writes credentials with mode `0600`, enables systemd, and starts only when required TLS/Xray prerequisites exist.
5. The token is invalidated immediately after a successful exchange.

The raw Node Secret is no longer something the administrator has to copy into `agent.json`.

## Credential rotation

- Manager rotates healthy Gateway secrets automatically every 30 days.
- The previous secret remains accepted for a bounded 24-hour handoff window.
- An Agent authenticating with the previous secret receives the new credential in its authenticated heartbeat response.
- Agent atomically persists the new secret to `/etc/bluevpn-gateway/agent.json` with mode `0600` and immediately signs future calls with it.
- Manual rotation uses the same dual-secret protocol; no SSH edit is required.

## Enrollment watchdog

If a token is consumed but the Node never sends a heartbeat within 15 minutes, Sentinel raises `GATEWAY_ENROLLMENT_NO_HEARTBEAT_<id>`. The incident is automatically resolved after the first healthy authenticated heartbeat.

## fail-safe rules

- The authoritative enrollment verifier stores only a SHA-256 derived hash; the one-time admin display cache is encrypted with the Manager secret and deleted after display. Tokens are single-use.
- Repeated enrollment attempts are rate-limited per Node/source IP.
- The installer refuses to start the data plane when Xray or required TLS files are missing.
- Existing Autopilot, Safe Rollout, quota fail-closed, Circuit Breaker and zero-downtime handoff remain authoritative.
