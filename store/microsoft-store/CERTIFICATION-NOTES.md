# Microsoft Store certification notes — draft

BlueVPN is a WPF Win32 VPN client distributed through an offline, self-contained Inno Setup EXE. The application requires elevation for networking/TUN setup and cleanup. It bundles the networking runtime required by the application instead of downloading an executable payload during installation.

BlueVPN uses Xray/sing-box based networking and a Wintun-based TUN component where required by the selected connection mode. The installer only installs BlueVPN and the runtime files needed by BlueVPN. Network components are removed/cleaned up through the application's disconnect/uninstall path.

Reviewer flow:
1. Install the architecture-appropriate signed BlueVPN installer.
2. Launch BlueVPN.
3. Sign in with the reviewer account supplied in Partner Center.
4. Synchronize the active subscription.
5. Press Connect and allow the requested Windows elevation/networking actions.
6. Verify the connected status and normal web connectivity.
7. Press Disconnect and verify system proxy/TUN state is restored.

Reviewer account:
- Username/email/phone: `________________`
- Password/OTP instructions: `________________`
- Any required entitlement note: `________________`

Privacy policy:
`https://bot.blluepanel.ir/privacy/`

Support:
`https://bot.blluepanel.ir/support/`
