# Changed Files — 4.7.2

- `.github/workflows/build-apk.yml`: align WordPress convergence with the minimal public health endpoint. A compatible/newer Manager with `status=ok` passes even when admin-only schema/updater diagnostics are absent. Older Manager versions still wait/fail; degraded public health still fails.
- `bluevpn-manager/bluevpn-manager.php`, `bluevpn-manager/readme.txt`, `branding/app.json`, `release.json`: version metadata updated to 4.7.2 / 40702.
- `scripts/build_aether_android.py`: provenance filename updated.
- `tests/test_current_release.py`: regression coverage for the minimal-health convergence contract.
