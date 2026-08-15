# Changed Files — BlueVPN 4.7.1

- `scripts/cleanup_repository.py` — replaces incomplete fixed legacy-test deletion with fail-closed authoritative release-test reconciliation.
- `tests/release_test_manifest.json` — declares exactly which Python regression modules belong to this release.
- `tests/test_repository_cleanup_470.py` — behavioral coverage for build #261 stale modules, unknown future residue, manifest preservation and fail-closed behavior.
- `tests/test_current_release.py` — regression assertions for manifest-based cleanup.
- Version metadata synchronized to 4.7.1 / 40701 across Android branding, WordPress, release metadata and build workflow provenance.
- `BUILD-AND-TEST-4.7.1.md` — records the build #261 root cause and verification scope.
