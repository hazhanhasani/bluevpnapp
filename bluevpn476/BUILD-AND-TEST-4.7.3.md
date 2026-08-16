# BlueVPN 4.7.3 build/test summary

## Fixed P0 regressions

1. Free WARP/Aether was owned only by the application process without an explicit foreground owner, making it vulnerable when Home Activity left the foreground. 4.7.3 adds `BlueVpnWarpKeepAliveService` with `START_STICKY`, `stopWithTask=false`, and explicit-disconnect ownership.
2. Strategy scan backoff was checked before per-network LKG/direct probing. A previous failure could therefore make a known-good WARP route appear immediately unavailable. 4.7.3 probes LKG/direct candidates first and invalidates a cached edge immediately when its probe fails.

## Executed locally

- Python release/regression suite: 178/178 PASS.
- `scripts/validate_release.py`: PASS.
- Python compile check for CI scripts: PASS.
- PHP lint for BlueVPN Manager and site: PASS.

## Android build

A full Gradle Android build cannot be executed in this sandbox because the pinned upstream v2rayNG checkout is not vendored in this ZIP and external checkout is unavailable here. GitHub Actions remains the authoritative full Android compile/assemble environment.
