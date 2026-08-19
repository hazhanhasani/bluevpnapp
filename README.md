# BlueVPN

BlueVPN is the source repository for the BlueVPN Android client and its WordPress control plane.

## Architecture

- **Android client:** BlueVPN UI on the pinned v2rayNG/Xray runtime.
- **Windows client:** installed .NET 10 WPF BlueVPN UI with a verified official v2rayN runtime bundle, Xray/sing-box TUN routing, automatic app/runtime updates, and Aether/WARP on Windows x64.
- **Premium:** managed subscription routes provisioned by BlueVPN Manager.
- **Free:** Aether/WARP loopback transport with policy-controlled fallback.
- **Free/Premium isolation:** free and paid pools, entitlement state, route identity and runtime selection are kept separate.
- **Control plane:** WordPress + MySQL via `bluevpn-manager`.
- **Website:** `bluevpn-site`.
- **Hidden location architecture:** internal routes stay hidden from the public UI; users select a location while BlueVPN chooses the best eligible internal route.
- **CI/CD:** GitHub Actions validates release metadata, prepares/builds Android, compiles/publishes Windows x64+ARM64, validates v2rayN TUN configs, creates installers, and feeds every workflow result into the independent BlueVPN Sentinel. A full-project syntax/regression gate and a scheduled external WordPress/MySQL health probe provide additional coverage.

## Repository layout

```text
.github/workflows/   GitHub Actions
android-source/      Canonical Android overlay
bluevpn-windows/     Windows WPF client + v2rayN/WARP runtime integration + installer
branding/            App branding/version metadata
bluevpn-manager/     WordPress control-plane plugin
bluevpn-site/        WordPress theme
scripts/             Build/release tooling
tests/               Regression/release gates
third_party/         Required third-party provenance/notes
release.json         Canonical release metadata
README.md            Canonical repository readme
LICENSE              License
NOTICE.md            Required notices
```

## Release rules

`branding/app.json`, `release.json`, Android, BlueVPN Manager, BlueVPN Site and BlueVPN Windows metadata must remain synchronized.

Versioning:

```text
X.Y.Z where 0 <= Y <= 10 and 0 <= Z <= 10
```

After `X.Y.10`, the next release is `X.(Y+1).0`. After `X.10.10`, the next release is `(X+1).0.0`. Invalid forms such as `4.18.0` are rejected by release validation.

Generated build reports, caches, temporary diagnostics and historical one-off notes are excluded from Git. CI output belongs in GitHub Actions artifacts.

## Build

The supported build path is GitHub Actions. `scripts/prepare_android.py` applies the BlueVPN Android overlay to the pinned upstream source before Gradle compilation.

## Security

Never commit production secrets, API tokens, signing keystores, WordPress credentials or `.env` files.

## Repository policy

A **full platform deployment** is authoritative: the BlueVPN deployment bot mirrors the uploaded platform and removes obsolete/generated tracked files that are not part of the current project.

A **Manager-only deployment** updates only `bluevpn-manager/` and never performs repository-wide cleanup.
