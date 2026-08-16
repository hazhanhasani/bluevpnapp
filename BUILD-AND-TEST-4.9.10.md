# BlueVPN 4.10.4 — Authoritative PHP Release Gate

Build #277 failed in `release-regression-gate` while the visible test tail only
contained passing tests. The gate previously executed `php -l` over every PHP file
left in the repository and redirected successful/failing standard output to
`/dev/null`.

Because releases are commonly applied as overlay ZIP updates, deleted PHP files
from older versions can remain in GitHub even though they are not part of the
current release.

4.10.4:
- adds an authoritative `bluevpn-manager/release_php_manifest.json`;
- repository cleanup removes stale PHP files that are not shipped by this release;
- the regression gate validates the exact PHP release file set;
- every PHP lint result is visible;
- syntax failures print the exact path and PHP error;
- silent `find ... php -l >/dev/null` validation is removed.

No current PHP file is ignored: every PHP file shipped by the release must exist,
must be listed, and must pass `php -l`.

Validation executed locally:
- Exact release-regression-gate command chain: PASS
- Python regression suite: 27 modules / PASS
- Authoritative PHP release validation: 23/23 PASS
- GitHub Actions YAML parse: PASS
- Python test manifest: exact
- PHP release manifest: exact
