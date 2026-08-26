# Architecture

BlueVPN uses a shared control plane with platform-specific clients.

```text
WordPress Manager
      |
      +-- Android
      +-- Windows
      +-- iOS
      |
      +-- Gateway management
```

The central Project Health workflow validates the repository before dispatching synchronized platform releases. Android is produced from a pinned upstream v2rayNG checkout plus the canonical BlueVPN overlay. Windows and iOS have dedicated client sources, while Manager/Theme provide the server and website layers.

For the detailed version-controlled document, see `docs/architecture.md` in the main repository.
