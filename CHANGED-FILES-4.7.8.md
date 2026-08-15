# BlueVPN 4.7.8 changed files

- `android-source/BlueVpnSystemController.kt` — system-level start/stop/restart controller covering Premium/Xray and Free/WARP/Aether.
- `android-source/BlueVpnQuickTileService.kt` — BlueVPN-aware Android Quick Settings tile using the real daemon CoreServiceManager state and explicit app-process actions.
- `android-source/BlueVpnSystemActionReceiver.kt` — private receiver for notification/tile actions.
- `scripts/prepare_android.py` — replaces upstream tile with BlueVPN tile, wires notification Stop/Restart into BlueVPN lifecycle, opens BlueVPN Home from the notification, and keeps traffic stats visible.
- `tests/test_android_system_integration_478.py` — regression coverage for tile/notification/system lifecycle integration.
- Version metadata bumped to 4.7.8 / 40708.
