# Windows

`bluevpn-windows/` contains the Windows client, runtime integration and installer-related source.

## Runtime

The Windows application targets the supported .NET/WPF stack and integrates the packaged VPN runtime used by BlueVPN. Release automation builds architecture-specific outputs and validates packaging/installer metadata.

## UI and WebView2

WebView2 surfaces are themed globally so dark mode does not flash or retain white native/web backgrounds. Native title bars and web content should follow the active theme where supported.

## Tapsell Web placement

The Windows/Web publisher code can load through the provider's MediaAd host while still being a Tapsell Windows/Web placement. Publisher-origin-sensitive code must prefer the registered publisher host encoded by the loader URL rather than an unrelated control-plane origin.

The complete publisher snippet includes both the loader script and the placement container. Advertisement rendering is fail-open: an ad timeout or empty placement must not block VPN usage.

## Release

Windows releases are dispatched from Project Health at the synchronized release SHA. Verify x64/ARM64 build, packaging, installer publication and release metadata before considering a Windows release complete.
