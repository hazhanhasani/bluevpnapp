# BlueVPN 4.8.3 build/test summary

## Exit-country policy fix
- Empty blocked-exit-country list is now authoritative in WordPress.
- WordPress no longer silently restores IR after an admin clears the field.
- Android no longer inserts IR when the API/storage list is empty.
- IR is rejected only when IR is explicitly present in blocked_exit_countries.
- New installations default to an empty blocked-exit-country list.
- require_exit_trace can remain enabled: country is still detected, but IR is allowed when it is not blocked.

## Validation
- Python regression suite: 216/216 PASS
- Release validator: PASS
- PHP syntax lint: 36/36 PASS
- GitHub Actions YAML parse: 3/3 PASS

Full Android Gradle compilation remains a GitHub Actions step because the package bootstraps the pinned v2rayNG upstream in CI.
