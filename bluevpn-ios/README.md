# BlueVPN iOS

Native SwiftUI client mirroring the Android BlueVPN information architecture and visual system.

- App target: account, plans, locations, support, campaigns, stable/beta policy, RTL and light/dark UI.
- Packet Tunnel target: `NetworkExtension`, IPv4-first tunnel settings, fail-closed real-egress contract.
- Control plane: `https://bot.blluepanel.ir`, shared plan/location/free-WARP policy.

Generate the Xcode project with `xcodegen generate --spec bluevpn-ios/project.yml`.
Production signing requires Apple Network Extensions entitlement and signed Xray/Aether XCFrameworks.

