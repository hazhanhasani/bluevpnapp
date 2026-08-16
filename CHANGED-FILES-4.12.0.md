# Changed files — BlueVPN 4.12.0

- `.github/workflows/build-apk.yml` — resilient/logged libv2ray AAR retrieval and verification before Gradle.
- `branding/app.json` — release version 4.12.0.
- `release.json` — Android release metadata 4.12.0.
- `bluevpn-manager/bluevpn-manager.php` — Manager version sync.
- `bluevpn-manager/readme.txt` — Manager stable tag/version sync.
- `tests/release_test_manifest.json` — includes the new pre-Gradle regression test.
- `tests/test_pre_gradle_xray_download_4120.py` — verifies the hardened AAR acquisition path.
- Version assertions in existing regression tests were advanced to 4.12.0 without changing their behavioral checks.
- `BUILD-AND-TEST-4.12.0.md` — release/build notes.
