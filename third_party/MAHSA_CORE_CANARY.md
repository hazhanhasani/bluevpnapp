# Mahsa-Core Canary integration

BlueVPN production remains on the stock v2rayNG / 2dust AndroidLibXrayLite runtime.

The optional `mahsa-canary` CI mode builds a replacement `libv2ray.aar` from:

- Repository: `GFW-knocker/AndroidLibXrayLite`
- Commit: `8a5c4d4549338e13fa00ac1fe1e431074823f339`
- Core declared by that commit: `GFW-knocker/Xray-core v1.26.5-mahsa-r1`
- Go declared by that commit: `1.26.3`
- `golang.org/x/mobile`: `v0.0.0-20260217195705-b56b3793a9c4`
- gomobile Android API: 21

Why this is a canary rather than a runtime-loaded second AAR:

Both stock AndroidLibXrayLite and the Mahsa fork export the same `libv2ray`
Java/Kotlin package/classes. Bundling both unchanged AARs into a single APK
would create duplicate-class conflicts. BlueVPN therefore builds separate
stock and Mahsa canary APKs from the same application source and compares
their privacy-safe runtime telemetry in WordPress/BlueAI.

Promotion is intentionally manual. BlueAI may recommend promote/reject for a
network/operator cohort after enough samples, but production builds default to
`stock` and a `full` production release refuses `mahsa-canary`.

Licenses remain those of the upstream projects and must be preserved:
AndroidLibXrayLite fork is LGPL-3.0; GFW-knocker/Xray-core is MPL-2.0.
