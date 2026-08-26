# Troubleshooting

## Project Health fails before publication

Start with the first failing step in `full-static-regression-gate`. Publication is intentionally blocked until this job is green.

Common cases:

### Release test manifest mismatch

If a new `tests/test_*.py` file is added, register it in `tests/release_test_manifest.json`. The validator requires an exact match.

### Python helper import failure

Build helpers under `scripts/` can be imported both as scripts and by isolated regression tests. Do not rely on the runner's current working directory being present in `sys.path`; resolve sibling helpers relative to `__file__` or explicitly establish the script directory.

### Version drift

Run the same synchronization/validation helpers used by CI and repair the canonical version source rather than patching individual component versions independently.

## Android build fails after Project Health

Inspect the `Build Signed BlueVPN APK` run at the exact release SHA. Static regression success does not prove Gradle, R8, signing or signed-runtime validation succeeded.

Important checkpoints are:

1. source preparation;
2. Gradle release compile;
3. APK signing;
4. signed APK runtime validation;
5. GitHub Release publication;
6. control-plane metadata sync.

## Android Locations crashes or empties

Treat local server/profile snapshots as untrusted persisted data. Blank/null identifiers and corrupt MMKV rows should be skipped individually. A malformed entry must not terminate iteration of the whole location pool.

## Android updater stays on preparation

Separate connection preparation from actual byte download. Network routes must have bounded connect/read timeouts and a fallback route. UI state should indicate whether it is connecting, falling back or downloading instead of staying indefinitely on a generic preparation message.

## WordPress version drift

Distinguish three states: GitHub release version, Manager/Theme version available for update, and the version actually active on WordPress. A release can be healthy while the installation has not yet converged.
