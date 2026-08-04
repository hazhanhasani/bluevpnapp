from __future__ import annotations

import asyncio
import logging
import os
import signal
import sys
from pathlib import Path
from typing import Any

import uvicorn

APP_DIR = Path("/app")
BOT_DIR = Path("/opt/bluevpn_bot")

for directory in (APP_DIR, BOT_DIR):
    value = str(directory)
    if value not in sys.path:
        sys.path.insert(0, value)

from deploy_bot import build_application

# Database/import failures remain fatal. This prevents Railway from activating
# an empty SQLite fallback deployment.
from server.main import app as web_app

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("bluevpn.runner")


async def safe_call(obj: Any, method_name: str) -> None:
    method = getattr(obj, method_name, None)
    if method is None:
        return
    try:
        result = method()
        if asyncio.iscoroutine(result):
            await result
    except Exception:
        logger.exception(
            "Telegram cleanup step failed: %s",
            method_name,
        )


async def run_telegram_with_retry(stop: asyncio.Event) -> None:
    """
    Telegram polling must never block Railway's HTTP healthcheck.

    During a zero-downtime deployment, the old BlueVPN replica may still be
    polling the same bot token. The new replica therefore retries until the
    old deployment is drained, while the web/API service remains healthy.
    """
    retry_seconds = max(
        5,
        int(os.getenv("TELEGRAM_START_RETRY_SECONDS", "15")),
    )

    while not stop.is_set():
        telegram = None
        updater_started = False
        application_started = False
        application_initialized = False

        try:
            telegram = build_application()

            await telegram.initialize()
            application_initialized = True

            await telegram.start()
            application_started = True

            if telegram.updater is None:
                raise RuntimeError(
                    "Telegram updater is unavailable"
                )

            await telegram.updater.start_polling(
                drop_pending_updates=False,
            )
            updater_started = True

            logger.info(
                "Telegram deployment bot polling started"
            )

            await stop.wait()

        except asyncio.CancelledError:
            raise
        except Exception:
            logger.exception(
                "Telegram bot startup/polling failed; "
                "the web service stays online and polling will retry "
                "in %s seconds",
                retry_seconds,
            )
            try:
                await asyncio.wait_for(
                    stop.wait(),
                    timeout=retry_seconds,
                )
            except asyncio.TimeoutError:
                pass
        finally:
            if telegram is not None:
                if updater_started and telegram.updater is not None:
                    await safe_call(
                        telegram.updater,
                        "stop",
                    )
                if application_started:
                    await safe_call(
                        telegram,
                        "stop",
                    )
                if application_initialized:
                    await safe_call(
                        telegram,
                        "shutdown",
                    )


async def wait_for_web_start(
    web: uvicorn.Server,
    web_task: asyncio.Task,
) -> None:
    timeout_seconds = max(
        10,
        int(os.getenv("WEB_START_TIMEOUT_SECONDS", "60")),
    )
    loop = asyncio.get_running_loop()
    deadline = loop.time() + timeout_seconds

    while not web.started:
        if web_task.done():
            # Propagate the real Uvicorn/FastAPI/database exception.
            await web_task
            raise RuntimeError(
                "BlueVPN web server stopped before startup"
            )

        if loop.time() >= deadline:
            web.should_exit = True
            raise RuntimeError(
                "BlueVPN web server did not start within "
                f"{timeout_seconds} seconds"
            )

        await asyncio.sleep(0.1)


async def main() -> None:
    port = int(os.getenv("PORT", "8000"))

    config = uvicorn.Config(
        web_app,
        host="0.0.0.0",
        port=port,
        loop="asyncio",
        log_level="info",
        access_log=True,
    )
    web = uvicorn.Server(config)
    web.install_signal_handlers = lambda: None

    stop = asyncio.Event()
    loop = asyncio.get_running_loop()

    def shutdown() -> None:
        stop.set()
        web.should_exit = True

    for sig in (signal.SIGINT, signal.SIGTERM):
        try:
            loop.add_signal_handler(sig, shutdown)
        except NotImplementedError:
            pass

    # Critical ordering: start HTTP/FastAPI first so Railway can successfully
    # call /health. Telegram polling starts only after the web server is ready.
    web_task = asyncio.create_task(
        web.serve(),
        name="bluevpn-web",
    )

    await wait_for_web_start(web, web_task)
    logger.info(
        "BlueVPN web service is listening on PORT=%s",
        port,
    )

    telegram_task = asyncio.create_task(
        run_telegram_with_retry(stop),
        name="bluevpn-telegram",
    )
    stop_task = asyncio.create_task(
        stop.wait(),
        name="bluevpn-stop",
    )

    done, _ = await asyncio.wait(
        {web_task, stop_task},
        return_when=asyncio.FIRST_COMPLETED,
    )

    if web_task in done:
        # Surface an unexpected web server failure.
        await web_task

    stop.set()
    web.should_exit = True

    for task in (telegram_task, stop_task):
        if not task.done():
            task.cancel()

    await asyncio.gather(
        telegram_task,
        stop_task,
        return_exceptions=True,
    )

    if not web_task.done():
        await web_task


if __name__ == "__main__":
    asyncio.run(main())
