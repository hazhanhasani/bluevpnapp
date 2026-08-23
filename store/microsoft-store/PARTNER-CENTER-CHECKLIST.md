# BlueVPN 5.2.3 — Microsoft Store (EXE/MSI path) checklist

Checked against Microsoft Learn on 2026-08-23.

BlueVPN Windows remains a WPF/self-contained Win32 application with an elevated offline Inno Setup installer. For this architecture the prepared Store path is the Microsoft Store **EXE/MSI listing** rather than converting the current privileged/runtime layout to MSIX in this release.

Official references:
- https://learn.microsoft.com/windows/apps/package-and-deploy/publish-first-app
- https://learn.microsoft.com/windows/apps/publish/publish-your-app/msi/app-package-requirements
- https://learn.microsoft.com/windows/apps/distribute-through-store/how-to-distribute-your-win32-app-through-microsoft-store

## Required signing secrets

A public Microsoft Store EXE build cannot be produced with a self-signed certificate. Configure a CA-trusted Authenticode code-signing certificate in GitHub Actions:

- `WINDOWS_SIGN_PFX_BASE64` — Base64 of the PFX file.
- `WINDOWS_SIGN_PFX_PASSWORD` — PFX password.

The certificate must chain to a CA in the Microsoft Trusted Root Program.

Then run `Build BlueVPN Windows` manually with:
- `store_release = true`

The workflow will fail closed if the PFX/password are missing, signs unsigned PE files in the self-contained payload, verifies every EXE/DLL has a Valid Authenticode signature, builds the offline installer, signs/verifies the installer, and emits:

- `BlueVPN-MicrosoftStore-Setup-5.2.3-win-x64.exe`
- `BlueVPN-MicrosoftStore-Setup-5.2.3-win-arm64.exe`

Do not submit an installer generated without the `store_release=true` gate.

## Partner Center

1. Create/reserve **BlueVPN** as an EXE/MSI product.
2. Upload/provide the versioned HTTPS URL for each supported architecture according to Partner Center's current UI.
3. The binary at a submitted URL must never be replaced in place. Use a new versioned URL for each release.
4. Privacy policy: `https://bot.blluepanel.ir/privacy/`.
5. Provide the reviewer account/instructions in certification notes.
6. Explain that the installer is offline/self-contained and installs only BlueVPN and its bundled networking runtimes.
7. Disclose the network/TUN dependency and elevation requirement in certification notes; see `CERTIFICATION-NOTES.md`.
8. Complete age rating, category, markets, screenshots, support contact, and privacy fields.

## Suggested immutable GitHub Release URLs

After the Store workflow publishes version 5.2.3:

`https://github.com/hazhanhasani/bluevpnapp/releases/download/bluevpn-windows-v5.2.3/BlueVPN-MicrosoftStore-Setup-5.2.3-win-x64.exe`

`https://github.com/hazhanhasani/bluevpnapp/releases/download/bluevpn-windows-v5.2.3/BlueVPN-MicrosoftStore-Setup-5.2.3-win-arm64.exe`

Verify the URLs return the installer directly over HTTPS before submitting them to Partner Center.

## Certification smoke test

Run on clean Windows 11 x64 and ARM64 (physical/VM as applicable):
- installer signature reports Valid;
- installer launches and UAC publisher name matches your code-signing certificate;
- clean install and uninstall;
- app starts without a separate .NET download;
- login and existing subscription sync;
- VPN connect/disconnect;
- TUN/system proxy cleanup after disconnect and forced app exit;
- sleep/wake and network switch recovery;
- app/update flow does not leave orphan core processes;
- privacy/support links open successfully.
