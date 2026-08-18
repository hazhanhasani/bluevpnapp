BlueVPN v4.5.9: decouples authentication success from expensive entitlement/subscription/AI bootstrap so email and OTP login return immediately after the server issues a valid session; post-auth initialization continues in the background.

## BlueVPN 4.6.4 Free WARP engine
The Free tier can use the separately built Aether core (AGPL-3.0), pinned at `a26159b82a70048b459e0128213c71767abecb8a`. See `third_party/AETHER.md`. No Oblivion application code is copied into this project. Premium remains on the stock v2rayNG/Xray runtime.


## BlueVPN 4.17.1 Windows runtime
The Windows client packages runtime components from the official v2rayN 7.24.4 Windows distribution (GPL-3.0) and uses Aether v1.1.1 (AGPL-3.0) as the Free WARP transport on Windows x64. See `third_party/V2RAYN.md` and `third_party/AETHER_WINDOWS.md`. Windows ARM64 uses the curated Xray free-pool fallback because Aether v1.1.1 does not publish a Windows ARM64 binary.


## 4.16.9
Windows compile fix for RuntimeLocator architecture shadowing, Elementor fallback classification/self-heal, GitHub cancel preflight, and Node 24-ready artifact actions.

## 4.17.1

- Deploy Bot installs Manager directly from the validated project ZIP and treats GitHub Manager Release as a publishing/fallback channel.
- Project-root detection tolerates wrapper directories and one unambiguous versioned Manager folder.
- Sentinel operational scans no longer re-count unchanged failed bot jobs every minute.

## 4.16.10
Windows release assets are flattened before artifact upload and normalized after download so Setup EXEs and SHA256 files cannot be lost under an installers/ subdirectory. GitHub cache/artifact actions use Node.js 24-generation majors (cache v5, upload-artifact v7, download-artifact v8).
