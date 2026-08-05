BlueVPN 2.2.3 Premium Smart Connect
Build ID: 20260804171929

BlueVPN v0.4.0 Update

Replace these files in the GitHub repository:
- scripts/prepare_android.py
- branding/app.json
- .github/workflows/build-apk.yml

Before the build, configure the four permanent signing secrets from
bluevpn-signing-kit-v1.zip in GitHub Actions Secrets.

Features:
- Permanent APK signing for future in-place updates
- Connected server ping
- Connection duration
- Live download/upload speed
- Remaining subscription volume and time from subscription-userinfo
- Smart client-side load balancing by lowest ping
- Configs hidden and grouped by location
- Loopback/metadata configs filtered out
