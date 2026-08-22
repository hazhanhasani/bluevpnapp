# BlueVPN Gateway — Phase 3 start

This patch keeps the completed 5.1.5 Phase 2 data plane unchanged and starts Phase 3 as a rollback-safe control-plane layer.

## Implemented in this start

- Deterministic 0–100 gateway health score using existing Phase 2 heartbeat telemetry.
- Ranking keeps manual `priority` authoritative, then prefers healthier and less-loaded nodes.
- A fleet snapshot is persisted on the existing BlueVPN cleanup cadence.
- No automatic drain, no live session migration, and no routing mutation yet.

## Next Phase 3 increments

1. Feed the Phase 3 health score into `BlueVPN_Gateway::healthy_nodes()` behind a feature flag.
2. Add circuit-breaker hysteresis (open / half-open / closed) so a flapping node cannot thrash assignments.
3. Add config-generation acknowledgement from gateway agents.
4. Add staged rollout/canary percentages and automatic rollback on heartbeat/config errors.
5. Add session migration handoff so replica replacement does not cause avoidable reconnect storms.

The observational-first step is intentional: it provides measurable fleet state before Phase 3 begins making placement decisions.
