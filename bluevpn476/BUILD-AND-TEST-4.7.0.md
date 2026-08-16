# BlueVPN 4.7.0 Build and Test

## CI failure fixed

GitHub Actions build #260 stopped in `release-regression-gate` before Gradle because 28 retired v3.x Railway/PostgreSQL test modules were still tracked in the GitHub repository. The 4.6.10 ZIP no longer contained those files, but an overlay-style repository update does not delete previously tracked paths. `python -m unittest discover -s tests -v` therefore imported obsolete modules whose application packages had already been removed.

`cleanup_repository.py` now explicitly retires those 28 known legacy test modules before the regression gate. It does not use a wildcard or blanket deletion of `tests/`.

## Executed locally

- `python -m py_compile ... tests/*.py` — PASS
- `python scripts/validate_release.py` — PASS
- `python -m unittest discover -s tests -v` — 170/170 PASS
- PHP lint for every `bluevpn-manager/**/*.php` — PASS
- behavioral stale-workspace simulation: inject the same 28 `_FailedTest` modules -> confirm failure -> run cleanup -> rerun discovery — 170/170 PASS, zero `_FailedTest`

## Android build status

A complete Gradle APK build cannot be executed from this sandbox because the release ZIP intentionally does not vendor the full `upstream/V2rayNG` checkout and the runtime environment cannot perform the required external Git checkout. The GitHub workflow still requires the pinned upstream checkout and the real Android compile/assemble stages after the regression gate. Version 4.7.0 fixes the gate that prevented build #260 from reaching those stages.
