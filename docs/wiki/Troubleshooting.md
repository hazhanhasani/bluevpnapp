# Troubleshooting

## CI failure

Open the exact failing workflow run and inspect the first failed job/step. Do not diagnose from the final Sentinel excerpt alone when the full job log is available.

## Android

If Project Health passes but Android fails, inspect the release-SHA `Build Signed BlueVPN APK` run. Gradle, R8, signing and signed-runtime validation are separate checkpoints.

## Version mismatch

Use `version.json` as the source of truth and repair synchronization rather than manually changing one platform's version.

## WordPress

A GitHub release can be newer than the plugin/theme currently active on the server. Treat publication and installation convergence as separate states.

More cases are documented in `docs/troubleshooting.md`.
