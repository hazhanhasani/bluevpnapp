# BlueVPN 4.11.2 — Deploy Bot Commit/Version Gate

Fixes the Telegram Deploy Bot bug where the bot could report hundreds of files
as applied even when the resulting GitHub commit had no real diff, allowing
GitHub Actions to build the previous repository version.

Changes:
- read expected version/version_code from the uploaded ZIP before deployment;
- require branding/app.json and release.json to agree;
- require BlueVPN Manager version to agree when included in the ZIP;
- Git HTTPS no-change results are rejected before repository_dispatch;
- GitHub REST transport refuses to create an empty commit when the new tree is identical;
- after push, fetch the exact commit from GitHub and require a non-empty `files` diff;
- fetch branding/app.json, release.json and BlueVPN Manager from the exact commit SHA;
- require their version metadata to match the uploaded ZIP;
- re-check the exact commit has a diff immediately before starting Android build;
- success Telegram message now says the SHA and version were verified and reports changed files.

Failure codes include:
- DEPLOY_COMMIT_NOT_APPLIED
- DEPLOY_VERSION_NOT_APPLIED
- DEPLOY_RELEASE_METADATA_NOT_APPLIED
- DEPLOY_MANAGER_VERSION_NOT_APPLIED

Validation executed:
- Release validator: PASS
- Python regression suite: 329/329 PASS
- PHP release lint: 24/24 PASS
- GitHub Actions YAML parse: PASS
- Test manifest: exact
- PHP release manifest: exact

Android Gradle compile was not re-run locally because this release changes only
the WordPress Telegram Deploy Bot and test/release metadata. GitHub remains the
authoritative Android compile/assemble gate after a verified deployment.
