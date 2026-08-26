# Gateway

`bluevpn-gateway/` contains the managed gateway agent, systemd unit, example configuration and installation scripts.

The canonical operational overview remains `bluevpn-gateway/README.md`. This page exists as a repository-level entry point and documents how the gateway fits into the wider system.

## Relationship to Manager

The WordPress Manager can carry distributable gateway assets used by the deployment flow. Changes to canonical gateway files and mirrored Manager assets must stay synchronized when the release validator requires equality.

## Security

Enrollment tokens, node credentials and production endpoint secrets must never be committed. Example configurations must use non-sensitive placeholders.

## Release discipline

Gateway agent version strings participate in synchronized project release metadata. Avoid independent version bumps outside the canonical release process.
