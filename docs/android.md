# Android

## Source layout

Canonical BlueVPN Android files live under `android-source/`. The supported build does not compile that directory as a standalone Android project; CI checks out the pinned v2rayNG source and applies the overlay before Gradle runs.

Important tooling lives in `scripts/`, including Android preparation, release optimization and runtime hardening helpers.

## Connection model

The application distinguishes connection phases instead of treating a started core process as a verified VPN connection. The connection lifecycle includes preparation, connection, internet verification, connected state, recovery and failure handling.

Server selection and recovery use runtime information such as recent results and failure classification. Soft failures such as transient DNS/egress verification should not automatically poison a server forever, while hard configuration/core/server failures may receive stronger penalties.

## Control plane

Android consumes remote configuration and account/entitlement state from the BlueVPN Manager. Network operations should use bounded timeouts and transport failover. Retried state-changing operations must preserve a stable request identifier so server-side deduplication can prevent duplicate effects.

## Locations

The location UI operates on server/profile identifiers derived from local MMKV state and entitlement data. Because persisted snapshots can be stale or partially corrupt, location-pool decoding is hardened before release compilation: blank/null identifiers and corrupt rows must not crash iteration of the pool, and a bad row must not invalidate the entire usable snapshot.

## Updates

Release APKs are architecture-specific (`arm64-v8a` and `armeabi-v7a`) so a device does not need to download both native ABIs. R8 and resource shrinking reduce release size, but the signed APK runtime validator remains the final guard against shrinker-related breakage.

The updater should remain fail-safe: transport preparation, download, checksum/signature verification and install handoff must be distinct observable stages. A failed network route should fall back rather than leave the UI indefinitely in a preparation state.

## Diagnostics

User-visible diagnostics should report technical state without exposing account tokens, subscription URLs, secrets or raw private endpoints. Runtime audit events should use normalized error classes rather than unfiltered exception strings whenever possible.
