# v2rayN runtime notice

BlueVPN Windows uses a pinned official v2rayN Windows release bundle as the source of its Xray, sing-box and Wintun runtime components while keeping the BlueVPN user interface and account/control-plane integration separate.

- Project: `2dust/v2rayN`
- Pinned baseline for BlueVPN 4.16.7: `7.24.4`
- License: GPL-3.0
- Official source/release artifacts are downloaded by GitHub Actions and SHA-256 verified from GitHub release metadata.
- BlueVPN may download a newer *stable* official v2rayN runtime after installation; it is validated before activation and never replaced while a VPN session is active.

The complete upstream package is preserved under `runtime/v2rayn/upstream/` in Windows builds for attribution/auditability; normalized core binaries used by BlueVPN are placed in `runtime/v2rayn/bluevpn-core/`.
