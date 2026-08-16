# BlueVPN 4.12.2 — pre-Gradle stage hardening

The 4.12.1 build log proved the Python/Pillow stage completes. The log then ended because the following pre-Gradle steps did not append their progress to `android-build.log`, leaving the actual cutoff opaque.

4.12.2 keeps one continuous diagnostic log through cleanup, Android overlay, cache restore, Rust target setup, Aether build/verify and auth overlay. A cache outage is no longer allowed to fail the production build, and Rust target setup uses the runner's own `rustup` with retry rather than a third-party setup action.
