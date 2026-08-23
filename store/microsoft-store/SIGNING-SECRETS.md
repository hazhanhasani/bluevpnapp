# Windows Store Authenticode signing setup

The Microsoft Store EXE/MSI path requires a trusted signature on the installer and its PE payload. Self-signed development certificates are not accepted for the public Store EXE path.

## GitHub secrets

Convert your CA-issued PFX to Base64 locally and store only the Base64 in the GitHub secret `WINDOWS_SIGN_PFX_BASE64`. Store the PFX password as `WINDOWS_SIGN_PFX_PASSWORD`.

PowerShell example for Base64 generation on your own trusted machine:

```powershell
[Convert]::ToBase64String([IO.File]::ReadAllBytes('BlueVPN-CodeSigning.pfx')) | Set-Content -NoNewline BlueVPN-CodeSigning.pfx.base64.txt
```

Never commit the PFX, the Base64 text, or its password to Git/GitHub.

Run the `Build BlueVPN Windows` workflow with `store_release=true`. The workflow uses `signtool`, SHA-256, and an RFC3161 timestamp and verifies signatures before publishing Store-named artifacts.
