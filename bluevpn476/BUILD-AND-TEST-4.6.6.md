# BlueVPN 4.6.6 — Build and Test Report

## Executive result

**Source regression gate: PASS.** 167 local source/regression tests pass, the 4.6.6 release validator passes, all BlueVPN Manager PHP files pass PHP 8.4 lint, and Python build/test scripts compile.

**Release APK build in this sandbox: BLOCKED by environment, not reported as PASS.** The sandbox has Java 21 but no Android SDK/NDK, no Gradle and no Rust/Cargo. In addition, the actual checkout attempt for the pinned v2rayNG 2.2.6 source failed because shell DNS could not resolve `github.com`. No APK and no source-built Aether ABI binary were fabricated.

## Baseline 4.6.5

- Existing unit/regression tests: **152 PASS**.
- Existing release validator: **PASS**.
- Full Android baseline compile: **BLOCKED** by missing Android/Rust toolchain and shell DNS.

## 4.6.6 local regression

- `python3 -m unittest -v tests.test_current_release tests.test_warp_adaptive_466`: **167 PASS**.
- `python3 scripts/validate_release.py`: **PASS**.
- PHP runtime used: **PHP 8.4.23**.
- `php -l` across `bluevpn-manager/**/*.php`: **PASS**.
- Python `py_compile` across `scripts/` and `tests/`: **PASS**.
- Version: **4.6.6 / 40606**, no build autobump.

## Aether provenance

- Repository: CluvexStudio/Aether
- Pinned commit: `a26159b82a70048b459e0128213c71767abecb8a`
- Release line at that commit: **v1.6.0**
- Exact downloaded `Cargo.lock` SHA-256: `3f48fd945727564b0e19e79b3bd492c16d9738d7f88f1945956f9eaeb6a66d78`
- `.gitmodules` at the exact commit: not present (404 when checked); no submodule status can be truthfully generated without a Git checkout.
- Host `aether --version` / `aether --help`: **NOT RUN locally** because Cargo/Rust is absent. The build script now makes both mandatory gates when toolchain execution is possible.
- ARM64 source-built binary SHA-256: **NOT RUN/BLOCKED**.
- ARMv7 source-built binary SHA-256: **NOT RUN/BLOCKED**.
- x86_64: not included in Release policy; intended for emulator test only.

## Functional test matrix

| Test | Result | Evidence/limit |
|---|---|---|
| Multiple concurrent Connect ownership invariants | PASS (source regression) | Mutex + generation assertions |
| Cancel/generation invalidation | PASS (source regression) | Generation-token path asserted |
| ProcessBuilder list/no shell | PASS | Regression test |
| Explicit quick/no-quick reconnect | PASS | Regression test |
| stdin closed | PASS | Regression test |
| H3 -> H2 -> H2+Fragment ordering | PASS | Regression test |
| Optional WG/gool policy gates | PASS | Regression test |
| `--no-data-check` absent | PASS | Regression test |
| Port collision bounded alternate port | PASS (source regression) | 1819–1829 allocator asserted |
| SOCKS5 greeting + remote domain CONNECT | PASS (source regression) | Protocol bytes/path asserted |
| Proxied trace / two independent endpoint rule | PASS (source regression) | Validation path asserted |
| Post-bridge failure -> same-attempt Pool fallback | PASS (source regression) | HomeActivity regression assertion |
| `warp_only` no Pool mixing | PASS (existing release regression) | Existing policy gate retained |
| Free/Premium bridge separation | PASS (existing release regression) | Private bridge subscription + premium integrity checks |
| PHP 8.4 lint | PASS | Actual PHP 8.4.23 run |
| Schema-1/old install fallback defaults | PASS (source regression) | `opt*`/defaults and schema-2 defaults asserted |
| Log rotation | PASS (source regression) | 512 KiB current + `.1` |
| Android Kotlin/Gradle compile | BLOCKED | Android SDK/Gradle unavailable; upstream checkout DNS failure |
| Aether host source compile | BLOCKED | Rust/Cargo unavailable |
| Aether ARM64/ARMv7 source compile | BLOCKED | Rust + Android NDK unavailable |
| APK Release + SHA-256 | NOT PRODUCED | Cannot truthfully create without Android build |
| Android 8–16 real devices | NOT RUN | No attached Android devices/emulators |
| Wi‑Fi real WARP connection | NOT RUN | No Android runtime/network harness |
| همراه اول / ایرانسل / رایتل | NOT RUN | Operator networks unavailable |
| IPv4-only / IPv6/NAT64 | NOT RUN | Network lab unavailable |
| UDP open/blocked/throttled | NOT RUN | Network impairment lab unavailable |
| Packet loss/high latency | NOT RUN | Network impairment lab unavailable |
| DNS/IPv4/IPv6 leak | NOT RUN | Requires built APK/device |
| 30 connect/disconnect cycles | NOT RUN | Requires built APK/device |
| Doze/screen-off/activity recreation | NOT RUN | Requires device instrumentation |
| Actual process/port residue after Stop | NOT RUN | Requires Android binary/runtime |

## Connection-quality comparison

No real device/operator measurements were available, so performance numbers are deliberately **not invented**.

| Metric | 4.6.5 | 4.6.6 | Status |
|---|---:|---:|---|
| Warm reconnect P50/P95 | NOT MEASURED | NOT MEASURED | Device test required |
| Cold connect P50/P95 | NOT MEASURED | NOT MEASURED | Device test required |
| Network-change recovery P95 | NOT MEASURED | NOT MEASURED | Device test required |
| False Connected count | NOT MEASURED | NOT MEASURED | Device matrix required |
| Fallback ratio | NOT MEASURED | NOT MEASURED | Operator matrix required |
| Disconnect cleanup | NOT MEASURED | NOT MEASURED | Instrumented APK required |

Source-level behavior changes are measurable: 4.6.5 used a shallower startup/fallback boundary, while 4.6.6 requires SOCKS protocol/data proof before bridge handoff and routes post-bridge failure into same-generation Pool fallback.

## Remaining acceptance items blocked by this environment

A full acceptance sign-off still requires the real Gradle APK build, source builds of Aether for ARM64/ARMv7, `--help`/`--version` execution from that exact source, ABI SHA-256/ELF/16 KiB verification, and the physical device/operator/leak/performance matrix. These are marked blocked or NOT RUN rather than passed.

## Raw logs

- `reports/baseline-4.6.5.log`
- `reports/regression-4.6.6.log`
- `reports/android-build-attempt-4.6.6.log`

## Final rerun in current session

- 167/167 Python regression tests: PASS.
- Release validator: PASS.
- PHP 8.4.23 lint across BlueVPN Manager: PASS.
- Python bytecode compile for scripts/tests: PASS.
- Android build retry: BLOCKED before Gradle because `upstream/V2rayNG` is not present in this sandbox; Gradle, Android SDK/NDK, Cargo and Rust are also unavailable. See `reports/android-build-rerun-4.6.6.log`.
