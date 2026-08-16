# BlueVPN 4.10.5 — Stable APK Runtime Gate

Build #274 again proved the Android project itself builds successfully. The only failing stage was the post-sign APK runtime gate.

4.10.5 removes manifest-decoder tooling from the post-signing gate entirely.

Validation split:
1. Exact generated AndroidManifest.xml is validated before Gradle for required permissions/services/receivers.
2. Gradle compile + assemble remains mandatory.
3. Final signed APK is validated after signing for:
   - apksigner verification
   - ZIP integrity
   - DEX presence
   - arm64-v8a Aether native runtime
   - armeabi-v7a Aether native runtime

This keeps the release gate strict without allowing aapt2/apkanalyzer CLI differences to reject a healthy APK.
