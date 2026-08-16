# BlueVPN 4.12.1 — Pre-Gradle Python cutoff fix

The uploaded 4.12.0 log proves `libv2ray.aar` was downloaded and verified successfully, then the captured log ends before Android source preparation or Gradle. The immediately following external setup action was therefore removed as a failure surface. The runner Python already executes several earlier workflow scripts successfully, so BlueVPN now validates and reuses that same interpreter, installs Pillow with retry, records the exact stage, and keeps the pre-Gradle trace when Gradle begins.

Validation: run `python -m unittest discover -s tests -p 'test_*.py' -v`, `python scripts/validate_release.py`, PHP lint, YAML parse, and ZIP integrity before delivery.
