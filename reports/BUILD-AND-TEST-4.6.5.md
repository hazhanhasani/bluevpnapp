# BlueVPN 4.6.5 — WARP Free Entitlement / Control Plane

## Root cause
The Android entitlement gate still treated legacy Free subscription availability as the server-authored Free access state. WordPress also computed `free_access.enabled` only when an active legacy Free subscription existed. Therefore a guest/non-Premium user could have Aether/WARP packaged and functional but still be classified as `UNAVAILABLE` before `BlueVpnWarpEngine` was reached.

## Fix
- Free WARP entitlement is independent of legacy public subscription rows.
- WordPress publishes explicit WARP engine policy under `free_access.warp`.
- New DB-backed settings: WARP enable, mode, fallback, guest policy, start timeout.
- Schema bumped to 1.11.0; app settings defaults are merged into existing installs.
- Android persists WARP policy and migrates 4.6.4 devices with WARP enabled by default until authoritative config arrives.
- Guest Free WARP may connect without login.
- Legacy Free Pool is used only when policy explicitly permits fallback.
- Premium remains stock v2rayNG/Xray and is not changed.

## Tests
- validate_release.py: PASS
- Regression suite: 152/152 PASS
- PHP lint: PASS
- Workflow YAML parse: PASS (3/3)
- Python compile: PASS
- Full Gradle/NDK build: not run in this environment; GitHub Actions remains authoritative.
