# BlueVPN 4.9.8 build/test summary

## Fixes
- Prevent WARP backoff starvation: if every allowed strategy is cooling down, force one bounded recovery probe using the strategy whose cooldown expires first.
- Preserve failure diagnostics with attempted/skipped/recovery strategy details.
- Prevent stale Free/WARP location state from being presented as ready after the WARP runtime enters FAILED.

## Validation executed
- Python regression suite: 208/208 PASS
- Release validator: PASS
- PHP syntax lint: 36/36 PASS
- GitHub Actions YAML parse: 3/3 PASS

Full Android Gradle compilation is performed by the repository CI because the shipped package bootstraps the pinned v2rayNG upstream during CI.
