# BlueVPN 4.7.1 Build/Test Summary

GitHub Actions build #261 reached `release-regression-gate`, proving the previous cleanup removed the original 28 stale test modules. Two additional repository-residue modules (`test_admin_security.py` and `test_admin_sms_catalog_render_v359.py`) remained in the long-lived checkout and were rediscovered by `unittest discover`.

4.7.1 removes the fragile fixed legacy-test list. `tests/release_test_manifest.json` is now authoritative for Python regression modules shipped by the release. Before Android overlay/build, `scripts/cleanup_repository.py` fails closed if the manifest is invalid or references missing release tests, then removes any `tests/test_*.py` module that is not present in that manifest. Current release tests are never suppressed after they start: failures in approved tests still fail the regression gate.

Local verification recorded for this release:
- authoritative manifest exactly matches all shipped `tests/test_*.py` modules;
- cleanup behavior test covers the two build #261 leftovers plus an unknown future stale module;
- cleanup fails closed when the manifest is missing;
- full Python discovery, release validator, PHP lint and workflow syntax checks executed successfully in the packaging environment.

Full Android Gradle assembly remains a GitHub Actions responsibility when the pinned upstream v2rayNG/Aether sources are available; the regression gate still precedes and cannot replace Gradle compilation/assembly.
