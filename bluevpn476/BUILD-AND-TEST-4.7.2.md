# Build and Test Summary — 4.7.2

## Root cause fixed
Build #262 reached `wait-wordpress-auto-update`. The public `/health` endpoint was intentionally hardened to expose only minimal status and version data, while the workflow still required admin-only `database.schema_version`, `database.ready`, and `github_updater` fields. Therefore an already-installed matching Manager (`4.7.1`) was incorrectly treated as `ready=false`.

## New convergence contract
- Public CI barrier requires installed Manager version >= release target.
- Public health must report `status=ok`.
- If detailed schema/readiness fields are present, they are additionally validated.
- Missing admin-only schema/updater diagnostics no longer fail a healthy compatible Manager.
- Older Manager versions still wait/fail.
- Compatible Manager with degraded public health still fails.

## Executed locally
- Python regression suite: 173/173 PASS.
- `scripts/validate_release.py`: PASS.
- PHP syntax lint for all BlueVPN Manager PHP files: PASS.
- All GitHub Actions YAML files parsed successfully.
- Full Android Gradle build is still delegated to GitHub Actions because the package does not vendor the complete upstream checkout.
