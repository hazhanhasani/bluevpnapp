# Changed files — BlueVPN 4.12.3

- `.github/workflows/build-apk.yml` — keep app/release `version_source` synchronized during CI.
- `branding/app.json` / `release.json` — 4.12.3 metadata and stable source provenance.
- `tests/test_support_tehran_time_41110.py` — remove stale release-specific provenance expectation.
- `tests/test_release_provenance_4123.py` — regression coverage for the CI mutation that caused build (8) to fail.
- current-release version assertions and BlueVPN Manager metadata synchronized to 4.12.3.
