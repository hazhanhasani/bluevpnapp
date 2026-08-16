# BlueVPN 4.12.0 — Pre-Gradle Xray artifact hardening

## Trigger
The supplied `android-build (5).log` stops immediately after resolving `AndroidLibXrayLite v26.7.5`, before Gradle starts. The log itself does not contain the GitHub Actions error emitted by the following action step, so it cannot prove which external action failed.

## Changes
- Added explicit `resolve-libv2ray-artifact` and `verify-libv2ray-artifact` build stages to the persistent failure log.
- Made the AAR cache restore non-fatal; a cache backend problem now falls through to a clean download.
- Replaced the opaque third-party release-downloader action with a logged `curl` download from the exact upstream release asset.
- Added bounded retry/backoff and a `gh release download` fallback.
- Added AAR integrity validation as a ZIP, minimum-size guard, and SHA-256 logging before Android source preparation.
- Preserved the official v2rayNG 2.2.6 / AndroidLibXrayLite v26.7.5 pairing.

## Version
Per BlueVPN versioning policy, `4.11.10` rolls to `4.12.0` rather than `4.11.11`.
