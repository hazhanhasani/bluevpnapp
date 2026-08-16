# BlueVPN 4.10.10 — Staged Regression Diagnostics

Build #278 stopped in release-regression-gate while the compact Telegram excerpt
contained only passing test names. The single shell block did not identify which
command returned exit code 1.

4.10.10 converts the gate into explicit named substages:
- repository-cleanup
- pycompile
- release-validator
- python-regression
- php-release

Each substage records its command, timestamp, exit code, PASS/FAIL marker and the
active substage file. Python tests are fail-fast so the first real failing test is
visible near the end of stability-gate.log. Telegram extraction now recognizes
unittest FAIL/ERROR, AssertionError, Traceback, PHP lint failures and substage
markers.

Authoritative repository cleanup is run again immediately before regression tests
to make overlay-style GitHub updates deterministic.

Validation executed:
- staged release regression chain: PASS
- Python tests: PASS (28 modules)
- PHP release files: PASS (23/23)
- workflow YAML: PASS
- test manifest: exact
- PHP manifest: exact
