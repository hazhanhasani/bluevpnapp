# BlueVPN 4.6.10 Build and Test

## Completed in this artifact environment

- `python scripts/validate_release.py` — PASS.
- `python -m unittest discover -s tests -v` — 168 tests PASS.
- PHP 8.4-compatible syntax lint across `bluevpn-manager` and `bluevpn-site` — PASS using the available PHP runtime.
- Pure Kotlin `BlueVpnWarpPolicy` behavioral test — PASS on JVM.
- `BlueVpnWarpEngine.kt` Kotlin compile against Android/v2rayNG compatibility stubs + kotlinx-coroutines — PASS.
- Final ZIP integrity (`unzip -t`) — PASS.

## Android build gate

The release workflow now requires both:

1. `:app:compilePlaystoreReleaseKotlin`
2. `:app:assemblePlaystoreRelease`

against pinned v2rayNG `2.2.6`, pinned AndroidLibXrayLite resolution, NDK `29.0.14206865`, and pinned Aether commit `a26159b82a70048b459e0128213c71767abecb8a`.

A full Gradle build could not be run inside this artifact sandbox because the supplied ZIP intentionally does not contain `upstream/V2rayNG`, and outbound DNS to `github.com` is unavailable. A real checkout was attempted and failed with `Could not resolve host: github.com`. The source was therefore not falsely marked as locally APK-built; GitHub Actions remains the reproducible full-build gate.

## Main regression coverage changed in 4.6.10

- bounded parallel endpoint racing and cancellation
- scored network-scoped LKG + TTL
- adaptive error-specific cooldown
- direct-peer fast path without scan
- Aether process cleanup and forced shutdown
- dynamic port retry
- strict SOCKS + HTTPS + exit-country validation
- explicit Iran-exit rejection
- authoritative Free boolean handling
- public/admin health separation
- OTP device/IP/phone throttling
- BluePay duplicate webhook short-circuit
- existing Free/Premium ownership and isolation suite remains enabled
