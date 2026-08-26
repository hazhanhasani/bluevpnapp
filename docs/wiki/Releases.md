# Releases

BlueVPN uses `version.json` as the synchronized version source of truth.

A normal release is:

1. source change on `main`;
2. Project Health static/regression gate;
3. automated version bump;
4. platform/component fan-out at the exact release SHA;
5. build/sign/publish steps inside each platform workflow;
6. external health and Sentinel observation.

Project Health success means publication was allowed to start; it does not by itself prove every platform artifact finished successfully. Check the actual Android/Windows/iOS/Manager/Theme run for final status.

For full details, see `docs/release-process.md`.
