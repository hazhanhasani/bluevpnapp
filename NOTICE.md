BlueVPN v4.5.9: decouples authentication success from expensive entitlement/subscription/AI bootstrap so email and OTP login return immediately after the server issues a valid session; post-auth initialization continues in the background.

## BlueVPN 4.6.3 Free WARP engine
The Free tier can use the separately built Aether core (AGPL-3.0), pinned at `a26159b82a70048b459e0128213c71767abecb8a`. See `third_party/AETHER.md`. No Oblivion application code is copied into this project. Premium remains on the stock v2rayNG/Xray runtime.

