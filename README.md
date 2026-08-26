# BlueVPN

[![Project Health](https://github.com/hazhanhasani/bluevpnapp/actions/workflows/project-health.yml/badge.svg)](https://github.com/hazhanhasani/bluevpnapp/actions/workflows/project-health.yml)
[![Android](https://github.com/hazhanhasani/bluevpnapp/actions/workflows/build-apk.yml/badge.svg)](https://github.com/hazhanhasani/bluevpnapp/actions/workflows/build-apk.yml)
[![Windows](https://github.com/hazhanhasani/bluevpnapp/actions/workflows/build-windows.yml/badge.svg)](https://github.com/hazhanhasani/bluevpnapp/actions/workflows/build-windows.yml)

BlueVPN is a multi-platform VPN project with Android, Windows and iOS clients, a WordPress control plane, a site theme, and managed gateway tooling. The repository also contains the release validators and CI/CD orchestration required to publish synchronized builds.

## Components

| Component | Repository path | Purpose |
| --- | --- | --- |
| Android | `android-source/` | BlueVPN overlay on the pinned v2rayNG/Xray runtime |
| Windows | `bluevpn-windows/` | .NET/WPF client and Windows runtime integration |
| iOS | `bluevpn-ios/` | Swift client |
| Manager | `bluevpn-manager/` | WordPress control-plane plugin |
| Site | `bluevpn-site/` | WordPress theme/site integration |
| Gateway | `bluevpn-gateway/` | Managed gateway agent and installer |
| Release tooling | `scripts/` | Versioning, preparation, validation and build helpers |
| Regression gates | `tests/` | Static and behavioral release checks |

## Architecture contract

BlueVPN uses a **hidden-route location architecture**: users select a public location, while the eligible internal routes behind that location remain hidden from the public UI. BlueVPN selects the best eligible internal route according to entitlement, health and runtime policy. Free and Premium route pools remain isolated.

See [docs/architecture.md](docs/architecture.md) for the full component and data-flow model.

## Versioning

`version.json` is the canonical project version. All shipped components are expected to remain synchronized with it.

BlueVPN uses bounded semantic-style versions:

```text
X.Y.Z
0 <= Y <= 10
0 <= Z <= 10
```

After `X.Y.10`, the next version is `X.(Y+1).0`. After `X.10.10`, the next version is `(X+1).0.0`.

Do not hand-edit component versions independently. The release pipeline validates synchronization and performs the project-wide bump.

## Documentation

Start at **[docs/README.md](docs/README.md)**.

- [Architecture](docs/architecture.md)
- [Release process](docs/release-process.md)
- [Android](docs/android.md)
- [Windows](docs/windows.md)
- [iOS](docs/ios.md)
- [WordPress control plane](docs/control-plane.md)
- [Gateway](docs/gateway.md)
- [Troubleshooting](docs/troubleshooting.md)
- [Security](docs/security.md)
- [Contribution guide](CONTRIBUTING.md)
- [Wiki source pages](docs/wiki/Home.md)

## Supported build path

GitHub Actions is the authoritative release path. Android is prepared from the pinned upstream source and the canonical BlueVPN overlay before Gradle compilation. Windows, iOS, Manager and Theme releases are dispatched from the central Project Health workflow.

For repository validation, the Project Health gate checks syntax, release metadata, the release-test manifest, and the Python regression suite before publishing fan-out is allowed.

## Repository hygiene

Generated APKs, installers, caches, temporary diagnostics and one-off release notes do not belong in the repository root. Build outputs are retained as GitHub Actions artifacts or GitHub Releases. Historical files removed from the working tree remain available through Git history.

## Security

Never commit production secrets, API tokens, signing keys, WordPress credentials, subscription URLs, private endpoints or `.env` files. See [docs/security.md](docs/security.md).

## License

See [LICENSE](LICENSE) and [NOTICE.md](NOTICE.md).
