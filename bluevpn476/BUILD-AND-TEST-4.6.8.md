# BlueVPN 4.6.8 Build and Test

## Result
- Python/unit regression suite: **173/173 PASS**
- `scripts/validate_release.py`: **PASS**
- PHP syntax: `class-bluevpn-ads.php`, `class-bluevpn-db.php`, `bluevpn-manager.php`: **PASS**

## Runtime behavior
1. Aether starts a candidate WARP strategy and exposes dynamic loopback SOCKS.
2. BlueVPN fetches Cloudflare trace through that SOCKS path.
3. If trace is unavailable while strict mode is enabled, the route is rejected.
4. If `loc` is in the blocked country set (default: `IR`), the route is rejected.
5. After v2rayNG/Xray/TUN starts, BlueVPN repeats the exit trace through the final local Xray proxy.
6. A blocked/unverifiable WARP route never becomes `CONNECTED`; existing failover transfers to the configured Free pool when enabled.
7. Route Intelligence/AI records the failed route so it is penalized/quarantined for subsequent selection.

## WordPress controls
Free connection settings now expose:
- `تأیید اجباری کشور خروجی WARP`
- `کشورهای خروجی مسدود` (ISO-2, comma separated; default `IR`)

## Limitation
A true `WARP -> dedicated foreign VPS exit` chain requires real foreign exit credentials/configuration. No fake endpoint is embedded. Version 4.6.8 instead guarantees that an Iranian WARP egress is rejected and uses the already-configured foreign Free Pool as failover.
