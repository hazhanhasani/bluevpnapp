# Changed files — BlueVPN 4.12.2

- `.github/workflows/build-apk.yml` — extends `android-build.log` across repository cleanup, Android overlay, Aether cache, Rust target setup, Aether build/verification and auth overlay; makes cache restore non-fatal; replaces the external Rust setup action with runner `rustup` plus retry.
- `branding/app.json` / `release.json` — release metadata bumped to 4.12.2.
- `bluevpn-manager/bluevpn-manager.php` / `readme.txt` — synchronized Manager version 4.12.2.
- `tests/*` — current-release assertions updated and pre-Gradle stage hardening regression coverage added.
