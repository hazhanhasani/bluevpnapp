# BlueVPN Phase 3 + Free Source Resilience Patch

Target base: `hazhanhasani/bluevpnapp` main at BlueVPN 5.1.5 / schema 1.27.0.

## What this patch does

- Replaces the single 12-second Telegram preview fetch with bounded retries (6s / 10s / 15s).
- Adds a 15-minute cron cooldown after an exhausted transport failure.
- Stops duplicate Sentinel reporting for the same `t.me` outage.
- Emits one source-owned `FREE_SOURCE_TRANSPORT_FAILED_<id>` incident, throttled for 30 minutes.
- Keeps hard HTTP/content failures visible through the existing operational scanner.
- Starts Phase 3 with an observational gateway health/capacity score and persisted fleet snapshot.
- Does not change live gateway placement yet and requires no DB schema migration.

## Apply to a clean checkout

```bash
git checkout main
git pull --ff-only
git apply bluevpn-phase3-source-resilience.patch
php -l bluevpn-manager/includes/class-bluevpn-free-sources.php
php -l bluevpn-manager/includes/class-bluevpn-gateway-phase3.php
php -l bluevpn-manager/bluevpn-manager.php
python3 -m unittest tests.test_free_source_resilience_515 tests.test_gateway_phase3_start_515 -v
```

Create a feature branch before committing.

## Important

The bundled `bluevpn-manager/` directory is an overlay, not a complete plugin ZIP. Do not replace the whole live plugin directory with only these files; apply the patch or copy the included files over the matching paths in a full checkout/deployment package.
