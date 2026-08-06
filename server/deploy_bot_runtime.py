
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

DEPLOY_BOT_VERSION = "3.4-repository-dispatch-fallback"
BUILD_TRIGGER_MODE = "repository-dispatch-with-workflow-dispatch-fallback"


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
GITHUB_REPOSITORY_DISPATCH_EVENT = (
    os.getenv("GITHUB_REPOSITORY_DISPATCH_EVENT", "bluevpn_build").strip()
    or "bluevpn_build"
)
GIT_AUTHOR_NAME = os.getenv("GIT_AUTHOR_NAME", "BlueVPN Deploy Bot").strip()
GIT_AUTHOR_EMAIL = os.getenv(
    "GIT_AUTHOR_EMAIL",
    "bluevpn-deploy-bot@users.noreply.github.com",
).strip()
MAX_ZIP_MB = int(os.getenv("MAX_ZIP_MB", "50"))
MAX_EXTRACTED_MB = int(os.getenv("MAX_EXTRACTED_MB", "900"))
MAX_FILES = int(os.getenv("MAX_FILES", "25000"))
BUILD_TIMEOUT_SECONDS = int(os.getenv("BUILD_TIMEOUT_SECONDS", "5400"))
RUN_DISCOVERY_TIMEOUT_SECONDS = _env_int(
    "RUN_DISCOVERY_TIMEOUT_SECONDS",
    300,
    minimum=60,
)
PUSH_EVENT_GRACE_SECONDS = _env_int(
    "PUSH_EVENT_GRACE_SECONDS",
    12,
    minimum=2,
)
REPOSITORY_DISPATCH_GRACE_SECONDS = _env_int(
    "REPOSITORY_DISPATCH_GRACE_SECONDS",
    30,
    minimum=5,
)
RUNNER_QUEUE_TIMEOUT_SECONDS = _env_int(
    "RUNNER_QUEUE_TIMEOUT_SECONDS",
    900,
    minimum=120,
)
JOB_STALE_TIMEOUT_SECONDS = _env_int(
    "JOB_STALE_TIMEOUT_SECONDS",
    1800,
    minimum=300,
)
JOB_CRITICAL_GRACE_SECONDS = _env_int(
    "JOB_CRITICAL_GRACE_SECONDS",
    900,
    minimum=120,
)
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
    ".env",
    "BlueVPN-release.jks",
}
PROTECTED_SUFFIXES = {".jks", ".keystore", ".p12", ".pfx"}
DELETE_MANIFEST = ".bluevpn-delete"

ACTIVE_CHAT_JOBS: set[int] = set()
ACTIVE_JOB_STATUS: dict[int, str] = {}
ACTIVE_JOB_TASKS: dict[int, asyncio.Task[Any]] = {}
ACTIVE_JOB_TOKENS: dict[int, str] = {}
ACTIVE_JOB_STARTED_AT: dict[int, float] = {}
ACTIVE_JOB_UPDATED_AT: dict[int, float] = {}
ACTIVE_JOB_COMMITS: dict[int, str] = {}
ACTIVE_JOB_RUN_IDS: dict[int, int] = {}


def _register_job(job_key: int, status: str) -> str:
    token = secrets.token_hex(12)
    now = time.monotonic()
    ACTIVE_CHAT_JOBS.add(job_key)
    ACTIVE_JOB_TOKENS[job_key] = token
    ACTIVE_JOB_STATUS[job_key] = status
    ACTIVE_JOB_STARTED_AT[job_key] = now
    ACTIVE_JOB_UPDATED_AT[job_key] = now
    ACTIVE_JOB_COMMITS.pop(job_key, None)
    ACTIVE_JOB_RUN_IDS.pop(job_key, None)
    return token


def _set_job_status(
    job_key: int,
    token: str,
    status: str,
    *,
    commit: str | None = None,
    run_id: int | None = None,
) -> bool:
    if ACTIVE_JOB_TOKENS.get(job_key) != token:
        return False
    ACTIVE_JOB_STATUS[job_key] = status
    ACTIVE_JOB_UPDATED_AT[job_key] = time.monotonic()
    if commit:
        ACTIVE_JOB_COMMITS[job_key] = commit
    if run_id:
        ACTIVE_JOB_RUN_IDS[job_key] = int(run_id)
    return True


def _clear_job(job_key: int, token: str | None = None) -> bool:
    current = ACTIVE_JOB_TOKENS.get(job_key)
    if token is not None and current != token:
        return False
    ACTIVE_CHAT_JOBS.discard(job_key)
    ACTIVE_JOB_STATUS.pop(job_key, None)
    ACTIVE_JOB_TASKS.pop(job_key, None)
    ACTIVE_JOB_TOKENS.pop(job_key, None)
    ACTIVE_JOB_STARTED_AT.pop(job_key, None)
    ACTIVE_JOB_UPDATED_AT.pop(job_key, None)
    ACTIVE_JOB_COMMITS.pop(job_key, None)
    ACTIVE_JOB_RUN_IDS.pop(job_key, None)
    return True


def _reconcile_finished_job(job_key: int) -> None:
    task = ACTIVE_JOB_TASKS.get(job_key)
    if job_key in ACTIVE_CHAT_JOBS and task is not None and task.done():
        _clear_job(job_key, ACTIVE_JOB_TOKENS.get(job_key))


def _job_age_seconds(job_key: int) -> int:
    started = ACTIVE_JOB_STARTED_AT.get(job_key)
    if started is None:
        return 0
    return max(0, int(time.monotonic() - started))


def _job_is_waiting(status: str) -> bool:
    normalized = status.strip().lower()
    return any(
        marker in normalized
        for marker in (
            "منتظر build",
            "در صف github",
            "منتظر runner",
            "queued",
            "waiting",
        )
    )


def _job_is_critical(status: str) -> bool:
    normalized = status.strip().lower()
    return any(
        marker in normalized
        for marker in (
            "نصب فایل‌ها روی github",
            "ایجاد commit",
            "شروع build با commit",
        )
    )


async def _cancel_github_run(run_id: int | None) -> bool:
    if not run_id:
        return False
    response = await gh_request(
        "POST",
        f"/repos/{OWNER}/{REPO}/actions/runs/{int(run_id)}/cancel",
    )
    return response.status_code in {202, 409}


async def _cancel_active_job(
    job_key: int,
    *,
    reason: str,
    force: bool = False,
) -> tuple[bool, str]:
    _reconcile_finished_job(job_key)
    if job_key not in ACTIVE_CHAT_JOBS:
        return False, "عملیات فعالی وجود ندارد."

    status = ACTIVE_JOB_STATUS.get(job_key, "نامشخص")
    age = _job_age_seconds(job_key)
    if _job_is_critical(status) and age < JOB_CRITICAL_GRACE_SECONDS and not force:
        return False, (
            "عملیات اکنون در مرحله ثبت فایل‌ها روی GitHub است و آزادسازی فوری "
            "می‌تواند دو Push هم‌زمان بسازد. چند دقیقه بعد دوباره امتحان کن."
        )

    token = ACTIVE_JOB_TOKENS.get(job_key)
    run_id = ACTIVE_JOB_RUN_IDS.get(job_key)
    task = ACTIVE_JOB_TASKS.get(job_key)
    if task is not None and not task.done():
        task.cancel()
    try:
        await _cancel_github_run(run_id)
    except Exception as exc:
        logger.warning("Could not cancel GitHub run %s: %s", run_id, redact(str(exc)))
    _clear_job(job_key, token)
    logger.info("Released deploy lock for chat %s: %s", job_key, reason)
    return True, "قفل عملیات آزاد شد و Build قبلی در صورت امکان لغو شد."


def keyboard() -> ReplyKeyboardMarkup:
    return ReplyKeyboardMarkup(
        [
            ["📦 نصب و ساخت خودکار"],
            ["🟡 صف GuardCore", "📊 وضعیت"],
            ["🚀 ساخت فوری", "⬇️ دریافت آخرین APK"],
            ["🔓 آزادسازی عملیات", "🔐 بررسی امضا"],
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


def _commit_url(commit_sha: str) -> str:
    return (
        f"https://github.com/{GITHUB_REPOSITORY}/commit/{commit_sha}"
    )


def _remote_branch_sha(repo: Path) -> str:
    result = subprocess.run(
        [
            "git",
            "ls-remote",
            "origin",
            f"refs/heads/{GIT_BRANCH}",
        ],
        cwd=repo,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        timeout=120,
    )
    if result.returncode != 0:
        raise RuntimeError(
            "خواندن SHA شاخه از GitHub ناموفق بود:\n"
            + redact((result.stderr or result.stdout)[-2000:])
        )
    line = result.stdout.strip().splitlines()
    return line[0].split()[0] if line else ""


def _push_and_verify(
    repo: Path,
    git,
    *,
    attempts: int = 3,
) -> tuple[str, str]:
    """Push HEAD and prove that GitHub's branch points at the same commit."""
    last_error = ""
    for attempt in range(1, attempts + 1):
        expected = git("rev-parse", "HEAD").stdout.strip()
        pushed = git(
            "push",
            "--porcelain",
            "origin",
            f"HEAD:{GIT_BRANCH}",
            check=False,
        )
        if pushed.returncode != 0:
            last_error = redact((pushed.stderr or pushed.stdout)[-3000:])
            combined = (pushed.stderr or "") + "\n" + (pushed.stdout or "")
            race = any(
                marker in combined.lower()
                for marker in (
                    "non-fast-forward",
                    "fetch first",
                    "failed to push some refs",
                    "stale info",
                )
            )
            if not race or attempt >= attempts:
                raise RuntimeError(
                    "Push فایل‌ها به GitHub ناموفق بود. هیچ APKی ارسال نمی‌شود "
                    "تا سورس واقعاً ثبت شود.\n"
                    + last_error
                )

            git("fetch", "--prune", "origin", GIT_BRANCH)
            rebased = git(
                "rebase",
                f"origin/{GIT_BRANCH}",
                check=False,
            )
            if rebased.returncode != 0:
                git("rebase", "--abort", check=False)
                raise RuntimeError(
                    "هم‌زمان یک تغییر دیگر روی GitHub ثبت شد و Rebase خودکار "
                    "به تعارض خورد. ZIP را دوباره ارسال کن.\n"
                    + redact((rebased.stderr or rebased.stdout)[-2500:])
                )
            continue

        # GitHub's ref is authoritative. A successful local push is not enough.
        remote_sha = ""
        for _ in range(8):
            remote_sha = _remote_branch_sha(repo)
            if remote_sha == expected:
                return expected, remote_sha
            time.sleep(1.5)

        last_error = (
            f"SHA محلی {expected} است ولی GitHub شاخه {GIT_BRANCH} را "
            f"روی {remote_sha or 'نامشخص'} نشان می‌دهد."
        )
        if attempt < attempts:
            git("fetch", "--prune", "origin", GIT_BRANCH)
            continue

    raise RuntimeError(
        "تأیید ثبت فایل‌ها روی GitHub ناموفق بود. APK ساخته یا ارسال نشد.\n"
        + last_error
    )


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
                "50",
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
            remote_commit = _remote_branch_sha(repo)
            if remote_commit != commit:
                raise RuntimeError(
                    "مخزن محلی با GitHub همگام نیست؛ برای جلوگیری از Build روی "
                    "سورس اشتباه، عملیات متوقف شد."
                )
            return {
                "changed": False,
                "commit": commit,
                "remote_commit": remote_commit,
                "verified": True,
                "commit_url": _commit_url(commit),
                "repository": GITHUB_REPOSITORY,
                "branch": GIT_BRANCH,
                "changed_files": [],
                "copied": copied,
                "deleted": deleted,
                "skipped": skipped,
            }

        changed_files = [
            line.strip()
            for line in git(
                "diff",
                "--cached",
                "--name-only",
            ).stdout.splitlines()
            if line.strip()
        ]
        git(
            "commit",
            "-m",
            "deploy: persist BlueVPN project files from Telegram",
        )
        commit, remote_commit = _push_and_verify(repo, git)
        return {
            "changed": True,
            "commit": commit,
            "remote_commit": remote_commit,
            "verified": commit == remote_commit,
            "commit_url": _commit_url(commit),
            "repository": GITHUB_REPOSITORY,
            "branch": GIT_BRANCH,
            "changed_files": changed_files,
            "copied": copied,
            "deleted": deleted,
            "skipped": skipped,
        }


async def workflow_info() -> dict[str, Any]:
    response = await gh_request(
        "GET",
        f"/repos/{OWNER}/{REPO}/actions/workflows/{GITHUB_WORKFLOW}",
    )
    if response.status_code >= 400:
        raise RuntimeError(
            "خواندن تنظیمات Workflow ناموفق بود: "
            f"HTTP {response.status_code} {response.text[-900:]}"
        )
    return response.json()


async def ensure_workflow_enabled() -> dict[str, Any]:
    info = await workflow_info()
    if str(info.get("state") or "").lower() == "active":
        return info

    response = await gh_request(
        "PUT",
        f"/repos/{OWNER}/{REPO}/actions/workflows/{GITHUB_WORKFLOW}/enable",
    )
    if response.status_code not in {204, 200}:
        raise RuntimeError(
            "Workflow غیرفعال است و فعال‌سازی خودکار آن ناموفق بود. "
            "توکن GitHub باید مجوز Actions: write داشته باشد. "
            f"HTTP {response.status_code}: {response.text[-900:]}"
        )
    await asyncio.sleep(1)
    return await workflow_info()


async def branch_head_sha() -> str:
    response = await gh_request(
        "GET",
        f"/repos/{OWNER}/{REPO}/git/ref/heads/{quote(GIT_BRANCH, safe='')}",
    )
    if response.status_code >= 400:
        raise RuntimeError(
            "خواندن آخرین Commit شاخه ناموفق بود: "
            f"HTTP {response.status_code} {response.text[-900:]}"
        )
    sha = str((response.json().get("object") or {}).get("sha") or "").strip()
    if not sha:
        raise RuntimeError("GitHub برای شاخه مقصد SHA معتبری برنگرداند.")
    return sha


async def dispatch_repository_event(commit_sha: str) -> dict[str, Any]:
    """Trigger Actions using the repository dispatch endpoint.

    This endpoint uses the same Contents: write permission already required by
    the deploy bot for pushing project files. It therefore works even when the
    token does not have the separate Actions: write permission needed by
    workflow_dispatch.
    """
    request_id = secrets.token_hex(12)
    response = await gh_request(
        "POST",
        f"/repos/{OWNER}/{REPO}/dispatches",
        json_body={
            "event_type": GITHUB_REPOSITORY_DISPATCH_EVENT,
            "client_payload": {
                "target_sha": commit_sha,
                "ref": GIT_BRANCH,
                "request_id": request_id,
                "source": "bluevpn-deploy-bot",
            },
        },
    )
    if response.status_code not in {200, 204}:
        raise RuntimeError(
            "repository_dispatch ناموفق بود؛ توکن GitHub باید برای مخزن "
            "مجوز Contents: write داشته باشد. "
            f"HTTP {response.status_code}: {response.text[-1200:]}"
        )
    return {
        "trigger": "repository_dispatch",
        "request_id": request_id,
        "target_sha": commit_sha,
    }


async def dispatch_workflow() -> dict[str, Any]:
    """Fallback trigger for tokens that also have Actions: write."""
    info = await ensure_workflow_enabled()
    response = await gh_request(
        "POST",
        f"/repos/{OWNER}/{REPO}/actions/workflows/{GITHUB_WORKFLOW}/dispatches",
        json_body={"ref": GIT_BRANCH},
    )
    if response.status_code not in {200, 204}:
        permission_hint = (
            " توکن GitHub برای این روش باید مجوز Actions: write داشته باشد."
            if response.status_code in {401, 403, 404}
            else ""
        )
        raise RuntimeError(
            "workflow_dispatch ناموفق بود."
            + permission_hint
            + f" HTTP {response.status_code}: {response.text[-1200:]}"
        )

    payload: dict[str, Any] = {}
    if response.content:
        try:
            payload = response.json()
        except ValueError:
            payload = {}
    return {
        "workflow_id": info.get("id"),
        "workflow_state": info.get("state"),
        "run_id": payload.get("workflow_run_id") or payload.get("id"),
        "html_url": payload.get("html_url") or "",
        "trigger": "workflow_dispatch",
    }


def _matching_commit_runs(
    runs: list[dict[str, Any]],
    commit_sha: str,
    previous_ids: set[int],
) -> list[dict[str, Any]]:
    matches = [
        run
        for run in runs
        if str(run.get("head_sha") or "") == commit_sha
        and int(run.get("id") or 0) not in previous_ids
    ]
    return sorted(
        matches,
        key=lambda run: (str(run.get("created_at") or ""), int(run.get("id") or 0)),
        reverse=True,
    )


async def ensure_commit_workflow_run(
    commit_sha: str,
    previous_ids: set[int],
    *,
    allow_push_grace: bool,
) -> dict[str, Any]:
    """Ensure one run exists for commit, using REST dispatch as fallback."""
    current_head = await branch_head_sha()
    if current_head != commit_sha:
        raise RuntimeError(
            "قبل از شروع Build، شاخه GitHub تغییر کرد. "
            f"Commit مورد انتظار {commit_sha[:8]} و Commit فعلی {current_head[:8]} است. "
            "برای جلوگیری از ساخت سورس اشتباه، دوباره تلاش کن."
        )

    if allow_push_grace:
        deadline = time.monotonic() + PUSH_EVENT_GRACE_SECONDS
        while time.monotonic() < deadline:
            matches = _matching_commit_runs(
                await workflow_runs(),
                commit_sha,
                previous_ids,
            )
            if matches:
                return {"trigger": "push", "run": matches[0]}
            await asyncio.sleep(2)

    repository_error = ""
    try:
        repository_result = await dispatch_repository_event(commit_sha)
        deadline = time.monotonic() + REPOSITORY_DISPATCH_GRACE_SECONDS
        while time.monotonic() < deadline:
            matches = _matching_commit_runs(
                await workflow_runs(),
                commit_sha,
                previous_ids,
            )
            if matches:
                repository_result["run"] = matches[0]
                return repository_result
            await asyncio.sleep(3)
        repository_error = (
            "درخواست repository_dispatch پذیرفته شد اما GitHub در مهلت کوتاه "
            "هیچ Run جدیدی نساخت."
        )
        logger.warning(repository_error)
    except Exception as exc:
        repository_error = redact(str(exc))[-1200:]
        logger.warning("repository_dispatch failed, trying workflow_dispatch: %s", repository_error)

    try:
        result = await dispatch_workflow()
        result["fallback_from"] = "repository_dispatch"
        result["repository_dispatch_error"] = repository_error
        return result
    except Exception as exc:
        workflow_error = redact(str(exc))[-1200:]
        raise RuntimeError(
            "هیچ‌یک از دو روش ساخت GitHub اجرا نشد.\n"
            f"repository_dispatch: {repository_error}\n"
            f"workflow_dispatch: {workflow_error}"
        ) from exc


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


async def wait_for_commit_run(
    commit_sha: str | None,
    previous_ids: set[int],
    *,
    job_key: int | None = None,
    job_token: str | None = None,
) -> dict[str, Any]:
    deadline = time.monotonic() + BUILD_TIMEOUT_SECONDS
    discovery_deadline = time.monotonic() + RUN_DISCOVERY_TIMEOUT_SECONDS
    queued_since: float | None = None
    queued_run_id: int | None = None

    while time.monotonic() < deadline:
        runs = await workflow_runs()
        if commit_sha:
            matches = _matching_commit_runs(runs, commit_sha, previous_ids)
        else:
            matches = sorted(
                [
                    run
                    for run in runs
                    if int(run.get("id") or 0) not in previous_ids
                ],
                key=lambda run: (
                    str(run.get("created_at") or ""),
                    int(run.get("id") or 0),
                ),
                reverse=True,
            )

        if matches:
            successful = next(
                (
                    run
                    for run in matches
                    if run.get("status") == "completed"
                    and run.get("conclusion") == "success"
                ),
                None,
            )
            if successful is not None:
                return successful

            active = next(
                (run for run in matches if run.get("status") != "completed"),
                None,
            )
            selected = active or matches[0]
            run_id = int(selected.get("id") or 0)
            run_number = selected.get("run_number") or "?"
            run_status = str(selected.get("status") or "queued")

            if job_key is not None and job_token is not None:
                if run_status == "in_progress":
                    label = f"Build #{run_number} در حال اجرا"
                elif run_status == "completed":
                    label = f"Build #{run_number} کامل شد"
                else:
                    label = f"Build #{run_number} در صف GitHub؛ منتظر Runner"
                _set_job_status(
                    job_key,
                    job_token,
                    label,
                    commit=commit_sha,
                    run_id=run_id or None,
                )

            if run_status == "completed":
                # All newly created runs for this commit are terminal and none
                # succeeded. Returning the newest one exposes the real result.
                return selected

            if run_status in {"queued", "waiting", "pending", "requested"}:
                if queued_run_id != run_id:
                    queued_run_id = run_id
                    queued_since = time.monotonic()
                if (
                    queued_since is not None
                    and time.monotonic() - queued_since
                    >= RUNNER_QUEUE_TIMEOUT_SECONDS
                ):
                    try:
                        await _cancel_github_run(run_id)
                    except Exception:
                        logger.exception("Could not cancel stale queued GitHub run")
                    raise RuntimeError(
                        "GitHub در مهلت تعیین‌شده هیچ Runnerی به Build اختصاص نداد. "
                        "قفل ربات خودکار آزاد شد؛ بعداً دوباره Build را اجرا کن."
                    )
            else:
                queued_since = None
                queued_run_id = None

            await asyncio.sleep(10)
            continue

        if time.monotonic() >= discovery_deadline:
            raise RuntimeError(
                "درخواست Build به GitHub ارسال شد اما هیچ اجرای جدیدی برای "
                f"Commit {str(commit_sha or '')[:8]} ساخته نشد. "
                "Workflow باید repository_dispatch را پشتیبانی کند؛ ربات هر دو روش "
                "repository_dispatch و workflow_dispatch را امتحان کرده است."
            )
        if job_key is not None and job_token is not None:
            _set_job_status(
                job_key,
                job_token,
                "درخواست ارسال شد؛ منتظر ایجاد Build جدید",
                commit=commit_sha,
            )
        await asyncio.sleep(5)

    raise RuntimeError("زمان انتظار برای پایان Build تمام شد و قفل ربات آزاد شد.")


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
        "✅ <b>ربات خودکار BlueVPN آماده است</b>\n"
        f"نسخه ربات: {DEPLOY_BOT_VERSION}\n"
        f"روش Build: {BUILD_TRIGGER_MODE}\n"
        f"مخزن مقصد: {GITHUB_REPOSITORY}\n"
        f"شاخه مقصد: {GIT_BRANCH}\n\n"
        "ZIP پروژه یا آپدیت را بفرست.\n"
        "ربات ابتدا فایل‌ها را Commit و Push می‌کند، SHA واقعی شاخه "
        "GitHub را تأیید می‌کند و سپس Workflow را مستقیماً از API رسمی "
        "GitHub Actions اجرا می‌کند؛ بنابراین به رخداد Push وابسته نیست.",
        parse_mode=ParseMode.HTML,
        reply_markup=keyboard(),
    )


async def process_zip_job(
    update: Update,
    context: ContextTypes.DEFAULT_TYPE,
    zip_path: Path,
    progress,
    job_key: int,
    job_token: str,
    work_dir: Path,
) -> None:
    try:
        _set_job_status(job_key, job_token, "خواندن آخرین Buildهای GitHub")
        previous_runs = await workflow_runs()
        previous_ids = {int(run["id"]) for run in previous_runs}

        _set_job_status(job_key, job_token, "نصب فایل‌ها روی GitHub")
        await progress.edit_text(
            "📤 در حال نصب پروژه روی GitHub...\n\n"
            "بررسی Secretها رد شد؛ عملیات مستقیم ادامه دارد."
        )

        deployed = await asyncio.to_thread(deploy_zip, zip_path)
        commit = str(deployed["commit"])

        commit_for_run = commit
        commit_url = str(deployed["commit_url"])
        changed_count = len(deployed.get("changed_files") or [])

        _set_job_status(
            job_key,
            job_token,
            "درخواست مستقیم Build از GitHub Actions",
            commit=commit_for_run,
        )
        trigger = await ensure_commit_workflow_run(
            commit_for_run,
            previous_ids,
            allow_push_grace=bool(deployed["changed"]),
        )
        trigger_mode = str(trigger.get("trigger") or "workflow_dispatch")

        _set_job_status(
            job_key,
            job_token,
            f"GitHub تأیید شد؛ منتظر Build {commit_for_run[:8]}",
            commit=commit_for_run,
        )
        await progress.edit_text(
            "✅ فایل‌ها واقعاً روی GitHub ثبت و SHA شاخه تأیید شد.\n"
            f"مخزن: {GITHUB_REPOSITORY}\n"
            f"شاخه: {GIT_BRANCH}\n"
            f"فایل‌های تغییرکرده: {changed_count}\n"
            f"Commit: {commit_for_run[:8]}\n"
            f"{commit_url}\n\n"
            "🛠 Build فقط از همین Commit اجرا می‌شود.\n"
            f"روش شروع: {trigger_mode}\n"
            "در صورت گیرکردن Runner، دکمه «🔓 آزادسازی عملیات» فعال است."
        )

        run = await wait_for_commit_run(
            commit_for_run,
            previous_ids,
            job_key=job_key,
            job_token=job_token,
        )

        if run.get("conclusion") != "success":
            _set_job_status(job_key, job_token, "Build ناموفق")
            await progress.edit_text(
                "❌ Build ناموفق بود.\n"
                f"نتیجه: {run.get('conclusion')}\n"
                f"{run.get('html_url', '')}",
                reply_markup=keyboard(),
            )
            return

        _set_job_status(job_key, job_token, "دریافت و ارسال APK")
        await progress.edit_text(
            "✅ Build موفق شد.\n"
            "⬇️ در حال دریافت و ارسال APK..."
        )
        await send_apks(update, context, run)

        _set_job_status(job_key, job_token, "کامل شد")
        await progress.edit_text(
            "🎉 <b>عملیات کامل شد</b>\n\n"
            "APK جدید در پیام‌های بالا ارسال شد.",
            parse_mode=ParseMode.HTML,
            reply_markup=keyboard(),
        )
    except asyncio.CancelledError:
        _set_job_status(job_key, job_token, "عملیات لغو شد")
        try:
            await progress.edit_text(
                "🛑 عملیات قبلی لغو شد و قفل ربات آزاد است.",
                reply_markup=keyboard(),
            )
        except Exception:
            pass
        raise
    except Exception as exc:
        logger.exception("Background deploy/build failed")
        _set_job_status(job_key, job_token, "خطا")
        try:
            await progress.edit_text(
                "❌ عملیات خودکار ناموفق بود.\n\n"
                f"<code>{redact(str(exc))[-3000:]}</code>\n\n"
                "✅ قفل ربات آزاد شد و می‌توانی ZIP را دوباره بفرستی.",
                parse_mode=ParseMode.HTML,
                reply_markup=keyboard(),
            )
        except Exception:
            logger.exception("Could not update deploy progress message")
    finally:
        _clear_job(job_key, job_token)
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
    _reconcile_finished_job(job_key)
    if job_key in ACTIVE_CHAT_JOBS:
        old_status = ACTIVE_JOB_STATUS.get(job_key, "نامشخص")
        old_age = _job_age_seconds(job_key)
        if _job_is_waiting(old_status) or old_age >= JOB_STALE_TIMEOUT_SECONDS:
            released, message = await _cancel_active_job(
                job_key,
                reason="جایگزینی با ZIP جدید",
                force=old_age >= JOB_STALE_TIMEOUT_SECONDS,
            )
            if released:
                await update.effective_message.reply_text(
                    "♻️ عملیات قبلی که در انتظار Build مانده بود متوقف شد.\n"
                    "ZIP جدید جای آن نصب می‌شود.",
                    reply_markup=keyboard(),
                )
            else:
                await update.effective_message.reply_text(
                    f"⏳ {message}",
                    reply_markup=keyboard(),
                )
                return
        else:
            await update.effective_message.reply_text(
                "⏳ یک نصب یا Build از قبل در حال اجراست.\n"
                f"مرحله: {old_status}\n"
                f"مدت: {old_age // 60} دقیقه\n\n"
                "برای توقف امن، دکمه «🔓 آزادسازی عملیات» را بزن.",
                reply_markup=keyboard(),
            )
            return

    progress = await update.effective_message.reply_text(
        "📥 در حال دریافت ZIP..."
    )

    work_dir = Path(tempfile.mkdtemp(prefix="bluevpn-background-upload-"))
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

    job_token = _register_job(job_key, "شروع عملیات")
    task = context.application.create_task(
        process_zip_job(
            update=update,
            context=context,
            zip_path=zip_path,
            progress=progress,
            job_key=job_key,
            job_token=job_token,
            work_dir=work_dir,
        ),
        update=update,
        name=f"bluevpn-deploy-{job_key}-{job_token[:6]}",
    )
    ACTIVE_JOB_TASKS[job_key] = task

    await progress.edit_text(
        "✅ ZIP دریافت شد و عملیات در پس‌زمینه شروع شد.\n\n"
        "اگر GitHub Runner گیر کند، قفل بعد از مهلت خودکار آزاد می‌شود.",
        reply_markup=keyboard(),
    )


async def process_rebuild_job(
    update: Update,
    context: ContextTypes.DEFAULT_TYPE,
    progress,
    job_key: int,
    job_token: str,
) -> None:
    try:
        _set_job_status(job_key, job_token, "خواندن Buildهای GitHub")
        previous_runs = await workflow_runs()
        previous_ids = {int(run["id"]) for run in previous_runs}

        _set_job_status(job_key, job_token, "ارسال درخواست مستقل Build")
        commit_sha = await branch_head_sha()
        trigger = await ensure_commit_workflow_run(
            commit_sha,
            previous_ids,
            allow_push_grace=False,
        )

        await progress.edit_text(
            "🛠 درخواست Build به GitHub ارسال شد.\n"
            f"مخزن: {GITHUB_REPOSITORY}\n"
            f"شاخه: {GIT_BRANCH}\n"
            f"Commit: {commit_sha[:8]}\n"
            f"روش شروع: {trigger.get('trigger', 'repository_dispatch')}\n\n"
            "در صورت گیرکردن Runner، قفل خودکار آزاد خواهد شد."
        )

        _set_job_status(
            job_key,
            job_token,
            f"منتظر Build مربوط به {commit_sha[:8]}",
            commit=commit_sha,
        )
        run = await wait_for_commit_run(
            commit_sha,
            previous_ids,
            job_key=job_key,
            job_token=job_token,
        )

        if run.get("conclusion") != "success":
            _set_job_status(job_key, job_token, "Build ناموفق")
            await progress.edit_text(
                f"❌ Build ناموفق بود: {run.get('conclusion')}\n"
                f"{run.get('html_url', '')}",
                reply_markup=keyboard(),
            )
            return

        _set_job_status(job_key, job_token, "ارسال APK")
        await progress.edit_text("✅ Build موفق شد؛ در حال ارسال APK...")
        await send_apks(update, context, run)
        await progress.edit_text(
            "✅ APK با موفقیت ارسال شد.",
            reply_markup=keyboard(),
        )
    except asyncio.CancelledError:
        _set_job_status(job_key, job_token, "عملیات لغو شد")
        raise
    except Exception as exc:
        logger.exception("Background rebuild failed")
        _set_job_status(job_key, job_token, "خطا")
        try:
            await progress.edit_text(
                f"❌ {redact(str(exc))[-2800:]}\n\n"
                "✅ قفل ربات آزاد شد.",
                reply_markup=keyboard(),
            )
        except Exception:
            logger.exception("Could not update rebuild progress")
    finally:
        _clear_job(job_key, job_token)


async def rebuild(
    update: Update,
    context: ContextTypes.DEFAULT_TYPE,
) -> None:
    if not is_admin(update.effective_user.id if update.effective_user else None):
        await deny(update)
        return

    job_key = int(update.effective_chat.id)
    _reconcile_finished_job(job_key)
    if job_key in ACTIVE_CHAT_JOBS:
        status_text = ACTIVE_JOB_STATUS.get(job_key, "نامشخص")
        age = _job_age_seconds(job_key)
        await update.effective_message.reply_text(
            "⏳ یک عملیات از قبل در حال اجراست.\n"
            f"مرحله: {status_text}\n"
            f"مدت: {age // 60} دقیقه\n\n"
            "دکمه «🔓 آزادسازی عملیات» را بزن.",
            reply_markup=keyboard(),
        )
        return

    progress = await update.effective_message.reply_text(
        "🛠 درخواست Build ثبت شد..."
    )

    job_token = _register_job(job_key, "شروع Build")
    task = context.application.create_task(
        process_rebuild_job(
            update=update,
            context=context,
            progress=progress,
            job_key=job_key,
            job_token=job_token,
        ),
        update=update,
        name=f"bluevpn-rebuild-{job_key}-{job_token[:6]}",
    )
    ACTIVE_JOB_TASKS[job_key] = task


async def unlock(
    update: Update,
    context: ContextTypes.DEFAULT_TYPE,
) -> None:
    if not is_admin(update.effective_user.id if update.effective_user else None):
        await deny(update)
        return
    job_key = int(update.effective_chat.id)
    released, message = await _cancel_active_job(
        job_key,
        reason="آزادسازی دستی توسط مدیر",
    )
    prefix = "✅" if released else "ℹ️"
    await update.effective_message.reply_text(
        f"{prefix} {message}",
        reply_markup=keyboard(),
    )


async def status(
    update: Update,
    context: ContextTypes.DEFAULT_TYPE,
) -> None:
    if not is_admin(update.effective_user.id if update.effective_user else None):
        await deny(update)
        return

    job_key = int(update.effective_chat.id)
    _reconcile_finished_job(job_key)
    local_status = ACTIVE_JOB_STATUS.get(job_key, "بیکار")
    age = _job_age_seconds(job_key)
    local_line = (
        f"عملیات ربات: {local_status}"
        + (f"\nمدت عملیات: {age // 60} دقیقه" if job_key in ACTIVE_CHAT_JOBS else "")
    )

    try:
        info, head, runs = await asyncio.gather(
            workflow_info(),
            branch_head_sha(),
            workflow_runs(),
        )
        workflow_state = str(info.get("state") or "نامشخص")
        if not runs:
            await update.effective_message.reply_text(
                "📊 وضعیت BlueVPN\n\n"
                f"{local_line}\n"
                f"Workflow: {workflow_state}\n"
                f"آخرین Commit شاخه: {head[:8]}\n"
                "هیچ Buildی ثبت نشده است.\n\n"
                "برای ساخت همین Commit دکمه «🚀 ساخت فوری» را بزن.",
                reply_markup=keyboard(),
            )
            return

        run = runs[0]
        run_sha = str(run.get("head_sha") or "")
        source_ahead = bool(head and run_sha != head)
        source_line = (
            "⚠️ سورس GitHub از آخرین Build جدیدتر است و هنوز برای Commit فعلی "
            "Build ساخته نشده.\n"
            if source_ahead
            else "✅ آخرین Build با Commit فعلی شاخه هماهنگ است.\n"
        )
        action_line = (
            "برای ساخت Commit فعلی: 🚀 ساخت فوری"
            if source_ahead and job_key not in ACTIVE_CHAT_JOBS
            else "برای توقف Build گیرکرده: 🔓 آزادسازی عملیات"
        )
        await update.effective_message.reply_text(
            "📊 وضعیت BlueVPN\n\n"
            f"{local_line}\n"
            f"Workflow: {workflow_state}\n"
            f"Commit فعلی شاخه: {head[:8]}\n"
            f"Commit آخرین Build: {run_sha[:8] or 'نامشخص'}\n"
            f"{source_line}"
            f"وضعیت GitHub: {run.get('status')}\n"
            f"نتیجه: {run.get('conclusion') or 'در انتظار'}\n"
            f"شماره Build: #{run.get('run_number')}\n"
            f"{run.get('html_url', '')}\n\n"
            f"{action_line}",
            reply_markup=keyboard(),
        )
    except Exception as exc:
        await update.effective_message.reply_text(
            "📊 وضعیت ربات\n\n"
            f"{local_line}\n"
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


async def _deploy_lock_watchdog_loop(application: Application) -> None:
    while True:
        await asyncio.sleep(60)
        for job_key in list(ACTIVE_CHAT_JOBS):
            _reconcile_finished_job(job_key)
            if job_key not in ACTIVE_CHAT_JOBS:
                continue
            age = _job_age_seconds(job_key)
            if age < JOB_STALE_TIMEOUT_SECONDS:
                continue
            released, _ = await _cancel_active_job(
                job_key,
                reason="آزادسازی خودکار قفل قدیمی توسط Watchdog",
                force=True,
            )
            if released:
                try:
                    await application.bot.send_message(
                        chat_id=job_key,
                        text=(
                            "🧹 عملیات قدیمی بیش از حد مجاز طول کشید و قفل ربات "
                            "به‌صورت خودکار آزاد شد. اکنون می‌توانی ZIP را دوباره بفرستی."
                        ),
                        reply_markup=keyboard(),
                    )
                except Exception:
                    logger.exception("Could not notify chat after stale lock cleanup")


async def bot_post_init(application: Application) -> None:
    # Recover activations created while the bot was restarting and also
    # repair recent activated orders that missed the old plan-only trigger.
    await _retry_guardcore_notifications_once()
    application.create_task(
        _guardcore_notification_loop(application),
        name='guardcore-notification-retry',
    )
    application.create_task(
        _deploy_lock_watchdog_loop(application),
        name='deploy-lock-watchdog',
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
    elif text in {"🚀 ساخت فوری", "🛠 ساخت دوباره"}:
        await rebuild(update, context)
    elif text == "📊 وضعیت":
        await status(update, context)
    elif text == "🔓 آزادسازی عملیات":
        await unlock(update, context)
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
    app.add_handler(CommandHandler("unlock", unlock))
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
