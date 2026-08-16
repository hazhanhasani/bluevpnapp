# BlueVPN 4.11.0 — APK Runtime Gate Hotfix

The Android Gradle build for 4.9.2 completed successfully, but the post-signing
apk-runtime-validation gate produced a false failure.

4.11.0 fixes the release gate:
- Uses apkanalyzer manifest print to decode the binary APK manifest.
- Manifest policy checks are performed by a deterministic Python XML parser.
- The parser is unit-tested with valid and invalid BlueVPN manifest contracts.
- Final APK signature is still re-verified with apksigner.
- Final APK ZIP/native Aether contract checks remain mandatory.
- Compiler warnings from build #273 remain warnings and do not fail the build.

The supplied build #273 log shows BUILD SUCCESSFUL; this hotfix targets only the
post-build runtime-validation stage.
