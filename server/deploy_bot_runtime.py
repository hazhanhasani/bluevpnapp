
from __future__ import annotations

import asyncio
import base64
import io
import json
import html
import logging
import os
import re
import secrets
import shutil
import stat
import subprocess
import tempfile
import time
import zipfile
from pathlib import Path
from typing import Any
from urllib.parse import quote

import httpx
from nacl import encoding, public
from telegram import InlineKeyboardButton, InlineKeyboardMarkup, ReplyKeyboardMarkup, Update
from telegram.constants import ChatAction, ParseMode
from telegram.request import HTTPXRequest
from telegram.ext import (
    Application,
    CallbackQueryHandler,
    CommandHandler,
    ContextTypes,
    MessageHandler,
    filters,
)

logging.basicConfig(
    format="%(asctime)s | %(levelname)s | %(name)s | %(message)s",
    level=logging.INFO,
)
logger = logging.getLogger("bluevpn-one-click-bot")

DEPLOY_BOT_VERSION = "2.8-resilient-telegram-startup"
BUILD_TRIGGER_MODE = "git-empty-commit-push"


def _env_int(name: str, default: int, minimum: int = 1) -> int:
    try:
        return max(minimum, int(os.getenv(name, str(default))))
    except (TypeError, ValueError):
        return max(minimum, default)


def _env_float(name: str, default: float, minimum: float = 0.1) -> float:
    try:
        return max(minimum, float(os.getenv(name, str(default))))
    except (TypeError, ValueError):
        return max(minimum, default)


def _telegram_request(*, updates: bool = False) -> HTTPXRequest:
    """Build a deterministic HTTP/1.1 transport for Railway.

    PTB defaults to a 5-second connect/read timeout. That is too aggressive
    during occasional Railway or Telegram route stalls and can make get_me()
    fail while the web/API service is otherwise healthy.
    """
    prefix = "TELEGRAM_GET_UPDATES" if updates else "TELEGRAM"
    default_pool = 4 if updates else 16
    default_read = 65.0 if updates else 35.0

    return HTTPXRequest(
        connection_pool_size=_env_int(
            f"{prefix}_CONNECTION_POOL_SIZE",
            default_pool,
        ),
        connect_timeout=_env_float("TELEGRAM_CONNECT_TIMEOUT", 35.0),
        read_timeout=_env_float(
            f"{prefix}_READ_TIMEOUT",
            default_read,
        ),
        write_timeout=_env_float("TELEGRAM_WRITE_TIMEOUT", 35.0),
        pool_timeout=_env_float("TELEGRAM_POOL_TIMEOUT", 35.0),
        media_write_timeout=_env_float(
            "TELEGRAM_MEDIA_WRITE_TIMEOUT",
            180.0,
        ),
        http_version="1.1",
    )


def require_env(name: str) -> str:
    value = os.getenv(name, "").strip()
    if not value:
        raise RuntimeError(f"Environment variable {name} is required")
    return value


BOT_TOKEN = require_env("BOT_TOKEN")
GITHUB_TOKEN = require_env("GITHUB_TOKEN")
GITHUB_REPOSITORY = require_env("GITHUB_REPOSITORY")
GIT_BRANCH = os.getenv("GIT_BRANCH", "main").strip() or "main"
GITHUB_WORKFLOW = os.getenv("GITHUB_WORKFLOW", "build-apk.yml").strip() or "build-apk.yml"
GIT_AUTHOR_NAME = os.getenv("GIT_AUTHOR_NAME", "BlueVPN Deploy Bot").strip()
GIT_AUTHOR_EMAIL = os.getenv(
    "GIT_AUTHOR_EMAIL",
    "bluevpn-deploy-bot@users.noreply.github.com",
).strip()
MAX_ZIP_MB = int(os.getenv("MAX_ZIP_MB", "50"))
MAX_EXTRACTED_MB = int(os.getenv("MAX_EXTRACTED_MB", "900"))
MAX_FILES = int(os.getenv("MAX_FILES", "25000"))
BUILD_TIMEOUT_SECONDS = int(os.getenv("BUILD_TIMEOUT_SECONDS", "5400"))
GITHUB_API_VERSION = "2022-11-28"
GITHUB_API = "https://api.github.com"

try:
    ADMIN_IDS = {
        int(item.strip())
        for item in require_env("ADMIN_IDS").split(",")
        if item.strip()
    }
except ValueError as exc:
    raise RuntimeError("ADMIN_IDS must contain Telegram numeric IDs") from exc

if "/" not in GITHUB_REPOSITORY or GITHUB_REPOSITORY.startswith("http"):
    raise RuntimeError("GITHUB_REPOSITORY must use OWNER/REPOSITORY format")

OWNER, REPO = GITHUB_REPOSITORY.split("/", 1)

PROTECTED_NAMES = {
    "Dockerfile",
    ".env",
    "BlueVPN-release.jks",
}
PROTECTED_SUFFIXES = {".jks", ".keystore", ".p12", ".pfx"}
DELETE_MANIFEST = ".bluevpn-delete"

ACTIVE_CHAT_JOBS: set[int] = set()
ACTIVE_JOB_STATUS: dict[int, str] = {}


def keyboard() -> ReplyKeyboardMarkup:
    return ReplyKeyboardMarkup(
        [
            ["📦 نصب و ساخت خودکار"],
            ["🟡 صف GuardCore", "📊 وضعیت"],
            ["🛠 ساخت دوباره", "⬇️ دریافت آخرین APK"],
            ["🔐 بررسی امضا"],
        ],
        resize_keyboard=True,
    )


def is_admin(user_id: int | None) -> bool:
    return user_id is not None and user_id in ADMIN_IDS


def gh_headers() -> dict[str, str]:
    return {
        "Accept": "application/vnd.github+json",
        "Authorization": f"Bearer {GITHUB_TOKEN}",
        "X-GitHub-Api-Version": GITHUB_API_VERSION,
        "User-Agent": "BlueVPN-One-Click-Bot",
    }


def redact(text: str) -> str:
    return text.replace(GITHUB_TOKEN, "***").replace(BOT_TOKEN, "***")


async def gh_request(
    method: str,
    path: str,
    *,
    json_body: dict[str, Any] | None = None,
    timeout: float = 30.0,
) -> httpx.Response:
    async with httpx.AsyncClient(
        headers=gh_headers(),
        follow_redirects=True,
        timeout=timeout,
    ) as client:
        return await client.request(
            method,
            f"{GITHUB_API}{path}",
            json=json_body,
        )


async def get_repo_public_key() -> tuple[str, str]:
    response = await gh_request(
        "GET",
        f"/repos/{OWNER}/{REPO}/actions/secrets/public-key",
    )
    if response.status_code >= 400:
        raise RuntimeError(
            "دریافت کلید عمومی GitHub ناموفق بود. "
            "توکن باید دسترسی Secrets داشته باشد. "
            f"HTTP {response.status_code}: {response.text[-700:]}"
        )
    data = response.json()
    return data["key"], data["key_id"]


def encrypt_secret(public_key_b64: str, secret_value: str) -> str:
    public_key = public.PublicKey(
        public_key_b64.encode("utf-8"),
        encoding.Base64Encoder(),
    )
    sealed_box = public.SealedBox(public_key)
    encrypted = sealed_box.encrypt(secret_value.encode("utf-8"))
    return base64.b64encode(encrypted).decode("utf-8")


async def put_repo_secret(
    name: str,
    value: str,
    public_key_b64: str,
    key_id: str,
) -> None:
    response = await gh_request(
        "PUT",
        f"/repos/{OWNER}/{REPO}/actions/secrets/{name}",
        json_body={
            "encrypted_value": encrypt_secret(public_key_b64, value),
            "key_id": key_id,
        },
    )
    if response.status_code not in {201, 204}:
        raise RuntimeError(
            f"ثبت Secret {name} ناموفق بود: "
            f"HTTP {response.status_code} {response.text[-700:]}"
        )


async def list_secret_names() -> set[str]:
    response = await gh_request(
        "GET",
        f"/repos/{OWNER}/{REPO}/actions/secrets?per_page=100",
    )
    if response.status_code >= 400:
        raise RuntimeError(
            "خواندن Secretهای GitHub ناموفق بود. "
            "توکن باید دسترسی Secrets داشته باشد. "
            f"HTTP {response.status_code}: {response.text[-700:]}"
        )
    return {item["name"] for item in response.json().get("secrets", [])}


def generate_signing_kit() -> dict[str, str | bytes]:
    password = secrets.token_urlsafe(30)
    alias = "bluevpn_release"

    with tempfile.TemporaryDirectory(prefix="bluevpn-signing-") as temp:
        keystore = Path(temp) / "BlueVPN-release.jks"
        command = [
            "keytool",
            "-genkeypair",
            "-v",
            "-keystore",
            str(keystore),
            "-storepass",
            password,
            "-keypass",
            password,
            "-alias",
            alias,
            "-keyalg",
            "RSA",
            "-keysize",
            "2048",
            "-validity",
            "10000",
            "-dname",
            "CN=BlueVPN, OU=Mobile, O=BlueVPN",
        ]
        result = subprocess.run(
            command,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            timeout=120,
        )
        if result.returncode != 0 or not keystore.exists():
            raise RuntimeError(
                "ساخت کلید امضای اندروید ناموفق بود:\n"
                + (result.stderr or result.stdout)[-1800:]
            )

        raw = keystore.read_bytes()

    return {
        "keystore_bytes": raw,
        "keystore_base64": base64.b64encode(raw).decode("ascii"),
        "password": password,
        "alias": alias,
    }


def create_signing_backup_zip(kit: dict[str, str | bytes]) -> bytes:
    memory = io.BytesIO()
    with zipfile.ZipFile(memory, "w", zipfile.ZIP_DEFLATED) as zf:
        zf.writestr("BlueVPN-release.jks", kit["keystore_bytes"])
        zf.writestr(
            "GITHUB_SECRETS.txt",
            "\n".join(
                [
                    f"ANDROID_KEYSTORE_PASSWORD={kit['password']}",
                    f"ANDROID_KEY_ALIAS={kit['alias']}",
                    f"ANDROID_KEY_PASSWORD={kit['password']}",
                    "",
                    "ANDROID_KEYSTORE_BASE64 is already saved in GitHub Actions Secrets.",
                    "Keep this ZIP private. It is required for disaster recovery.",
                ]
            ),
        )
        zf.writestr(
            "README_FA.txt",
            "این فایل بکاپ کلید امضای دائمی BlueVPN است.\n"
            "آن را در جای امن نگه دارید و داخل GitHub آپلود نکنید.\n",
        )
    return memory.getvalue()


async def ensure_automation_secrets(
    context: ContextTypes.DEFAULT_TYPE | None = None,
    chat_id: int | None = None,
) -> dict[str, Any]:
    required = {
        "ANDROID_KEYSTORE_BASE64",
        "ANDROID_KEYSTORE_PASSWORD",
        "ANDROID_KEY_ALIAS",
        "ANDROID_KEY_PASSWORD",
        "TELEGRAM_BOT_TOKEN",
        "TELEGRAM_CHAT_ID",
    }
    existing = await list_secret_names()
    missing = required - existing
    result: dict[str, Any] = {
        "existing": sorted(existing),
        "created": [],
        "signing_backup": None,
    }
    if not missing:
        return result

    public_key_b64, key_id = await get_repo_public_key()

    # Telegram delivery secrets are always synchronized from Railway.
    if "TELEGRAM_BOT_TOKEN" in missing:
        await put_repo_secret(
            "TELEGRAM_BOT_TOKEN",
            BOT_TOKEN,
            public_key_b64,
            key_id,
        )
        result["created"].append("TELEGRAM_BOT_TOKEN")

    if "TELEGRAM_CHAT_ID" in missing:
        destination = str(chat_id or sorted(ADMIN_IDS)[0])
        await put_repo_secret(
            "TELEGRAM_CHAT_ID",
            destination,
            public_key_b64,
            key_id,
        )
        result["created"].append("TELEGRAM_CHAT_ID")

    signing_names = {
        "ANDROID_KEYSTORE_BASE64",
        "ANDROID_KEYSTORE_PASSWORD",
        "ANDROID_KEY_ALIAS",
        "ANDROID_KEY_PASSWORD",
    }
    if signing_names & missing:
        # Never generate only part of a signing identity.
        if signing_names & existing:
            raise RuntimeError(
                "بعضی Secretهای امضا وجود دارند و بعضی وجود ندارند. "
                "برای جلوگیری از خراب‌شدن امضای اپ، چهار Secret امضا را "
                "کامل حذف یا کامل اصلاح کنید."
            )

        kit = generate_signing_kit()
        signing_values = {
            "ANDROID_KEYSTORE_BASE64": str(kit["keystore_base64"]),
            "ANDROID_KEYSTORE_PASSWORD": str(kit["password"]),
            "ANDROID_KEY_ALIAS": str(kit["alias"]),
            "ANDROID_KEY_PASSWORD": str(kit["password"]),
        }
        for name, value in signing_values.items():
            await put_repo_secret(
                name,
                value,
                public_key_b64,
                key_id,
            )
            result["created"].append(name)

        result["signing_backup"] = create_signing_backup_zip(kit)

    return result


def validate_zip(infos: list[zipfile.ZipInfo]) -> None:
    if len(infos) > MAX_FILES:
        raise RuntimeError(f"تعداد فایل‌ها بیشتر از حد مجاز است: {len(infos)}")

    total = 0
    for info in infos:
        normalized = info.filename.replace("\\", "/")
        path = Path(normalized)
        if normalized.startswith("/") or ".." in path.parts:
            raise RuntimeError(f"مسیر ناامن داخل ZIP: {info.filename}")
        mode = (info.external_attr >> 16) & 0xFFFF
        if stat.S_ISLNK(mode):
            raise RuntimeError(f"لینک نمادین مجاز نیست: {info.filename}")
        total += info.file_size
        if total > MAX_EXTRACTED_MB * 1024 * 1024:
            raise RuntimeError(
                f"حجم استخراج‌شده بیشتر از {MAX_EXTRACTED_MB} مگابایت است."
            )


def safe_extract(zip_path: Path, target: Path) -> Path:
    target.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(zip_path) as archive:
        infos = archive.infolist()
        validate_zip(infos)
        root = target.resolve()
        for info in infos:
            relative = info.filename.replace("\\", "/").lstrip("/")
            if not relative:
                continue
            destination = (target / relative).resolve()
            if destination != root and root not in destination.parents:
                raise RuntimeError(f"مسیر ناامن داخل ZIP: {info.filename}")
            if info.is_dir():
                destination.mkdir(parents=True, exist_ok=True)
                continue
            destination.parent.mkdir(parents=True, exist_ok=True)
            with archive.open(info) as source, open(destination, "wb") as output:
                shutil.copyfileobj(source, output)

    entries = [
        item for item in target.iterdir()
        if item.name not in {"__MACOSX", ".DS_Store"}
    ]
    if len(entries) == 1 and entries[0].is_dir():
        return entries[0]
    return target


def protected(relative: Path) -> bool:
    if ".git" in relative.parts or "__pycache__" in relative.parts:
        return True
    if relative.name in PROTECTED_NAMES:
        return True
    if relative.suffix.lower() in PROTECTED_SUFFIXES:
        return True
    return False


def apply_deletions(source: Path, repo: Path) -> int:
    manifest = source / DELETE_MANIFEST
    if not manifest.exists():
        return 0
    count = 0
    root = repo.resolve()
    for raw in manifest.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        relative = Path(line.replace("\\", "/").lstrip("/"))
        if ".." in relative.parts or protected(relative):
            continue
        destination = (repo / relative).resolve()
        if destination != root and root not in destination.parents:
            continue
        if destination.is_dir() and not destination.is_symlink():
            shutil.rmtree(destination)
            count += 1
        elif destination.exists() or destination.is_symlink():
            destination.unlink(missing_ok=True)
            count += 1
    return count


def deploy_zip(zip_path: Path) -> dict[str, Any]:
    token = quote(GITHUB_TOKEN, safe="")
    remote = f"https://x-access-token:{token}@github.com/{GITHUB_REPOSITORY}.git"

    with tempfile.TemporaryDirectory(prefix="bluevpn-deploy-") as temp:
        temp_path = Path(temp)
        repo = temp_path / "repo"
        extracted = temp_path / "extracted"
        source = safe_extract(zip_path, extracted)

        result = subprocess.run(
            [
                "git",
                "clone",
                "--depth",
                "1",
                "--branch",
                GIT_BRANCH,
                remote,
                str(repo),
            ],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            timeout=420,
        )
        if result.returncode != 0:
            raise RuntimeError(redact((result.stderr or result.stdout)[-3000:]))

        def git(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
            run = subprocess.run(
                ["git", *args],
                cwd=repo,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                text=True,
                timeout=420,
            )
            if check and run.returncode != 0:
                raise RuntimeError(redact((run.stderr or run.stdout)[-3000:]))
            return run

        git("config", "user.name", GIT_AUTHOR_NAME)
        git("config", "user.email", GIT_AUTHOR_EMAIL)

        deleted = apply_deletions(source, repo)
        copied = 0
        skipped: list[str] = []

        for item in source.rglob("*"):
            relative = item.relative_to(source)
            if item.name == DELETE_MANIFEST:
                continue
            if protected(relative):
                if item.is_file():
                    skipped.append(relative.as_posix())
                continue
            destination = repo / relative
            if item.is_dir():
                destination.mkdir(parents=True, exist_ok=True)
            else:
                destination.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(item, destination)
                copied += 1

        if copied == 0 and deleted == 0:
            raise RuntimeError("ZIP هیچ فایل قابل نصب ندارد.")

        git("add", "-A")
        diff = git("diff", "--cached", "--quiet", check=False)
        if diff.returncode == 0:
            commit = git("rev-parse", "HEAD").stdout.strip()
            return {
                "changed": False,
                "commit": commit,
                "copied": copied,
                "deleted": deleted,
                "skipped": skipped,
            }

        git("commit", "-m", "Deploy BlueVPN automatically from Telegram")
        commit = git("rev-parse", "HEAD").stdout.strip()
        git("push", "origin", GIT_BRANCH)
        return {
            "changed": True,
            "commit": commit,
            "copied": copied,
            "deleted": deleted,
            "skipped": skipped,
        }


def trigger_build_by_empty_commit() -> str:
    """
    Trigger the workflow through the existing `push` event.

    This deliberately avoids the workflow_dispatch API, which requires
    Actions: write on a fine-grained GitHub token. The repository token only
    needs permission to push to the selected repository.
    """
    token = quote(GITHUB_TOKEN, safe="")
    remote = f"https://x-access-token:{token}@github.com/{GITHUB_REPOSITORY}.git"

    with tempfile.TemporaryDirectory(prefix="bluevpn-trigger-") as temp:
        repo = Path(temp) / "repo"

        clone = subprocess.run(
            [
                "git",
                "clone",
                "--depth",
                "1",
                "--branch",
                GIT_BRANCH,
                remote,
                str(repo),
            ],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            timeout=420,
        )
        if clone.returncode != 0:
            raise RuntimeError(redact((clone.stderr or clone.stdout)[-3000:]))

        def git(*args: str) -> subprocess.CompletedProcess[str]:
            result = subprocess.run(
                ["git", *args],
                cwd=repo,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                text=True,
                timeout=420,
            )
            if result.returncode != 0:
                raise RuntimeError(
                    redact((result.stderr or result.stdout)[-3000:])
                )
            return result

        git("config", "user.name", GIT_AUTHOR_NAME)
        git("config", "user.email", GIT_AUTHOR_EMAIL)
        git(
            "commit",
            "--allow-empty",
            "-m",
            "Trigger BlueVPN APK build from Telegram",
        )
        commit = git("rev-parse", "HEAD").stdout.strip()
        git("push", "origin", GIT_BRANCH)
        return commit


async def workflow_runs() -> list[dict[str, Any]]:
    response = await gh_request(
        "GET",
        f"/repos/{OWNER}/{REPO}/actions/workflows/{GITHUB_WORKFLOW}/runs"
        f"?branch={quote(GIT_BRANCH)}&per_page=20",
    )
    if response.status_code >= 400:
        raise RuntimeError(
            f"خواندن Buildها ناموفق بود: HTTP {response.status_code} "
            f"{response.text[-600:]}"
        )
    return response.json().get("workflow_runs", [])


async def dispatch_build() -> str:
    """
    Start a build without requiring GitHub Actions write permission.

    The workflow already listens to pushes on the main branch, so an empty
    commit is enough to start a fresh build.
    """
    return await asyncio.to_thread(trigger_build_by_empty_commit)


async def wait_for_commit_run(
    commit_sha: str | None,
    previous_ids: set[int],
) -> dict[str, Any]:
    deadline = time.monotonic() + BUILD_TIMEOUT_SECONDS
    selected: dict[str, Any] | None = None

    while time.monotonic() < deadline:
        runs = await workflow_runs()
        for run in runs:
            if commit_sha and run.get("head_sha") == commit_sha:
                selected = run
                break
            if not commit_sha and run.get("id") not in previous_ids:
                selected = run
                break

        if selected:
            if selected.get("status") == "completed":
                return selected
            await asyncio.sleep(12)
            refreshed = await gh_request(
                "GET",
                f"/repos/{OWNER}/{REPO}/actions/runs/{selected['id']}",
            )
            if refreshed.status_code < 400:
                selected = refreshed.json()
                if selected.get("status") == "completed":
                    return selected
        else:
            await asyncio.sleep(8)

    raise RuntimeError("زمان انتظار برای پایان Build تمام شد.")


async def download_apks(run_id: int) -> tuple[list[Path], tempfile.TemporaryDirectory]:
    response = await gh_request(
        "GET",
        f"/repos/{OWNER}/{REPO}/actions/runs/{run_id}/artifacts?per_page=100",
    )
    if response.status_code >= 400:
        raise RuntimeError("دریافت Artifactها ناموفق بود.")

    artifacts = [
        item for item in response.json().get("artifacts", [])
        if not item.get("expired")
    ]
    if not artifacts:
        raise RuntimeError("برای Build موفق، Artifact پیدا نشد.")

    temp = tempfile.TemporaryDirectory(prefix="bluevpn-artifact-")
    root = Path(temp.name)
    apks: list[Path] = []

    async with httpx.AsyncClient(
        headers=gh_headers(),
        follow_redirects=True,
        timeout=240,
    ) as client:
        for artifact in artifacts:
            response = await client.get(
                f"{GITHUB_API}/repos/{OWNER}/{REPO}/actions/artifacts/"
                f"{artifact['id']}/zip"
            )
            if response.status_code >= 400:
                continue
            archive = root / f"{artifact['id']}.zip"
            archive.write_bytes(response.content)
            destination = root / str(artifact["id"])
            destination.mkdir()
            with zipfile.ZipFile(archive) as zf:
                zf.extractall(destination)
            apks.extend(destination.rglob("*.apk"))

    if not apks:
        temp.cleanup()
        raise RuntimeError("فایل APK داخل Artifact پیدا نشد.")
    return sorted(apks), temp


async def send_apks(
    update: Update,
    context: ContextTypes.DEFAULT_TYPE,
    run: dict[str, Any],
) -> None:
    apks, temp = await download_apks(int(run["id"]))
    try:
        for apk in apks:
            await context.bot.send_chat_action(
                chat_id=update.effective_chat.id,
                action=ChatAction.UPLOAD_DOCUMENT,
            )
            with apk.open("rb") as handle:
                await context.bot.send_document(
                    chat_id=update.effective_chat.id,
                    document=handle,
                    filename=apk.name,
                    caption=(
                        f"✅ BlueVPN Build #{run.get('run_number')}\n"
                        f"📦 {apk.name}\n"
                        "🔏 امضای دائمی فعال است"
                    ),
                    read_timeout=240,
                    write_timeout=240,
                    connect_timeout=60,
                )
    finally:
        temp.cleanup()


async def setup_and_send_backup(
    update: Update,
    context: ContextTypes.DEFAULT_TYPE,
) -> dict[str, Any]:
    result = await ensure_automation_secrets(
        context=context,
        chat_id=update.effective_chat.id,
    )
    backup = result.get("signing_backup")
    if backup:
        document = io.BytesIO(backup)
        document.name = "BlueVPN-signing-backup.zip"
        await context.bot.send_document(
            chat_id=update.effective_chat.id,
            document=document,
            filename=document.name,
            caption=(
                "🔐 بکاپ کلید امضای دائمی BlueVPN\n"
                "این فایل را در جای امن نگه دار و برای کسی نفرست."
            ),
        )
    return result


async def deny(update: Update) -> None:
    if update.effective_message:
        await update.effective_message.reply_text("⛔️ دسترسی ندارید.")


async def start(
    update: Update,
    context: ContextTypes.DEFAULT_TYPE,
) -> None:
    if not is_admin(update.effective_user.id if update.effective_user else None):
        await deny(update)
        return

    await update.effective_message.reply_text(
        "✅ <b>ربات خودکار BlueVPN آماده است</b>\n"f"نسخه ربات: {DEPLOY_BOT_VERSION}\n"f"روش Build: {BUILD_TRIGGER_MODE}\n\n"
        "ZIP پروژه یا آپدیت را بفرست.\n"
        "بررسی Secretها از مسیر نصب حذف شده تا ربات هیچ‌وقت آنجا گیر نکند.\n\n"
        "ربات خودش فایل‌ها را نصب می‌کند، Build را دنبال می‌کند و APK را می‌فرستد.",
        parse_mode=ParseMode.HTML,
        reply_markup=keyboard(),
    )


async def process_zip_job(
    update: Update,
    context: ContextTypes.DEFAULT_TYPE,
    zip_path: Path,
    progress,
    job_key: int,
    work_dir: Path,
) -> None:
    try:
        ACTIVE_JOB_STATUS[job_key] = "خواندن آخرین Buildهای GitHub"
        previous_runs = await workflow_runs()
        previous_ids = {int(run["id"]) for run in previous_runs}

        ACTIVE_JOB_STATUS[job_key] = "نصب فایل‌ها روی GitHub"
        await progress.edit_text(
            "📤 در حال نصب پروژه روی GitHub...\n\n"
            "بررسی Secretها رد شد؛ عملیات مستقیم ادامه دارد."
        )

        deployed = await asyncio.to_thread(deploy_zip, zip_path)
        commit = str(deployed["commit"])

        if not deployed["changed"]:
            ACTIVE_JOB_STATUS[job_key] = "ایجاد Commit برای شروع Build"
            await progress.edit_text(
                "ℹ️ فایل‌ها تغییری نداشتند؛ یک Commit خالی روی main می‌سازم تا Build شروع شود..."
            )
            commit_for_run = await dispatch_build()
        else:
            commit_for_run = commit

        ACTIVE_JOB_STATUS[job_key] = (
            f"منتظر Build مربوط به {commit_for_run[:8]}"
        )
        await progress.edit_text(
            "✅ فایل‌ها نصب شدند.\n"
            f"Commit: {commit_for_run[:8]}\n\n"
            "🛠 Build در پس‌زمینه در حال اجراست.\n"
            "ربات قفل نیست؛ دکمه «📊 وضعیت» را می‌توانی بزنید."
        )

        run = await wait_for_commit_run(commit_for_run, previous_ids)

        if run.get("conclusion") != "success":
            ACTIVE_JOB_STATUS[job_key] = "Build ناموفق"
            await progress.edit_text(
                "❌ Build ناموفق بود.\n"
                f"نتیجه: {run.get('conclusion')}\n"
                f"{run.get('html_url', '')}",
                reply_markup=keyboard(),
            )
            return

        ACTIVE_JOB_STATUS[job_key] = "دریافت و ارسال APK"
        await progress.edit_text(
            "✅ Build موفق شد.\n"
            "⬇️ در حال دریافت و ارسال APK..."
        )
        await send_apks(update, context, run)

        ACTIVE_JOB_STATUS[job_key] = "کامل شد"
        await progress.edit_text(
            "🎉 <b>عملیات کامل شد</b>\n\n"
            "APK جدید در پیام‌های بالا ارسال شد.",
            parse_mode=ParseMode.HTML,
            reply_markup=keyboard(),
        )
    except asyncio.CancelledError:
        ACTIVE_JOB_STATUS[job_key] = "عملیات لغو شد"
        raise
    except Exception as exc:
        logger.exception("Background deploy/build failed")
        ACTIVE_JOB_STATUS[job_key] = "خطا"
        try:
            await progress.edit_text(
                "❌ عملیات خودکار ناموفق بود.\n\n"
                f"<code>{redact(str(exc))[-3000:]}</code>",
                parse_mode=ParseMode.HTML,
                reply_markup=keyboard(),
            )
        except Exception:
            logger.exception("Could not update deploy progress message")
    finally:
        ACTIVE_CHAT_JOBS.discard(job_key)
        ACTIVE_JOB_STATUS.pop(job_key, None)
        shutil.rmtree(work_dir, ignore_errors=True)


async def receive_zip(
    update: Update,
    context: ContextTypes.DEFAULT_TYPE,
) -> None:
    if not is_admin(update.effective_user.id if update.effective_user else None):
        await deny(update)
        return

    document = update.effective_message.document
    if not document or not (document.file_name or "").lower().endswith(".zip"):
        return

    if document.file_size and document.file_size > MAX_ZIP_MB * 1024 * 1024:
        await update.effective_message.reply_text(
            f"❌ حداکثر حجم ZIP برابر {MAX_ZIP_MB} مگابایت است."
        )
        return

    job_key = int(update.effective_chat.id)
    if job_key in ACTIVE_CHAT_JOBS:
        await update.effective_message.reply_text(
            "⏳ یک نصب یا Build از قبل در حال اجراست.\n"
            "دکمه «📊 وضعیت» را بزن.",
            reply_markup=keyboard(),
        )
        return

    progress = await update.effective_message.reply_text(
        "📥 در حال دریافت ZIP..."
    )

    work_dir = Path(
        tempfile.mkdtemp(prefix="bluevpn-background-upload-")
    )
    zip_path = work_dir / "project.zip"

    try:
        telegram_file = await document.get_file()
        await telegram_file.download_to_drive(custom_path=str(zip_path))
    except Exception as exc:
        shutil.rmtree(work_dir, ignore_errors=True)
        await progress.edit_text(
            "❌ دریافت ZIP ناموفق بود.\n"
            f"{redact(str(exc))[-2000:]}",
            reply_markup=keyboard(),
        )
        return

    ACTIVE_CHAT_JOBS.add(job_key)
    ACTIVE_JOB_STATUS[job_key] = "شروع عملیات"

    context.application.create_task(
        process_zip_job(
            update=update,
            context=context,
            zip_path=zip_path,
            progress=progress,
            job_key=job_key,
            work_dir=work_dir,
        ),
        update=update,
        name=f"bluevpn-deploy-{job_key}",
    )

    await progress.edit_text(
        "✅ ZIP دریافت شد و عملیات در پس‌زمینه شروع شد.\n\n"
        "مرحله بررسی Secretها کاملاً حذف شده است.",
        reply_markup=keyboard(),
    )


async def process_rebuild_job(
    update: Update,
    context: ContextTypes.DEFAULT_TYPE,
    progress,
    job_key: int,
) -> None:
    try:
        ACTIVE_JOB_STATUS[job_key] = "خواندن Buildهای GitHub"
        previous_runs = await workflow_runs()
        previous_ids = {int(run["id"]) for run in previous_runs}

        ACTIVE_JOB_STATUS[job_key] = "شروع Build با Commit خودکار"
        commit_sha = await dispatch_build()

        await progress.edit_text(
            "🛠 Build با Commit و Push روی شاخه اصلی شروع شد.\n"
            f"Commit: {commit_sha[:8]}\n\n"
            "بررسی Secretها انجام نشد و ربات همچنان پاسخ‌گو است."
        )

        ACTIVE_JOB_STATUS[job_key] = (
            f"منتظر Build مربوط به {commit_sha[:8]}"
        )
        run = await wait_for_commit_run(commit_sha, previous_ids)

        if run.get("conclusion") != "success":
            ACTIVE_JOB_STATUS[job_key] = "Build ناموفق"
            await progress.edit_text(
                f"❌ Build ناموفق بود: {run.get('conclusion')}\n"
                f"{run.get('html_url', '')}",
                reply_markup=keyboard(),
            )
            return

        ACTIVE_JOB_STATUS[job_key] = "ارسال APK"
        await progress.edit_text(
            "✅ Build موفق شد؛ در حال ارسال APK..."
        )
        await send_apks(update, context, run)
        await progress.edit_text(
            "✅ APK با موفقیت ارسال شد.",
            reply_markup=keyboard(),
        )
    except asyncio.CancelledError:
        ACTIVE_JOB_STATUS[job_key] = "عملیات لغو شد"
        raise
    except Exception as exc:
        logger.exception("Background rebuild failed")
        ACTIVE_JOB_STATUS[job_key] = "خطا"
        try:
            await progress.edit_text(
                f"❌ {redact(str(exc))[-2800:]}",
                reply_markup=keyboard(),
            )
        except Exception:
            logger.exception("Could not update rebuild progress")
    finally:
        ACTIVE_CHAT_JOBS.discard(job_key)
        ACTIVE_JOB_STATUS.pop(job_key, None)


async def rebuild(
    update: Update,
    context: ContextTypes.DEFAULT_TYPE,
) -> None:
    if not is_admin(update.effective_user.id if update.effective_user else None):
        await deny(update)
        return

    job_key = int(update.effective_chat.id)
    if job_key in ACTIVE_CHAT_JOBS:
        await update.effective_message.reply_text(
            "⏳ یک عملیات از قبل در حال اجراست.\n"
            "دکمه «📊 وضعیت» را بزن.",
            reply_markup=keyboard(),
        )
        return

    progress = await update.effective_message.reply_text(
        "🛠 درخواست Build ثبت شد..."
    )

    ACTIVE_CHAT_JOBS.add(job_key)
    ACTIVE_JOB_STATUS[job_key] = "شروع Build"

    context.application.create_task(
        process_rebuild_job(
            update=update,
            context=context,
            progress=progress,
            job_key=job_key,
        ),
        update=update,
        name=f"bluevpn-rebuild-{job_key}",
    )


async def status(
    update: Update,
    context: ContextTypes.DEFAULT_TYPE,
) -> None:
    if not is_admin(update.effective_user.id if update.effective_user else None):
        await deny(update)
        return

    job_key = int(update.effective_chat.id)
    local_status = ACTIVE_JOB_STATUS.get(job_key, "بیکار")

    try:
        runs = await workflow_runs()
        if not runs:
            await update.effective_message.reply_text(
                "📊 وضعیت ربات\n\n"
                f"عملیات ربات: {local_status}\n"
                "هنوز Buildی در GitHub وجود ندارد.",
                reply_markup=keyboard(),
            )
            return

        run = runs[0]
        await update.effective_message.reply_text(
            "📊 وضعیت BlueVPN\n\n"
            f"عملیات ربات: {local_status}\n"
            f"وضعیت GitHub: {run.get('status')}\n"
            f"نتیجه: {run.get('conclusion') or 'در انتظار'}\n"
            f"شماره Build: #{run.get('run_number')}\n"
            f"{run.get('html_url', '')}",
            reply_markup=keyboard(),
        )
    except Exception as exc:
        await update.effective_message.reply_text(
            "📊 وضعیت ربات\n\n"
            f"عملیات ربات: {local_status}\n"
            "خواندن وضعیت GitHub ناموفق بود:\n"
            f"{redact(str(exc))[-1800:]}",
            reply_markup=keyboard(),
        )


async def latest(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if not is_admin(update.effective_user.id if update.effective_user else None):
        await deny(update)
        return
    progress = await update.effective_message.reply_text(
        "⬇️ در حال دریافت آخرین APK..."
    )
    try:
        runs = await workflow_runs()
        run = next(
            (
                item for item in runs
                if item.get("status") == "completed"
                and item.get("conclusion") == "success"
            ),
            None,
        )
        if not run:
            raise RuntimeError("Build موفقی پیدا نشد.")
        await send_apks(update, context, run)
        await progress.delete()
    except Exception as exc:
        await progress.edit_text(f"❌ {redact(str(exc))[-2200:]}")


async def signing_status(
    update: Update,
    context: ContextTypes.DEFAULT_TYPE,
) -> None:
    if not is_admin(update.effective_user.id if update.effective_user else None):
        await deny(update)
        return

    progress = await update.effective_message.reply_text(
        "🔐 بررسی اختیاری امضا؛ حداکثر ۱۵ ثانیه..."
    )

    try:
        result = await asyncio.wait_for(
            setup_and_send_backup(update, context),
            timeout=15.0,
        )

        if result.get("created"):
            await progress.edit_text(
                "✅ Secretهای ناقص ساخته شدند.\n"
                "این بررسی فقط با درخواست خودت انجام شد.",
                reply_markup=keyboard(),
            )
        else:
            await progress.edit_text(
                "✅ Secretهای امضا از قبل کامل هستند.",
                reply_markup=keyboard(),
            )
    except asyncio.TimeoutError:
        await progress.edit_text(
            "⚠️ API بخش Secrets در ۱۵ ثانیه پاسخ نداد.\n\n"
            "این موضوع دیگر نصب و Build را متوقف نمی‌کند؛ "
            "آپلود و ساخت بدون بررسی Secretها ادامه پیدا می‌کنند.",
            reply_markup=keyboard(),
        )
    except Exception as exc:
        await progress.edit_text(
            "⚠️ بررسی Secretها انجام نشد، اما ربات و Build قابل استفاده‌اند.\n\n"
            f"{redact(str(exc))[-1800:]}",
            reply_markup=keyboard(),
        )


def _manual_db():
    from .database import SessionLocal, initialize_database
    initialize_database()
    return SessionLocal()


async def _retry_guardcore_notifications_once() -> int:
    from .manual_guardcore import (
        backfill_recent_manual_requests,
        notify_manual_request,
        pending_manual_requests,
    )
    db=_manual_db()
    sent=0
    try:
        backfill_recent_manual_requests(db,hours=72,limit=100)
        rows=pending_manual_requests(db,100)
        for item in rows:
            request=item['request']
            if (
                request.get('state')=='awaiting_decision'
                and not request.get('notified_at')
            ):
                try:
                    if await notify_manual_request(db,item['order']):
                        sent+=1
                except Exception as exc:
                    logger.warning(
                        'GuardCore notification retry failed for %s: %s',
                        item['order'].id,
                        redact(str(exc)),
                    )
    finally:
        db.close()
    return sent


async def _guardcore_notification_loop(application: Application) -> None:
    while True:
        try:
            await _retry_guardcore_notifications_once()
        except Exception as exc:
            logger.warning(
                'GuardCore retry loop error: %s',
                redact(str(exc)),
            )
        await asyncio.sleep(60)


async def bot_post_init(application: Application) -> None:
    # Recover activations created while the bot was restarting and also
    # repair recent activated orders that missed the old plan-only trigger.
    await _retry_guardcore_notifications_once()
    application.create_task(
        _guardcore_notification_loop(application),
        name='guardcore-notification-retry',
    )


async def guardcore_queue(
    update: Update,
    context: ContextTypes.DEFAULT_TYPE,
) -> None:
    if not is_admin(update.effective_user.id if update.effective_user else None):
        await deny(update)
        return
    from .manual_guardcore import pending_manual_requests
    db=_manual_db()
    try:
        rows=pending_manual_requests(db,20)
        if not rows:
            await update.effective_message.reply_text(
                "✅ درخواست منتظر GuardCore وجود ندارد.",
                reply_markup=keyboard(),
            )
            return
        lines=[f"🟡 <b>صف GuardCore — {len(rows)} درخواست</b>"]
        buttons=[]
        for item in rows[:10]:
            order=item['order']; req=item['request']
            lines.append(
                "\n"
                f"• <code>{html.escape(str(req.get('username') or ''))}</code> — "
                f"{html.escape(str(req.get('plan_title') or ''))} — "
                f"{('منتظر تصمیم' if req.get('state')=='awaiting_decision' else 'منتظر لینک')}"
            )
            buttons.append([
                InlineKeyboardButton(
                    f"✅ {str(req.get('username') or '')[:22]}",
                    callback_data=f"gc:y:{order.id}",
                ),
                InlineKeyboardButton(
                    "⏭ رد",
                    callback_data=f"gc:n:{order.id}",
                ),
            ])
        await update.effective_message.reply_text(
            "".join(lines),
            parse_mode=ParseMode.HTML,
            reply_markup=InlineKeyboardMarkup(buttons),
        )
    finally:
        db.close()


async def guardcore_callback(
    update: Update,
    context: ContextTypes.DEFAULT_TYPE,
) -> None:
    query=update.callback_query
    if not query:
        return
    if not is_admin(update.effective_user.id if update.effective_user else None):
        await query.answer("دسترسی ندارید",show_alert=True)
        return
    data=query.data or ''
    match=re.fullmatch(r"gc:([yn]):([0-9a-fA-F-]{36})",data)
    if not match:
        await query.answer("درخواست نامعتبر است",show_alert=True)
        return
    use_guardcore=match.group(1)=='y'
    order_id=match.group(2)
    from .manual_guardcore import set_manual_decision
    db=_manual_db()
    try:
        order,request=set_manual_decision(
            db,
            order_id,
            use_guardcore=use_guardcore,
            admin_id=update.effective_user.id,
        )
        await query.answer("ثبت شد")
        try:
            await query.edit_message_reply_markup(reply_markup=None)
        except Exception:
            pass
        if not use_guardcore:
            context.user_data.pop('manual_guardcore_order_id',None)
            await query.message.reply_text(
                "⏭ GuardCore برای این سفارش رد شد. PasarGuard و Marzban طبق روال خودکار فعال مانده‌اند.",
                reply_markup=keyboard(),
            )
            return
        context.user_data['manual_guardcore_order_id']=order_id
        duration=(
            'نامحدود'
            if int(request.get('duration_days') or 0)==0
            else f"{int(request.get('duration_days') or 0)} روز"
        )
        volume=(
            'نامحدود'
            if int(request.get('data_limit_gb') or 0)==0
            else f"{int(request.get('data_limit_gb') or 0)} گیگ"
        )
        panel_url=str(request.get('panel_url') or '')
        panel_button=(
            InlineKeyboardMarkup([[InlineKeyboardButton('🌐 باز کردن پنل',url=panel_url)]])
            if panel_url.startswith(('http://','https://')) else None
        )
        await query.message.reply_text(
            "🛠 <b>کاربر را در پنل بساز</b>\n\n"
            f"نام کاربری: <code>{html.escape(str(request.get('username') or ''))}</code>\n"
            f"زمان: <b>{duration}</b>\n"
            f"حجم: <b>{volume}</b>\n"
            f"پلن: {html.escape(str(request.get('plan_title') or ''))}\n\n"
            "بعد از ساخت، فقط لینک Subscription را در پیام بعدی بفرست.",
            parse_mode=ParseMode.HTML,
            reply_markup=panel_button,
        )
    except Exception as exc:
        await query.answer("خطا",show_alert=True)
        await query.message.reply_text(f"❌ {str(exc)[:700]}")
    finally:
        db.close()


async def _capture_guardcore_link(
    update: Update,
    context: ContextTypes.DEFAULT_TYPE,
    text: str,
) -> bool:
    order_id=context.user_data.get('manual_guardcore_order_id')
    if not order_id or not text.startswith(('http://','https://')):
        return False
    from .manual_guardcore import attach_manual_subscription
    progress=await update.effective_message.reply_text(
        "🔎 در حال بررسی و ثبت لینک Subscription..."
    )
    db=_manual_db()
    try:
        result=await attach_manual_subscription(
            db,
            order_id,
            text,
            admin_id=update.effective_user.id,
        )
        context.user_data.pop('manual_guardcore_order_id',None)
        count=int(result.get('config_count') or 0)
        await progress.edit_text(
            "✅ <b>ساب GuardCore ثبت شد</b>\n\n"
            f"کاربر: <code>{html.escape(result['customer_email'])}</code>\n"
            f"نام پنل: <code>{html.escape(result['username'])}</code>\n"
            f"کانفیگ شناسایی‌شده: <b>{count if count else 'پاسخ معتبر'}</b>\n\n"
            "لینک به اشتراک تجمیعی کاربر اضافه شد و در اجرای بعدی/همگام‌سازی اپ دریافت می‌شود.",
            parse_mode=ParseMode.HTML,
            reply_markup=keyboard(),
        )
    except Exception as exc:
        await progress.edit_text(
            "❌ ثبت لینک ناموفق بود:\n"
            f"{str(exc)[:1000]}\n\n"
            "لینک صحیح را دوباره بفرست یا از پنل وب در بخش «صف GuardCore» ثبت کن.",
            reply_markup=keyboard(),
        )
    finally:
        db.close()
    return True


async def text_router(
    update: Update,
    context: ContextTypes.DEFAULT_TYPE,
) -> None:
    if not is_admin(update.effective_user.id if update.effective_user else None):
        await deny(update)
        return

    text = (update.effective_message.text or "").strip()
    if await _capture_guardcore_link(update,context,text):
        return
    if text == "📦 نصب و ساخت خودکار":
        await update.effective_message.reply_text(
            "ZIP پروژه یا آپدیت را همین‌جا بفرست.",
            reply_markup=keyboard(),
        )
    elif text == "🟡 صف GuardCore":
        await guardcore_queue(update,context)
    elif text == "🛠 ساخت دوباره":
        await rebuild(update, context)
    elif text == "📊 وضعیت":
        await status(update, context)
    elif text == "⬇️ دریافت آخرین APK":
        await latest(update, context)
    elif text == "🔐 بررسی امضا":
        await signing_status(update, context)
    else:
        await update.effective_message.reply_text(
            "ZIP را بفرست یا از دکمه‌های منو استفاده کن.",
            reply_markup=keyboard(),
        )


def build_application() -> Application:
    app = (
        Application.builder()
        .token(BOT_TOKEN)
        .request(_telegram_request(updates=False))
        .get_updates_request(_telegram_request(updates=True))
        .concurrent_updates(_env_int("TELEGRAM_CONCURRENT_UPDATES", 8))
        .post_init(bot_post_init)
        .build()
    )
    app.add_handler(CommandHandler("start", start))
    app.add_handler(CommandHandler("build", rebuild))
    app.add_handler(CommandHandler("status", status))
    app.add_handler(CommandHandler("latest", latest))
    app.add_handler(CommandHandler("signing", signing_status))
    app.add_handler(CommandHandler("guardcore", guardcore_queue))
    app.add_handler(
        CallbackQueryHandler(guardcore_callback,pattern=r"^gc:[yn]:")
    )
    app.add_handler(
        MessageHandler(filters.Document.FileExtension("zip"), receive_zip)
    )
    app.add_handler(MessageHandler(filters.TEXT & ~filters.COMMAND, text_router))
    return app
