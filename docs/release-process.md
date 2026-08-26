# Release Process

## Source of truth

`version.json` is the canonical project version and component-version contract.

Version format:

```text
X.Y.Z
0 <= Y <= 10
0 <= Z <= 10
```

The release helpers enforce rollover rules and synchronization across shipped components.

## Normal release path

1. A real source change lands on `main`.
2. `BlueVPN Project Health` runs the static/release regression gate.
3. If the gate is green, the version/publish job resolves the next synchronized release version.
4. The release bot writes the project-wide version update to `main`.
5. Platform workflows are dispatched at the exact release SHA.
6. Android, Windows, iOS, Manager, Theme and health workflows publish or validate their own artifacts.
7. BlueVPN Sentinel reports actionable workflow failures independently.

## Project Health gate

The full gate includes, among other checks:

- syntax validation for tracked project formats;
- release metadata/version synchronization;
- bundle/workflow integrity;
- release-test manifest equality;
- full Python regression suite.

A failure in the gate prevents publication fan-out.

## Android publication

The Android build prepares the pinned v2rayNG source, overlays BlueVPN, verifies native/runtime dependencies, compiles ABI-specific release APKs, signs them, validates the signed APK runtime contract, creates release metadata/checksums and synchronizes Android release metadata to the control plane.

R8/minification and resource shrinking are enabled by BlueVPN's release optimizer. Any change around shrinking must still pass the signed runtime validation step.

## Stable and Beta channels

Published build availability and Stable/Beta promotion are separate concerns. A newly built version can exist in a release channel before it becomes the Stable version exposed to all users. Health reports should therefore distinguish source/project version, published Beta version and promoted Stable version.

## Release troubleshooting

If Project Health fails, fix that failure first; do not infer that a platform APK/installer was published. If a platform build fails after Project Health, inspect the exact platform run at the release SHA rather than an older push-triggered run.
