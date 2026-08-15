# BlueVPN 4.8.2 build/test summary

## WARP Iran-exit recovery
- Tracks EXIT_IRAN per network signature across distinct WARP strategies.
- After 3 distinct IR exits, marks the current WARP identity/session as poisoned for that network.
- On the next connection attempt, quarantines the poisoned Aether identity and starts with a fresh identity/session.
- Identity rotation is limited to once per 6 hours per network to avoid registration storms/rate limiting.
- Keeps at most 2 quarantined identities.
- Clears stale WARP LKG, strategy backoff, failure counters and endpoint cache after a controlled identity rotation.
- A successful non-Iran WARP connection clears the poison state.
- Iran exits are still fail-closed and are never reported as CONNECTED.
- If WordPress Free Pool fallback is enabled, the existing fallback path remains available after WARP recovery fails.

## Validation executed
- Python regression suite: 212/212 PASS
- Release validator: PASS
- PHP syntax lint: 36/36 PASS
- GitHub Actions YAML parse: 3/3 PASS

Full Android Gradle compilation remains a CI step because the package bootstraps the pinned v2rayNG upstream during GitHub Actions.
