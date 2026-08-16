# BlueVPN 4.11.6 — Connection Continuity / False Recovery Fix

Root cause:
`BlueVpnLiveReporter` periodically converted repeated quality degradation into
`BlueVpnSystemController.predictiveFailover()`. That path executed a full
`restart()` for both Premium and Free/WARP: stop CoreVpnService, stop the WARP
owner/engine, clear connected state, then start again. Short mobile-network
latency/loss spikes could therefore create the user-visible disconnect/recovery
loop every few minutes.

Fixes:
- predictive quality degradation is advisory and non-destructive;
- current degraded route is penalized for the next connection but never tears
  down the live session;
- Activity/process recovery preserves both Premium and Free/WARP when the actual
  transport is still alive;
- repeated HTTP/RTT verification failures preserve an alive TUN/Aether session
  and retry after 15 seconds without traffic interruption;
- hard recovery is allowed only when CoreVpnService is actually down, or for
  Free/WARP when the WARP engine itself is no longer running;
- Premium no longer starts a redundant second foreground owner because
  CoreVpnService already owns the foreground VPN lifecycle;
- Free/WARP keeps its dedicated Aether lifecycle owner;
- the Free/WARP live notification updater is actually scheduled (it previously
  removed callbacks without reposting the updater).

Validation executed:
- Release validator: PASS
- Python regression suite: 363/363 PASS
- PHP release lint: 24/24 PASS
- GitHub Actions YAML parse: PASS
- Test manifest: exact
- PHP release manifest: exact
- Android Gradle compile/assemble: not re-run locally because pinned upstream v2rayNG is bootstrapped by GitHub CI.
