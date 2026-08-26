# BlueVPN Documentation

This directory is the canonical documentation set for the BlueVPN repository.

## System

- [Architecture](architecture.md) — component boundaries and data flow
- [Release process](release-process.md) — versioning, validation and publication
- [Security](security.md) — repository and runtime security rules
- [Troubleshooting](troubleshooting.md) — common build and runtime failures

## Platforms

- [Android](android.md)
- [Windows](windows.md)
- [iOS](ios.md)
- [WordPress control plane](control-plane.md)
- [Gateway](gateway.md)

## Wiki

`docs/wiki/` contains source pages intended for the GitHub Wiki. Keeping the source pages in the main repository makes Wiki content reviewable and versioned even when the GitHub Wiki UI is maintained separately.

- [Wiki Home](wiki/Home.md)
- [Wiki Architecture](wiki/Architecture.md)
- [Wiki Releases](wiki/Releases.md)
- [Wiki Troubleshooting](wiki/Troubleshooting.md)
- [Wiki Sidebar](wiki/_Sidebar.md)

## Documentation policy

Long-lived information belongs here. Version-specific fix notes and temporary release investigations should not accumulate in the repository root. Git history preserves retired notes when they are removed from the current tree.
