This directory is populated by .github/workflows/build-windows.yml.
The Windows build downloads the pinned official v2rayN runtime bundle and, on x64, the pinned official Aether WARP runtime, verifies GitHub SHA-256 metadata, then packages them into the installer.
Do not commit downloaded third-party runtime binaries to the BlueVPN source repository.
