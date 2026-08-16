# BlueVPN 4.11.4 — Deploy ZIP Project Root Detection

Fixes DEPLOY_VERSION_METADATA_MISSING for valid full-project ZIPs that contain
the BlueVPN project inside a top-level directory such as `bluevpn476/`.

Deploy Bot now:
- accepts a flat ZIP where branding/app.json is at archive root;
- detects a nested BlueVPN project root up to a bounded depth;
- requires branding/app.json, release.json and bluevpn-manager/bluevpn-manager.php
  to exist under the same resolved project root;
- fails closed if no valid project root exists;
- fails closed if multiple equally valid roots are present;
- deploys only from the resolved project root;
- cleans up the original extraction directory safely.

Validation executed:
- Release validator: PASS
- Python regression suite: 334/334 PASS
- PHP release lint: 24/24 PASS
- GitHub Actions YAML parse: PASS
- Test manifest: exact
- PHP release manifest: exact
- Android Gradle build: not re-run locally because this release changes only the WordPress Deploy Bot and release metadata.
