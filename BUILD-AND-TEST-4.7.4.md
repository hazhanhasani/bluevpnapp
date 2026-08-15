# BlueVPN 4.7.4 — Build and Test Summary

## Fixed
- Provider deletion is available for PasarGuard, Marzban and GuardCore.
- Delete is transactional and detaches plan/customer references before removing the panel row.
- Paid/manual provisioning now auto-resolves an active PasarGuard and Marzban for legacy plans whose provider ids are empty.
- A manual GuardCore `Global Subscription` is automatically attached as a paid source when no explicit GuardCore route exists.
- Provider repair now scans all active, non-expired paid entitlements including legacy plans without provider ids.

## Verification executed
- Python regression suite: 182/182 PASS.
- PHP syntax lint: 22/22 files PASS.
- `scripts/validate_release.py`: PASS.
- GitHub workflow YAML parse: 3/3 PASS.

Android Gradle compilation was not rerun locally because this update changes WordPress/provider control-plane logic and the repository package does not vendor the pinned upstream v2rayNG checkout. GitHub Actions remains the authoritative Android build environment.
