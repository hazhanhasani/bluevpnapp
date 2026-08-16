# BlueVPN 4.11.3 — Split APK ABI Gate Fix

Build #275 again completed Gradle successfully and failed only in apk-runtime-validation.

Root cause fixed:
- The release build may emit ABI-split APKs for arm64-v8a and armeabi-v7a.
- Previous validation incorrectly required every individual APK to contain both Aether ABIs.
- 4.11.3 validates each APK independently for its own supported Aether runtime.
- The complete signed APK set must still cover both required ABIs in aggregate.
- Signature, ZIP integrity, DEX and native Aether size validation remain mandatory.

This is a behavioral fix to the release gate, not a relaxation of the runtime contract.
