# BlueVPN 4.7.0 changed files

- `scripts/cleanup_repository.py` — explicitly removes the 28 retired Railway/PostgreSQL regression modules that can remain in overlay-updated GitHub repositories.
- `tests/test_repository_cleanup_470.py` — behavioral regression test for stale repository cleanup and protection against blanket test deletion.
- `scripts/validate_release.py` — validates that representative stale CI modules remain in the retirement contract.
- `.github/workflows/build-apk.yml` — Aether provenance filename synchronized to 4.7.0; existing cleanup-before-regression ordering retained.
- `scripts/build_aether_android.py` — Aether provenance filename synchronized to 4.7.0.
- `branding/app.json`, `release.json`, `bluevpn-manager/bluevpn-manager.php`, `bluevpn-manager/readme.txt`, `README.md` — synchronized to 4.7.0 / 40700.
- `tests/test_warp_adaptive_469.py` — current release assertion synchronized to 4.7.0.
