# BlueVPN 4.11.4 — Full-Pool Background AI Optimizer

When Android background restrictions become fully unrestricted, BlueVPN now
starts a progressive full-pool optimizer for the active entitlement.

Behavior:
- triggers after the Battery Optimization / Background Data permission state
  transitions to ready, and also self-heals on the next app resume if permission
  was already granted before this version;
- tests every allowed config in the current entitlement pool, not only the
  8-route fast lane used by the blocking connect UI;
- runs in batches of 10 to bound radio/battery/CPU use;
- never tears down an active VPN to benchmark; if a tunnel is active, the work
  remains pending until an idle window;
- performs two fresh v2rayNG TestService passes per route;
- derives average RTT, simple jitter, repeatability/loss and a quality score;
- classifies each route as FAST, STABLE, RESERVE or FAILED;
- persists results per physical-network fingerprint + entitlement identity for
  six hours;
- feeds results into BlueVpnIntelligenceCore route history and SmartSelector;
- fresh background evidence has more weight than cloud/personal heuristics;
- Servers UI exposes the resulting category for the user's current network;
- Settings shows optimizer counts and provides a manual Full Config Test action.

The normal pre-connect fast lane remains bounded for UX. Its purpose is instant
connection. The full exhaustive test is now moved to the background where it
belongs.

Validation executed:
- Release validator: PASS
- Python regression suite: 374/374 PASS
- PHP release lint: 24/24 PASS
- GitHub Actions YAML parse: PASS
- Test manifest: exact
- PHP release manifest: exact
- Android Gradle compile/assemble: not re-run locally because pinned upstream
  v2rayNG is materialized by GitHub CI.
