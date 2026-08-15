from __future__ import annotations

import hashlib
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


def run(*args: str, cwd: Path | None = None, env: dict[str, str] | None = None, capture: bool = False) -> str:
    print("+", " ".join(args))
    cp = subprocess.run(args, cwd=cwd, env=env, check=True, text=True, stdout=subprocess.PIPE if capture else None, stderr=subprocess.STDOUT if capture else None)
    return cp.stdout or ""


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


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
    resolved = run("git", "rev-parse", "HEAD", cwd=aether, capture=True).strip()
    if resolved != AETHER_COMMIT:
        raise SystemExit(f"Aether pin mismatch: expected {AETHER_COMMIT}, got {resolved}")
    submodules = run("git", "submodule", "status", "--recursive", cwd=aether, capture=True).strip()

    core = aether / "aether"
    if not (core / "Cargo.toml").is_file():
        raise SystemExit("Pinned Aether source layout changed")

    lock = core / "Cargo.lock"
    if not lock.is_file():
        raise SystemExit("Pinned Aether Cargo.lock is missing")
    provenance = ROOT / "reports" / "AETHER-PROVENANCE-4.6.6.txt"
    provenance.parent.mkdir(parents=True, exist_ok=True)
    lines = [f"commit={resolved}", f"cargo_lock_sha256={sha256(lock)}", "submodules=", submodules or "(none)"]

    # Host CLI regression gate from the exact pinned source.
    run("cargo", "build", "--release", "--locked", "--bin", "aether", cwd=core)
    host_binary = core / "target" / "release" / "aether"
    version = run(str(host_binary), "--version", cwd=core, capture=True).strip()
    help_text = run(str(host_binary), "--help", cwd=core, capture=True)
    required_help = ["--quick-reconnect", "--no-quick-reconnect", "--h2", "--fragment", "--startup-secs"]
    missing = [flag for flag in required_help if flag not in help_text]
    if missing:
        raise SystemExit(f"Pinned Aether CLI is missing required flags: {missing}")
    lines += [f"host_version={version}", f"host_sha256={sha256(host_binary)}", "required_help_flags=PASS"]

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
        run("cargo", "build", "--release", "--locked", "--target", triple, "--bin", "aether", cwd=core, env=env)
        binary = core / "target" / triple / "release" / "aether"
        if not binary.is_file():
            raise SystemExit(f"Aether output missing for {abi}: {binary}")
        abi_dir = out / abi
        abi_dir.mkdir(parents=True, exist_ok=True)
        target = abi_dir / "libbluevpn_aether.so"
        shutil.copy2(binary, target)
        target.chmod(0o755)
        digest = sha256(target)
        file_info = run("file", str(target), capture=True).strip()
        lines += [f"{abi}_sha256={digest}", f"{abi}_file={file_info}"]
        print(f"Aether {AETHER_COMMIT[:12]} -> {target} sha256={digest}")

    provenance.write_text("\n".join(lines) + "\n", encoding="utf-8")
    print(f"Aether provenance -> {provenance}")


if __name__ == "__main__":
    main()
