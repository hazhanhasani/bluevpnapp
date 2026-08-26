# Contributing to BlueVPN

## Before changing code

1. Identify the owning component: Android, Windows, iOS, Manager, Theme, Gateway or release tooling.
2. Keep secrets and production credentials out of commits, logs and test fixtures.
3. Prefer small, testable changes over cross-platform rewrites.
4. Preserve the centralized versioning contract in `version.json`.

## Validation

A change is not release-ready until the relevant static checks and regression tests pass. The authoritative full gate is `.github/workflows/project-health.yml`.

When adding a Python regression test under `tests/test_*.py`, update `tests/release_test_manifest.json` in the same change. The release validator intentionally requires the manifest to match the shipped test suite exactly.

## Android

BlueVPN Android is an overlay on a pinned upstream v2rayNG source. Do not assume a file under `upstream/` is permanently tracked. Canonical BlueVPN Android source belongs under `android-source/`, and preparation/build helpers belong under `scripts/`.

Changes that affect runtime generation, R8, native libraries, signing, update delivery or VPN state should be verified by the real Android release build in addition to Python regression tests.

## Windows

Windows changes should preserve x64 and ARM64 publishing, installer generation and runtime validation. WebView/Tapsell changes must remain fail-open so advertisement failures cannot block VPN functionality.

## Documentation

Canonical project documentation belongs under `docs/`. Avoid adding version-specific one-off Markdown files to the repository root. If a release needs a temporary investigation note, keep it in the issue/run context or consolidate the lasting information into the relevant `docs/` page.

## Commit scope

Use descriptive commits such as:

```text
fix(android): harden location snapshot decoding
feat(windows): improve update fallback
chore(docs): organize repository documentation
```
