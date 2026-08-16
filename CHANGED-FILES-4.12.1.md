# Changed files — BlueVPN 4.12.1

- `.github/workflows/build-apk.yml` — removes the redundant post-AAR `actions/setup-python` dependency, records a dedicated `prepare-python-environment` stage, logs Python/pip/Pillow preparation into `android-build.log`, retries Pillow installation, and preserves pre-Gradle diagnostics when Gradle starts.
- `branding/app.json` / `release.json` — release metadata bumped to 4.12.1.
- `bluevpn-manager/bluevpn-manager.php` / `readme.txt` — synchronized Manager version 4.12.1.
- `tests/test_pre_gradle_python_4121.py` — regression coverage for the pre-Gradle cutoff fix.
- Existing current-release assertions updated to 4.12.1.
