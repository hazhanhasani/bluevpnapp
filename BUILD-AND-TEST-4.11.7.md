# BlueVPN 4.11.7 — WARP-Off Smart Free Pool

Fixes a policy bug where disabling WARP could make Android resolve the user as
UNAVAILABLE instead of FREE.

Changes:
- Telegram/public curated pool is now a first-class `smart-curated` Free subscription;
- WARP disabled + non-warp-only mode is migration-safely interpreted as pool-only,
  even for 4.11.6 settings where the legacy pool checkbox remained false;
- saving the Free panel with WARP disabled explicitly stores `pool_only` and keeps
  Smart Free Pool enabled;
- `smart-curated` is served locally from MySQL ranking data without WordPress making
  a loopback HTTP request to itself;
- pool-only Android proactively imports the Free subscription on Home resume;
- after import, Background Full-Pool AI is queued immediately so configs are tested
  with the user's real network before Connect;
- admin labels no longer call the Smart Pool "legacy/old".

Validation executed:
- Release validator: PASS
- Python regression suite: 406/406 PASS
- PHP release lint: 25/25 PASS
- GitHub Actions YAML parse: PASS
- Test manifest: exact
- PHP release manifest: exact
