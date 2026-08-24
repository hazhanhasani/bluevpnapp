# Pool stale-while-revalidate — 5.3.8

- Premium readiness now uses the effective entitlement-isolated pool, including the exact same account/source last-known-good snapshot.
- The Android location snapshot stays warm for 24 hours and is still invalidated immediately when account, tier, pool identity, or source changes.
- Opening Locations and pressing Connect no longer waits for a provider refresh when a usable local pool already exists.
- Free/Premium ownership boundaries remain enforced by the existing semantic fingerprint gate.
- Android overlay preparation now tolerates the official `MainViewModel.kt` relocation while still hashing and enforcing the mandatory Core/VPN runtime boundary.
- Manifest launcher replacement now parses the official XML and finds the real `MAIN` + `LAUNCHER` component, so renamed activities, self-closing nodes, and reordered attributes no longer break CI.
