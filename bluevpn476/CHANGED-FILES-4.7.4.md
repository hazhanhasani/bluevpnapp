# BlueVPN 4.7.4 changed files

- `bluevpn-manager/includes/class-bluevpn-control-center.php` — safe provider deletion action/UI with transactional reference cleanup.
- `bluevpn-manager/includes/class-bluevpn-providers.php` — automatic active-provider resolution for legacy plans, shared Global Subscription fallback, and legacy repair coverage.
- `bluevpn-manager/bluevpn-manager.php`, `bluevpn-manager/readme.txt` — 4.7.4 metadata/changelog.
- `branding/app.json`, `release.json`, `README.md` — release metadata.
- `.github/workflows/build-apk.yml`, `scripts/build_aether_android.py` — current Aether provenance filename.
- `tests/test_provider_entitlement_474.py`, `tests/release_test_manifest.json` — regression coverage.
