from __future__ import annotations

import os
import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
UPSTREAM_APP = ROOT / "upstream" / "V2rayNG" / "app"
DEPS = ROOT / ".bluevpn-deps"
AETHER_REPO = "https://github.com/CluvexStudio/Aether.git"
AETHER_COMMIT = "a26159b82a70048b459e0128213c71767abecb8a"
NDK_VERSION = "29.0.14206865"
TARGETS = {
    "arm64-v8a": "aarch64-linux-android",
    "armeabi-v7a": "armv7-linux-androideabi",
}
API = 24


def run(*args: str, cwd: Path | None = None, env: dict[str, str] | None = None) -> None:
    print("+", " ".join(args))
    subprocess.run(args, cwd=cwd, env=env, check=True)


def ndk_root() -> Path:
    for key in ("NDK_HOME", "ANDROID_NDK_HOME"):
        value = os.environ.get(key)
        if value and Path(value).is_dir():
            return Path(value)
    home = os.environ.get("ANDROID_HOME") or os.environ.get("ANDROID_SDK_ROOT")
    if home:
        candidate = Path(home) / "ndk" / NDK_VERSION
        if candidate.is_dir():
            return candidate
    raise SystemExit("Android NDK is not available")


def host_tag(ndk: Path) -> str:
    prebuilt = ndk / "toolchains" / "llvm" / "prebuilt"
    for tag in ("linux-x86_64", "linux-arm64"):
        if (prebuilt / tag).is_dir():
            return tag
    found = next((p.name for p in prebuilt.iterdir() if p.is_dir()), None)
    if not found:
        raise SystemExit("NDK LLVM host toolchain not found")
    return found


def main() -> None:
    if not UPSTREAM_APP.is_dir():
        raise SystemExit("Run after the v2rayNG checkout")
    aether = DEPS / "aether"
    if not aether.exists():
        DEPS.mkdir(parents=True, exist_ok=True)
        run("git", "clone", "--recursive", AETHER_REPO, str(aether))
    run("git", "fetch", "origin", AETHER_COMMIT, cwd=aether)
    run("git", "checkout", "--detach", AETHER_COMMIT, cwd=aether)
    run("git", "submodule", "update", "--init", "--recursive", cwd=aether)

    core = aether / "aether"
    if not (core / "Cargo.toml").is_file():
        raise SystemExit("Pinned Aether source layout changed")

    run("rustup", "target", "add", *TARGETS.values())
    ndk = ndk_root()
    tools = ndk / "toolchains" / "llvm" / "prebuilt" / host_tag(ndk) / "bin"
    out = UPSTREAM_APP / "src" / "main" / "jniLibs"

    for abi, triple in TARGETS.items():
        clang_triple = "armv7a-linux-androideabi" if abi == "armeabi-v7a" else triple
        clang = tools / f"{clang_triple}{API}-clang"
        if not clang.is_file():
            raise SystemExit(f"NDK clang missing: {clang}")
        env = os.environ.copy()
        normalized = triple.upper().replace("-", "_")
        env_key = triple.replace("-", "_")
        sysroot = (tools / "../sysroot").resolve()
        env["ANDROID_NDK_HOME"] = str(ndk)
        env["ANDROID_NDK_ROOT"] = str(ndk)
        env[f"CARGO_TARGET_{normalized}_LINKER"] = str(clang)
        env[f"CARGO_TARGET_{normalized}_RUSTFLAGS"] = "-C link-arg=-Wl,-z,max-page-size=16384"
        env[f"CC_{env_key}"] = str(clang)
        env[f"AR_{env_key}"] = str(tools / "llvm-ar")
        env[f"BINDGEN_EXTRA_CLANG_ARGS_{env_key}"] = f"--target={triple} --sysroot={sysroot}"
        env["RUST_LIBC_UNSTABLE_MUSL_V1_2_3"] = "1"
        run("cargo", "build", "--release", "--target", triple, "--bin", "aether", cwd=core, env=env)
        binary = core / "target" / triple / "release" / "aether"
        if not binary.is_file():
            raise SystemExit(f"Aether output missing for {abi}: {binary}")
        abi_dir = out / abi
        abi_dir.mkdir(parents=True, exist_ok=True)
        target = abi_dir / "libbluevpn_aether.so"
        shutil.copy2(binary, target)
        target.chmod(0o755)
        print(f"Aether {AETHER_COMMIT[:12]} -> {target}")


if __name__ == "__main__":
    main()
