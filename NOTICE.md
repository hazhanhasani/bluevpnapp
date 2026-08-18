BlueVPN v4.5.9: decouples authentication success from expensive entitlement/subscription/AI bootstrap so email and OTP login return immediately after the server issues a valid session; post-auth initialization continues in the background.

## BlueVPN 4.6.4 Free WARP engine
The Free tier can use the separately built Aether core (AGPL-3.0), pinned at `a26159b82a70048b459e0128213c71767abecb8a`. See `third_party/AETHER.md`. No Oblivion application code is copied into this project. Premium remains on the stock v2rayNG/Xray runtime.


## BlueVPN 4.16.8 Windows runtime
The Windows client packages runtime components from the official v2rayN 7.24.4 Windows distribution (GPL-3.0) and uses Aether v1.1.1 (AGPL-3.0) as the Free WARP transport on Windows x64. See `third_party/V2RAYN.md` and `third_party/AETHER_WINDOWS.md`. Windows ARM64 uses the curated Xray free-pool fallback because Aether v1.1.1 does not publish a Windows ARM64 binary.
