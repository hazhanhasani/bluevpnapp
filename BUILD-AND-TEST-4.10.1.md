# BlueVPN 4.10.5 — Overlay Alternate-Core Cleanup

GitHub Run #279 failed because `android-source/BlueVpnCoreFlavor.kt`, removed from
the release, survived an overlay-style repository update.

Fix:
- explicitly retire `android-source/BlueVpnCoreFlavor.kt`;
- explicitly retire `third_party/MAHSA_CORE_CANARY.md`;
- extend the real overlay-cleanup behavioral test to inject both stale files and
  verify cleanup deletes them before regression discovery;
- add a dedicated regression for the exact #279 failure.
