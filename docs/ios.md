# iOS

`bluevpn-ios/` contains the Swift application source and project configuration.

## Versioning

iOS marketing/build metadata is synchronized from the canonical BlueVPN project version. Hand-editing the iOS version independently from `version.json` is not supported.

## Control plane

The iOS client consumes BlueVPN control-plane APIs and release/account state. Transport fallback should be implemented deliberately for both read and state-changing operations; state-changing retries require the same idempotency discipline used by the other clients.

## Build

The iOS workflow participates in the central Project Health fan-out. Treat the actual workflow result as authoritative for what was built or validated in a given release; a synchronized source version alone does not prove a signed distributable was produced.
