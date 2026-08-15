# BlueVPN 4.7.6 build/test summary

This release changes the WARP orchestration path only; it keeps Aether pinned to v1.6.0 commit `a26159b82a70048b459e0128213c71767abecb8a`.

Local validation executed in the packaging environment:
- Python regression suite
- release validator
- PHP syntax lint
- workflow YAML parsing
- Kotlin source/stub compilation where available
- ZIP integrity

A full Android Gradle build still requires the pinned upstream v2rayNG checkout performed by GitHub Actions.

Results:
- 188/188 Python regression tests PASS
- release validator PASS
- 22 PHP files syntax PASS
- 3 GitHub Actions YAML files parsed successfully
- Python scripts/tests byte-compile PASS

Full Gradle APK build was not executed locally because the ZIP does not vendor the pinned upstream v2rayNG checkout; GitHub Actions performs that reproducible checkout/build.
