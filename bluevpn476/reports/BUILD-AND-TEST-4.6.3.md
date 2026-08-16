# BlueVPN 4.6.3 — Free WARP/Aether integration

## Architecture
- Free primary: pinned Aether core -> loopback SOCKS5 127.0.0.1:1819 -> dedicated SOCKS profile -> stock v2rayNG CoreVpnService/TUN.
- Premium: unchanged stock v2rayNG 2.2.6 / Xray subscription runtime.
- Legacy Free subscription pool: bounded fallback only when Aether cannot become ready.
- No Oblivion application source is copied.

## Aether source
- Repository: https://github.com/CluvexStudio/Aether
- Revision: a26159b82a70048b459e0128213c71767abecb8a
- License: AGPL-3.0
- Android ABIs built in CI: arm64-v8a, armeabi-v7a.

## Validation performed in this workspace
- `python scripts/validate_release.py`: PASS.
- `python -m unittest tests.test_current_release`: 151/151 PASS.
- Python syntax compilation: PASS.
- JSON metadata parse: PASS.
- GitHub Actions YAML parse: PASS (3 workflows).
- PHP 8.4.23 lint: PASS (22 manager PHP files).

## Not executed locally
A full Android Gradle build and Aether Android cross-compile were not executable in this isolated workspace because the complete checked-out v2rayNG upstream/Android SDK network build environment is not present here. GitHub Actions is configured to build Aether from the pinned source before Gradle and must be the final compile/runtime gate.
