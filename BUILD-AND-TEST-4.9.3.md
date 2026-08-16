# BlueVPN 4.11.6 — Single Notification + Public Branding

- Removed duplicate user-visible VPN notification by sharing the upstream CoreVpnService notification ID.
- BlueVpnWarpKeepAliveService no longer runs its own 3-second notification refresh loop; v2rayNG/Xray Core notification is the single system UI owner.
- Free bridge remark is now `BlueVPN Free`, so the Core notification no longer exposes the Cloudflare/WARP provider name.
- Public Android UI uses `BlueVPN Free` / `اتصال رایگان BlueVPN`; transport/provider names remain limited to diagnostics/admin/internal implementation.
- The project does not falsely claim third-party infrastructure ownership; public branding is product-level rather than provider-level.

Validation:
- Python regression suite: 246/246 PASS
- Release validator: PASS
- PHP syntax lint: 37/37 PASS
- GitHub Actions YAML parse: 3/3 PASS
