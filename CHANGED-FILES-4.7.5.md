# BlueVPN 4.7.5 changed files

- `bluevpn-manager/includes/class-bluevpn-providers.php` — live PasarGuard group/Marzban inbound catalog and selected-access provisioning.
- `bluevpn-manager/includes/class-bluevpn-control-center.php` — admin-only AJAX catalog plus per-plan live selectors and persistence.
- `bluevpn-manager/includes/class-bluevpn-db.php` — `plans.marzban_inbounds_json` migration.
- `bluevpn-manager/includes/class-bluevpn-admin.php` — add-plan persistence for selected access.
- `bluevpn-manager/includes/class-bluevpn-unified-ui.php` — nonce/AJAX configuration for admin picker.
- `bluevpn-manager/assets/admin-unified.js` / `.css` — live catalog picker UI.
- version/release metadata and regression tests.

- `bluevpn-manager/includes/class-bluevpn-providers.php` — live PasarGuard group/Marzban inbound selection and resolved `$pgId` fallback provisioning fix.
- `tests/test_provider_access_picker_475.py` — regression coverage for catalog picker persistence and resolved PasarGuard fallback panel selection.
