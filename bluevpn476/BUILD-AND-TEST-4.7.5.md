# BlueVPN 4.7.5 build/test summary

Implemented live per-plan provider access selection:

- PasarGuard: admin-only live `/api/groups` / `/api/groups/simple` catalog; selected group IDs are persisted in `group_ids_json` and enforced during provision/repair.
- Marzban: admin-only live `/api/inbounds` catalog; selected protocol/tag pairs are persisted in `plans.marzban_inbounds_json` and enforced during provision/repair.
- Empty selection remains backward compatible: all active groups/inbounds are used.
- Catalog endpoint is protected by `manage_options` and WordPress nonce; credentials/raw provider payloads are not returned to the browser.
- Schema upgraded to 1.13.0 using the existing dbDelta upgrade path.

Validation executed locally:

- Python regression suite: 186/186 PASS.
- Release validator: PASS (`BlueVPN 4.7.5 validation: PASS`).
- PHP syntax lint: 36/36 files PASS.
- Admin JavaScript syntax (`node --check`): PASS.
- JSON metadata parse: PASS.

A full Android Gradle build is not required by this WordPress-only feature change in the local sandbox; the repository GitHub workflow remains responsible for the pinned upstream Android build.
