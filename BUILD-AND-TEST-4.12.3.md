# BlueVPN 4.12.3 — CI release provenance fix

Fixes the regression gate failure where the workflow rewrote `branding/app.json.version_source` to `source_declared_release_version` while the Tehran-time regression test still expected an old release-specific label.

The release provenance is now intentionally stable in both `branding/app.json` and `release.json`, and the workflow updates both files consistently before the regression gate.
