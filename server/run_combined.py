from __future__ import annotations

import asyncio
import hashlib
import importlib
import json
import logging
import os
import re
import random
import signal
import sys
import traceback
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import httpx
import uvicorn

APP_DIR = Path("/app")
BOT_DIR = Path("/opt/bluevpn_bot")
ERROR_LOG = Path("/tmp/bluevpn-startup-error.log")

for directory in (APP_DIR, BOT_DIR):
    value = str(directory)
    if value not in sys.path:
        sys.path.insert(0, value)

try:
    from server.version import VERSION
except Exception:
    VERSION = os.getenv("BLUEVPN_VERSION", "3.0.9")

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s | %(levelname)s | %(name)s | %(message)s",
)
logger = logging.getLogger("bluevpn.bootstrap")


def utc_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


def redact(value: str) -> str:
    result = value

    sensitive_names = (
        "DATABASE_URL",
        "DATABASE_PRIVATE_URL",
        "POSTGRES_URL",
        "POSTGRES_PRIVATE_URL",
        "BOT_TOKEN",
        "GITHUB_TOKEN",
        "SESSION_SECRET",
        "DATA_ENCRYPTION_KEY",
        "PGPASSWORD",
        "POSTGRES_PASSWORD",
    )
    for name in sensitive_names:
        secret = os.getenv(name, "")
        if secret:
            result = result.replace(secret, "***")

    result = re.sub(
        r"postgres(?:ql)?(?:\+psycopg)?://[^\s'\"]+",
        "postgresql://***",
        result,
        flags=re.IGNORECASE,
    )
    result = re.sub(
        r"https://api\.telegram\.org/bot[^/\s]+",
        "https://api.telegram.org/bot***",
        result,
        flags=re.IGNORECASE,
    )
    return result


class RuntimeState:
    def __init__(self) -> None:
        self.started_at = utc_iso()
        self.stop = asyncio.Event()

        self.real_app: Any | None = None
        self.app_ready = False
        self.app_status = "starting"
        self.app_attempt = 0
        self.app_error = ""
        self.app_error_at = ""

        self.bot_status = "waiting"
        self.bot_error = ""
        self.bot_error_at = ""
        self.bot_attempt = 0
        self.bot_consecutive_failures = 0
        self.bot_next_retry_seconds = 0

        self.loader_task: asyncio.Task | None = None
        self.bot_task: asyncio.Task | None = None
        self.real_app_started = False

        self.last_alert_hash = ""
        self.last_alert_at = 0.0


STATE = RuntimeState()


def json_bytes(payload: dict[str, Any]) -> bytes:
    return json.dumps(
        payload,
        ensure_ascii=False,
        separators=(",", ":"),
    ).encode("utf-8")


async def send_response(
    send: Any,
    status: int,
    payload: dict[str, Any],
    *,
    content_type: str = "application/json; charset=utf-8",
) -> None:
    body = json_bytes(payload)
    await send(
        {
            "type": "http.response.start",
            "status": status,
            "headers": [
                (b"content-type", content_type.encode("latin-1")),
                (b"content-length", str(len(body)).encode("ascii")),
                (b"cache-control", b"no-store"),
            ],
        }
    )
    await send(
        {
            "type": "http.response.body",
            "body": body,
        }
    )


def database_environment_diagnostics() -> dict[str, Any]:
    relevant = []
    url_candidates = []
    unresolved = []

    keywords = (
        "DATABASE",
        "POSTGRES",
        "POSTGRESQL",
        "PGHOST",
        "PGPORT",
        "PGUSER",
        "PGPASSWORD",
        "PGDATABASE",
        "DB_",
    )

    for name, raw_value in sorted(os.environ.items()):
        upper = name.upper()
        value = str(raw_value or "").strip()

        if any(keyword in upper for keyword in keywords):
            relevant.append(name)

        if (
            any(keyword in upper for keyword in keywords)
            and ("${{" in value or "}}" in value)
        ):
            unresolved.append(name)

        if value.lower().startswith(
            (
                "postgres://",
                "postgresql://",
                "postgresql+psycopg://",
            )
        ):
            url_candidates.append(name)

    required_pg = {
        "PGHOST",
        "PGUSER",
        "PGDATABASE",
    }
    available = {name for name in os.environ if os.getenv(name, "").strip()}

    return {
        "relevant_names": sorted(set(relevant)),
        "url_candidate_names": sorted(set(url_candidates)),
        "unresolved_reference_names": sorted(set(unresolved)),
        "postgres_components_ready": required_pg.issubset(available),
        "postgres_component_names": sorted(
            name
            for name in (
                "PGHOST",
                "PGPORT",
                "PGUSER",
                "PGPASSWORD",
                "PGDATABASE",
            )
            if name in available
        ),
    }


def database_runtime_status() -> dict[str, Any]:
    module = sys.modules.get("server.database")
    if module is None:
        return {
            "ready": False,
            "mode": "not-loaded",
            "persistent": False,
            "source": "",
            "error": "",
        }

    return {
        "ready": bool(getattr(module, "DATABASE_READY", False)),
        "mode": str(getattr(module, "DATABASE_MODE", "unknown")),
        "persistent": bool(
            getattr(module, "DATABASE_PERSISTENT", False)
        ),
        "source": str(
            getattr(module, "DATABASE_URL_SOURCE", "")
        ),
        "error": redact(
            str(getattr(module, "DATABASE_ERROR", ""))
        )[-1200:],
    }


def is_transient_telegram_error(exc: BaseException) -> bool:
    current: BaseException | None = exc
    transient_names = {
        "TimedOut",
        "NetworkError",
        "ConnectTimeout",
        "ReadTimeout",
        "WriteTimeout",
        "PoolTimeout",
        "ConnectError",
        "ReadError",
        "RemoteProtocolError",
    }

    while current is not None:
        if current.__class__.__name__ in transient_names:
            return True
        current = current.__cause__ or current.__context__
    return False


def telegram_retry_delay(failures: int) -> int:
    base = max(5, int(os.getenv("TELEGRAM_START_RETRY_SECONDS", "10")))
    maximum = max(
        base,
        int(os.getenv("TELEGRAM_START_RETRY_MAX_SECONDS", "120")),
    )
    exponential = min(maximum, base * (2 ** max(0, failures - 1)))
    return min(maximum, exponential + random.randint(0, min(4, base)))


def status_payload() -> dict[str, Any]:
    return {
        "status": (
            "ready"
            if STATE.app_ready
            else "degraded"
            if STATE.app_status == "error"
            else "starting"
        ),
        "service": "bluevpn-bootstrap",
        "version": VERSION,
        "alive": True,
        "started_at": STATE.started_at,
        "application": {
            "ready": STATE.app_ready,
            "status": STATE.app_status,
            "attempt": STATE.app_attempt,
            "error": STATE.app_error[-3500:],
            "error_at": STATE.app_error_at,
        },
        "telegram": {
            "status": STATE.bot_status,
            "attempt": STATE.bot_attempt,
            "consecutive_failures": STATE.bot_consecutive_failures,
            "next_retry_seconds": STATE.bot_next_retry_seconds,
            "error": STATE.bot_error[-1500:],
            "error_at": STATE.bot_error_at,
            "runtime": "server.deploy_bot_runtime",
            "version": "2.8-resilient-telegram-startup",
            "build_trigger": "git-empty-commit-push",
        },
        "database": database_runtime_status(),
        "database_environment": database_environment_diagnostics(),
    }


def clear_partial_backend_imports() -> None:
    names = (
        "server.main",
        "server.database",
        "server.models",
        "server.integrations",
        "server.security",
    )
    for name in names:
        sys.modules.pop(name, None)


async def send_startup_alert(title: str, error: str) -> None:
    token = os.getenv("BOT_TOKEN", "").strip()
    admin_ids = os.getenv("ADMIN_IDS", "").strip()
    chat_id = next(
        (
            item.strip()
            for item in admin_ids.split(",")
            if item.strip()
        ),
        "",
    )
    if not token or not chat_id:
        return

    safe_error = redact(error)
    digest = hashlib.sha256(
        (title + "\n" + safe_error).encode("utf-8")
    ).hexdigest()

    now = asyncio.get_running_loop().time()
    if (
        digest == STATE.last_alert_hash
        and now - STATE.last_alert_at < 1800
    ):
        return

    STATE.last_alert_hash = digest
    STATE.last_alert_at = now

    run_url = ""
    project = os.getenv("RAILWAY_PROJECT_ID", "")
    deployment = os.getenv("RAILWAY_DEPLOYMENT_ID", "")
    if project or deployment:
        run_url = (
            f"\nRailway project: {project or '—'}"
            f"\nDeployment: {deployment or '—'}"
        )

    diagnostics_text = ""
    if "PostgreSQL" in title or "Database" in title or "Backend" in title:
        diagnostics = database_environment_diagnostics()
        component_state = (
            "کامل"
            if diagnostics["postgres_components_ready"]
            else "ناقص"
        )
        diagnostics_text = (
            "\n\nمتغیرهای دیتابیس دیده‌شده:\n"
            f"{', '.join(diagnostics['relevant_names']) or 'هیچ‌کدام'}\n"
            "متغیرهای دارای URL واقعی PostgreSQL:\n"
            f"{', '.join(diagnostics['url_candidate_names']) or 'هیچ‌کدام'}\n"
            f"وضعیت اجزای PGHOST/PGUSER/PGDATABASE: {component_state}"
        )

    summary = (
        f"❌ خطای راه‌اندازی BlueVPN\n\n"
        f"بخش: {title}\n"
        f"نسخه Backend: {VERSION}\n"
        f"زمان: {utc_iso()}"
        f"{run_url}\n\n"
        f"{safe_error[-2500:]}"
        f"{diagnostics_text}\n\n"
        "وضعیت زنده:\n"
        "/startup-status"
    )

    try:
        async with httpx.AsyncClient(timeout=25.0) as client:
            response = await client.post(
                f"https://api.telegram.org/bot{token}/sendMessage",
                data={
                    "chat_id": chat_id,
                    "text": summary[:4000],
                    "disable_web_page_preview": "true",
                },
            )
            response.raise_for_status()

            if ERROR_LOG.exists():
                with ERROR_LOG.open("rb") as handle:
                    await client.post(
                        f"https://api.telegram.org/bot{token}/sendDocument",
                        data={
                            "chat_id": chat_id,
                            "caption": (
                                "📎 لاگ کامل و پاک‌سازی‌شده راه‌اندازی BlueVPN"
                            ),
                        },
                        files={
                            "document": (
                                "bluevpn-startup-error.log",
                                handle,
                                "text/plain",
                            )
                        },
                    )
    except Exception:
        logger.exception("Could not send startup error to Telegram")


def save_error(section: str, exc: BaseException) -> str:
    raw = "".join(
        traceback.format_exception(
            type(exc),
            exc,
            exc.__traceback__,
        )
    )
    safe = redact(raw)
    ERROR_LOG.write_text(
        (
            f"BlueVPN startup failure\n"
            f"section={section}\n"
            f"version={VERSION}\n"
            f"time={utc_iso()}\n\n"
            f"{safe}"
        ),
        encoding="utf-8",
    )
    return safe


async def load_application_forever() -> None:
    retry_seconds = max(
        5,
        int(os.getenv("APP_START_RETRY_SECONDS", "15")),
    )

    # Bootstrap owns retries, so each individual database attempt should fail
    # quickly and report the real cause instead of blocking the HTTP port.
    os.environ.setdefault("DB_CONNECT_RETRIES", "3")
    os.environ.setdefault("DB_CONNECT_RETRY_SECONDS", "2")

    while not STATE.stop.is_set() and not STATE.app_ready:
        STATE.app_attempt += 1
        STATE.app_status = "loading"
        STATE.app_error = ""

        try:
            clear_partial_backend_imports()

            module = await asyncio.to_thread(
                importlib.import_module,
                "server.main",
            )
            app = module.app

            # The real FastAPI app is not Uvicorn's root application, so its
            # startup hooks must be executed explicitly before it receives
            # requests.
            await app.router.startup()

            STATE.real_app = app
            STATE.real_app_started = True
            STATE.app_ready = True
            STATE.app_status = "ready"
            STATE.app_error = ""
            STATE.app_error_at = ""

            logger.info(
                "BlueVPN application and persistent database are ready"
            )
            return

        except asyncio.CancelledError:
            raise
        except Exception as exc:
            safe = save_error("backend/database", exc)
            STATE.app_status = "error"
            STATE.app_error = safe
            STATE.app_error_at = utc_iso()

            logger.exception(
                "BlueVPN backend/database startup failed; retrying in %s seconds",
                retry_seconds,
            )
            await send_startup_alert(
                "Backend / PostgreSQL / Migration",
                safe,
            )

            try:
                await asyncio.wait_for(
                    STATE.stop.wait(),
                    timeout=retry_seconds,
                )
            except asyncio.TimeoutError:
                pass


async def safe_bot_call(obj: Any, method_name: str) -> None:
    method = getattr(obj, method_name, None)
    if method is None:
        return
    try:
        result = method()
        if asyncio.iscoroutine(result):
            await result
    except Exception:
        logger.exception("Telegram cleanup failed: %s", method_name)


async def run_bot_forever() -> None:
    alert_after = max(
        1,
        int(os.getenv("TELEGRAM_ALERT_AFTER_FAILURES", "3")),
    )
    poll_timeout = max(
        10,
        int(os.getenv("TELEGRAM_POLL_TIMEOUT", "20")),
    )
    bootstrap_retries = max(
        0,
        int(os.getenv("TELEGRAM_BOOTSTRAP_RETRIES", "5")),
    )

    while not STATE.stop.is_set():
        telegram = None
        started = False
        polling = False
        STATE.bot_attempt += 1

        try:
            # Import happens only after Uvicorn is listening. Missing bot or
            # GitHub variables can no longer break Railway healthchecks.
            module = await asyncio.to_thread(
                importlib.import_module,
                "server.deploy_bot_runtime",
            )
            telegram = module.build_application()

            STATE.bot_status = "starting"
            STATE.bot_error = ""
            STATE.bot_next_retry_seconds = 0

            await telegram.initialize()
            await telegram.start()
            started = True

            # ApplicationBuilder.post_init() is not called automatically
            # because BlueVPN uses a custom lifecycle instead of run_polling().
            # Run the project callback explicitly after Application.start() so
            # background tasks created there are tracked during shutdown.
            post_init = getattr(module, "bot_post_init", None)
            if post_init is not None:
                await post_init(telegram)

            if telegram.updater is None:
                raise RuntimeError("Telegram updater is unavailable")

            await telegram.updater.start_polling(
                timeout=poll_timeout,
                bootstrap_retries=bootstrap_retries,
                drop_pending_updates=False,
            )
            polling = True

            STATE.bot_status = "running"
            STATE.bot_error = ""
            STATE.bot_error_at = ""
            STATE.bot_consecutive_failures = 0
            STATE.bot_next_retry_seconds = 0
            logger.info("BlueVPN Telegram deployment bot is running")

            await STATE.stop.wait()

        except asyncio.CancelledError:
            raise
        except Exception as exc:
            transient = is_transient_telegram_error(exc)
            STATE.bot_consecutive_failures += 1
            delay = telegram_retry_delay(
                STATE.bot_consecutive_failures
            )
            STATE.bot_next_retry_seconds = delay
            safe = save_error("telegram-bot", exc)
            STATE.bot_status = (
                "retrying" if transient else "error"
            )
            STATE.bot_error = safe
            STATE.bot_error_at = utc_iso()

            logger.exception(
                "Telegram bot startup failed (transient=%s, attempt=%s); "
                "web/API remain online and retry in %s seconds",
                transient,
                STATE.bot_attempt,
                delay,
            )

            # A single Telegram route stall is not a deployment failure.
            # Alert only after repeated transient failures, but report
            # configuration/programming errors immediately.
            if (
                not transient
                or STATE.bot_consecutive_failures >= alert_after
            ):
                await send_startup_alert(
                    "Telegram deployment bot",
                    safe,
                )

            # A partially imported module with failed require_env() must not be
            # reused on the next retry.
            sys.modules.pop("server.deploy_bot_runtime", None)

            try:
                await asyncio.wait_for(
                    STATE.stop.wait(),
                    timeout=delay,
                )
            except asyncio.TimeoutError:
                pass
        finally:
            if telegram is not None:
                if polling and telegram.updater is not None:
                    await safe_bot_call(telegram.updater, "stop")
                if started:
                    await safe_bot_call(telegram, "stop")
                # initialize() can fail after opening HTTPX clients. Always
                # attempt shutdown so failed get_me() calls do not leak pools.
                await safe_bot_call(telegram, "shutdown")


class BootstrapApplication:
    async def __call__(
        self,
        scope: dict[str, Any],
        receive: Any,
        send: Any,
    ) -> None:
        if scope["type"] == "lifespan":
            await self._lifespan(receive, send)
            return

        if scope["type"] != "http":
            if STATE.real_app is not None:
                await STATE.real_app(scope, receive, send)
            return

        path = scope.get("path", "/")

        if path == "/live":
            await send_response(
                send,
                200,
                {
                    "status": "alive",
                    "alive": True,
                    "service": "bluevpn-bootstrap",
                    "version": VERSION,
                    "app_ready": STATE.app_ready,
                },
            )
            return

        if path == "/startup-status":
            await send_response(send, 200, status_payload())
            return

        if STATE.real_app is not None and STATE.app_ready:
            await STATE.real_app(scope, receive, send)
            return

        await send_response(
            send,
            503,
            {
                "status": "starting",
                "message": (
                    "BlueVPN در حال اتصال به PostgreSQL و ساخت یا ارتقای "
                    "خودکار جداول است."
                ),
                "version": VERSION,
                "details": "/startup-status",
                "last_error": STATE.app_error[-1200:],
            },
        )

    async def _lifespan(self, receive: Any, send: Any) -> None:
        while True:
            message = await receive()

            if message["type"] == "lifespan.startup":
                STATE.loader_task = asyncio.create_task(
                    load_application_forever(),
                    name="bluevpn-app-loader",
                )
                STATE.bot_task = asyncio.create_task(
                    run_bot_forever(),
                    name="bluevpn-bot-loader",
                )
                await send(
                    {"type": "lifespan.startup.complete"}
                )

            elif message["type"] == "lifespan.shutdown":
                STATE.stop.set()

                tasks = [
                    task
                    for task in (
                        STATE.loader_task,
                        STATE.bot_task,
                    )
                    if task is not None
                ]
                for task in tasks:
                    if not task.done():
                        task.cancel()

                if tasks:
                    await asyncio.gather(
                        *tasks,
                        return_exceptions=True,
                    )

                if (
                    STATE.real_app is not None
                    and STATE.real_app_started
                ):
                    try:
                        await STATE.real_app.router.shutdown()
                    except Exception:
                        logger.exception(
                            "Real FastAPI shutdown failed"
                        )

                await send(
                    {"type": "lifespan.shutdown.complete"}
                )
                return


async def main() -> None:
    port = int(os.getenv("PORT", "8000"))
    app = BootstrapApplication()

    config = uvicorn.Config(
        app,
        host="0.0.0.0",
        port=port,
        loop="asyncio",
        log_level="info",
        access_log=True,
        lifespan="on",
    )
    server = uvicorn.Server(config)
    server.install_signal_handlers = lambda: None

    loop = asyncio.get_running_loop()

    def shutdown() -> None:
        server.should_exit = True

    for sig in (signal.SIGINT, signal.SIGTERM):
        try:
            loop.add_signal_handler(sig, shutdown)
        except NotImplementedError:
            pass

    logger.info(
        "Starting BlueVPN bootstrap listener on PORT=%s",
        port,
    )
    await server.serve()


if __name__ == "__main__":
    asyncio.run(main())
