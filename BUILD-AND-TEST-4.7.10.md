# BlueVPN 4.10.5 — Fast CI Build/Test Summary

## Goal
Reduce the feedback loop for GitHub APK builds without weakening the production release path.

## Implemented
- Manual workflow dispatch defaults to `build_mode=fast`.
- Repository dispatch defaults to `build_mode=full` for production compatibility.
- Fast builds still run Android source preparation, regression gates, Kotlin compile, release assemble, alignment and permanent signing.
- Fast builds upload the signed APK immediately after signing and skip WordPress auto-update convergence, production GitHub Release publication and production metadata sync.
- Added caches for pinned Aether native binaries, libhevtun and libv2ray AAR.
- Aether cache key is based on the pinned Aether ref and build script, not BlueVPN version metadata.
- Combined `compilePlaystoreReleaseKotlin` and `assemblePlaystoreRelease` into one Gradle invocation with `--build-cache --parallel`.

## Local verification
- Python regression suite: 203/203 PASS.
- Release validator: PASS.
- BlueVPN Manager PHP syntax lint: 22 files PASS.
- GitHub Actions YAML parse: 3/3 PASS.
- Python CI scripts: py_compile PASS.

## Environment limitation
A complete local Android Gradle build was not run because this artifact environment cannot materialize the pinned v2rayNG upstream checkout from GitHub. The GitHub Actions compile/assemble tasks remain the authoritative Android build gate.
