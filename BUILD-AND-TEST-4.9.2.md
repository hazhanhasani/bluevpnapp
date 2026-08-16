# BlueVPN 4.9.4 — Production Runtime Validation

Implemented:
- Privacy-safe bounded runtime lifecycle audit.
- Runtime audit hooks for start/stop/restart, WARP foreground service, task removal, predictive failover and verified Free/WARP connect.
- BlueAI diagnostics include the bounded runtime audit.
- Signed APK post-build contract validator.
- APK ZIP integrity + SHA-256 report.
- Required Aether native binaries for arm64-v8a and armeabi-v7a are enforced.
- GitHub post-signing manifest checks enforce BlueVpnWarpKeepAliveService, BlueVpnQuickTileService, BlueVpnSystemActionReceiver, POST_NOTIFICATIONS and FOREGROUND_SERVICE.
- apksigner verification is repeated on the final dist APK.
- Runtime-validation reports are uploaded as an independent GitHub artifact.

Device-dependent tunnel quality still requires a real Android device/network; CI validates the packaged runtime contract and build artifact, not radio/ISP behavior.
