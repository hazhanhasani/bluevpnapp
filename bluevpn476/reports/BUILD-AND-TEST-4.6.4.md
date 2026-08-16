# BlueVPN 4.6.4 — Kotlin WARP import build hotfix

Root cause: `BlueVpnWarpEngine` is declared in `com.v2ray.ang.bluevpn` while `BlueVpnHomeActivity` is declared in `com.v2ray.ang.ui`. The 4.6.3 source referenced the object but omitted the import, so Kotlin reported `Unresolved reference 'BlueVpnWarpEngine'`.

Fix: add `import com.v2ray.ang.bluevpn.BlueVpnWarpEngine` and add static regression guards.

Full Gradle compilation is expected to be verified by GitHub Actions against the pinned upstream checkout.
