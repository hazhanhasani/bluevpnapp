# BlueVPN 4.10.1 build/test summary

## Android system notification fix
- Declares and requests POST_NOTIFICATIONS on Android 13+.
- Keeps the existing BlueVPN Quick Settings tile.
- Replaces the minimal WARP keep-alive notification with a persistent BlueVPN VPN-status card.
- Notification shows active WARP strategy, live UID receive/send throughput, and Android chronometer.
- Notification actions: Restart and Stop, routed through BlueVpnSystemController so Xray + Aether + free-session state are managed together.
- Tapping the notification opens BlueVpnHomeActivity.
- Foreground ownership remains START_STICKY and independent from Activity/Recents lifecycle.

## Validation
- Python regression suite: 220/220 PASS
- Release validator: PASS
- PHP syntax lint: 36/36 PASS
- GitHub Actions YAML parse: 3/3 PASS

Full Android Gradle compilation is performed by GitHub Actions after bootstrapping pinned v2rayNG.
