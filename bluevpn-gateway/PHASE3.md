# BlueVPN Gateway — Phase 3 baseline (5.1.6+)

پایه Phase 3 که در 5.1.6 تکمیل شد همچنان لایه HA، metering و circuit-breaker است. Safe rollout نسل‌محور 5.1.8 در `PHASE4.md` مستند شده است.

## فعال در این نسخه

- capacity-aware + region-diverse primary/standby placement
- one-minute reconcile + drain
- crash-durable usage queue + agent_epoch/sequence replay guard
- row-locked central quota enforcement
- per-replica local quota lease fail-closed
- sing-box sidecar for Hysteria2/TUIC while Xray remains metering ingress
- circuit breaker: 3 unhealthy observations -> open; 180s hold; 2 healthy observations -> closed
- rollback flag: WordPress option/filter `bluevpn_gateway_phase3_circuit_enabled`

No DB migration is required for the circuit breaker; state is stored in `bluevpn_gateway_phase3_circuit_state`.
