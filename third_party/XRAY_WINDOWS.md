# Xray-core / Wintun — BlueVPN Windows

BlueVPN Windows uses the official Xray-core Windows release package at build time.

- Project: XTLS/Xray-core
- Pinned Windows runtime: `v26.7.28`
- Release assets: `Xray-windows-64.zip`, `Xray-windows-arm64-v8a.zip`
- TUN on Windows: Xray's official TUN inbound via `wintun.dll`
- Runtime binaries are downloaded by GitHub Actions and are not committed to this repository.

The upstream Xray Windows release package includes its Wintun redistribution notices. BlueVPN preserves the runtime files unmodified in the Windows artifact.
